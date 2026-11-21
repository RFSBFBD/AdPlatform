<?php

namespace App\Services\Advertising\Adapters;

use App\Exceptions\OAuthTokenExpiredException;
use App\Exceptions\ProviderRateLimitException;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GoogleAdsAdapter implements AdvertisingProviderInterface
{
    protected ?ProviderDeveloperConfig $config;
    protected string $apiVersion = 'v18';

    public function __construct(?ProviderDeveloperConfig $config = null)
    {
        $this->config = $config ?? ProviderDeveloperConfig::where('provider', 'google')->first();
        if ($this->config && !empty($this->config->api_version)) {
            $this->apiVersion = $this->config->api_version;
        }
    }

    public function getProviderName(): string
    {
        return 'google';
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->ensureConfigured();

        $params = http_build_query([
            'client_id' => $this->config->app_id,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
    }

    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($code, $redirectUri) {
            return Http::timeout(30)->connectTimeout(10)
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $this->config->app_id,
                    'client_secret' => $this->config->app_secret,
                    'redirect_uri' => $redirectUri,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                ]);
        }, 'oauth2.googleapis.com/token');

        if ($response->failed()) {
            throw new RuntimeException("Google OAuth exchange failed: " . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ];
    }

    public function discoverAccounts(string $accessToken): array
    {
        $this->ensureConfigured();

        $headers = [
            'developer-token' => $this->config->developer_token,
        ];

        $response = $this->executeHttp(function () use ($accessToken, $headers) {
            return Http::timeout(30)->connectTimeout(10)
                ->withToken($accessToken)
                ->withHeaders($headers)
                ->get("https://googleads.googleapis.com/{$this->apiVersion}/customers:listAccessibleCustomers");
        }, "customers:listAccessibleCustomers");

        if ($response->failed()) {
            throw new RuntimeException("Failed to discover Google Ads customers: " . $response->body());
        }

        $customerResourceNames = $response->json('resourceNames', []);
        $accounts = [];

        foreach ($customerResourceNames as $resourceName) {
            $customerId = str_replace('customers/', '', $resourceName);
            $cleanId = str_replace('-', '', $customerId);

            $currency = 'USD';
            $timezone = 'UTC';
            $accountName = "Google Ads Customer ({$customerId})";
            $status = 'ACCESSIBLE';

            // Discover native customer currency and timezone via search query (Phase 3)
            try {
                $detailResponse = $this->executeHttp(function () use ($accessToken, $headers, $cleanId) {
                    return Http::timeout(30)->connectTimeout(10)
                        ->withToken($accessToken)
                        ->withHeaders($headers)
                        ->post("https://googleads.googleapis.com/{$this->apiVersion}/customers/{$cleanId}/googleAds:search", [
                            'query' => "SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.status FROM customer LIMIT 1",
                        ]);
                }, "customers/{$cleanId}/googleAds:search");

                if ($detailResponse->successful()) {
                    $results = $detailResponse->json('results', []);
                    if (!empty($results[0]['customer'])) {
                        $cust = $results[0]['customer'];
                        $currency = $cust['currencyCode'] ?? $currency;
                        $timezone = $cust['timeZone'] ?? $timezone;
                        $accountName = $cust['descriptiveName'] ?? $accountName;
                        $status = $cust['status'] ?? $status;
                    }
                }
            } catch (Throwable $e) {
                // Non-fatal if detail lookup for individual customer fails; fall back safely
                Log::debug("Could not fetch detailed metadata for Google Ads customer {$customerId}: " . $e->getMessage());
            }

            $accounts[] = [
                'provider' => 'google',
                'external_account_id' => $customerId,
                'account_name' => $accountName,
                'currency' => $currency,
                'timezone' => $timezone,
                'raw_status' => $status,
            ];
        }

        return $accounts;
    }

    public function refreshToken(string $refreshToken): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($refreshToken) {
            return Http::timeout(30)->connectTimeout(10)
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $this->config->app_id,
                    'client_secret' => $this->config->app_secret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);
        }, 'oauth2.googleapis.com/token:refresh');

        if ($response->failed()) {
            throw new OAuthTokenExpiredException("Failed to refresh Google access token: " . $response->body(), 'google');
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $refreshToken,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ];
    }

    public function fetchCampaigns(ConnectedAdAccount $account): array
    {
        $this->ensureConfigured();
        $customerId = str_replace('-', '', $account->external_account_id);
        $headers = [
            'developer-token' => $this->config->developer_token ?? '',
            'Authorization' => "Bearer {$account->access_token}",
        ];

        // 1. Fetch Ad Groups & Ads hierarchy with complete pagination
        $adHierarchy = [];
        $agPageToken = null;
        do {
            try {
                $payload = [
                    'query' => "SELECT campaign.id, ad_group.id, ad_group.name, ad_group.status, ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.status FROM ad_group_ad",
                    'pageSize' => 1000,
                ];
                if ($agPageToken) {
                    $payload['pageToken'] = $agPageToken;
                }

                $adResponse = $this->executeWithTokenHandling($account, function () use ($headers, $customerId, $payload) {
                    return Http::timeout(30)->connectTimeout(10)
                        ->withHeaders($headers)
                        ->post("https://googleads.googleapis.com/{$this->apiVersion}/customers/{$customerId}/googleAds:search", $payload);
                }, "customers/{$customerId}/googleAds:search:ad_group_ad");

                if ($adResponse->successful()) {
                    $adData = $adResponse->json();
                    $rows = $adData['results'] ?? [];

                    foreach ($rows as $row) {
                        $campId = (string) ($row['campaign']['id'] ?? '');
                        $agId = (string) ($row['adGroup']['id'] ?? '');
                        $adId = (string) ($row['adGroupAd']['ad']['id'] ?? '');

                        if ($campId && $agId) {
                            if (!isset($adHierarchy[$campId][$agId])) {
                                $adHierarchy[$campId][$agId] = [
                                    'external_ad_set_id' => $agId,
                                    'name' => $row['adGroup']['name'] ?? "Ad Group {$agId}",
                                    'status' => strtoupper($row['adGroup']['status'] ?? 'ENABLED'),
                                    'ads' => [],
                                ];
                            }

                            if ($adId) {
                                $adHierarchy[$campId][$agId]['ads'][] = [
                                    'external_ad_id' => $adId,
                                    'name' => $row['adGroupAd']['ad']['name'] ?? "Ad {$adId}",
                                    'status' => strtoupper($row['adGroupAd']['status'] ?? 'ENABLED'),
                                ];
                            }
                        }
                    }

                    $agPageToken = !empty($adData['nextPageToken']) ? $adData['nextPageToken'] : null;
                } else {
                    $agPageToken = null;
                }
            } catch (Throwable $e) {
                // Secondary hierarchy query error shouldn't halt campaign sync
                $agPageToken = null;
            }
        } while ($agPageToken !== null);

        // 2. Fetch Campaigns with complete pagination
        $campaigns = [];
        $rawResponses = [];
        $nextPageToken = null;

        do {
            $payload = [
                'query' => "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type FROM campaign ORDER BY campaign.id",
                'pageSize' => 500,
            ];
            if ($nextPageToken) {
                $payload['pageToken'] = $nextPageToken;
            }

            $response = $this->executeWithTokenHandling($account, function () use ($headers, $customerId, $payload) {
                return Http::timeout(30)->connectTimeout(10)
                    ->withHeaders($headers)
                    ->post("https://googleads.googleapis.com/{$this->apiVersion}/customers/{$customerId}/googleAds:search", $payload);
            }, "customers/{$customerId}/googleAds:search:campaign");

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch Google campaigns: " . $response->body());
            }

            $data = $response->json();
            $rawResponses[] = $data;

            // Handle either search response format or searchStream batch format
            $results = $data['results'] ?? (is_array($data) && isset($data[0]['results']) ? $data[0]['results'] : []);

            foreach ($results as $row) {
                $c = $row['campaign'] ?? [];
                $campId = (string) ($c['id'] ?? '');

                $adSets = [];
                if (isset($adHierarchy[$campId])) {
                    $adSets = array_values($adHierarchy[$campId]);
                }

                $campaigns[] = [
                    'external_campaign_id' => $campId,
                    'name' => $c['name'] ?? 'Unnamed Google Campaign',
                    'status' => strtoupper($c['status'] ?? 'ENABLED'),
                    'objective' => $c['advertisingChannelType'] ?? null,
                    'daily_budget' => null,
                    'lifetime_budget' => null,
                    'currency' => $account->currency ?? 'USD',
                    'start_date' => null,
                    'end_date' => null,
                    'metadata' => $c,
                    'ad_sets' => $adSets,
                ];
            }

            $nextPageToken = !empty($data['nextPageToken']) ? $data['nextPageToken'] : null;
        } while ($nextPageToken !== null);

        return [
            'endpoint' => "/customers/{$customerId}/googleAds:search",
            'raw' => $rawResponses,
            'campaigns' => $campaigns,
        ];
    }

    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $this->ensureConfigured();
        $customerId = str_replace('-', '', $account->external_account_id);
        $headers = [
            'developer-token' => $this->config->developer_token ?? '',
            'Authorization' => "Bearer {$account->access_token}",
        ];

        $metrics = [];
        $rawResponses = [];
        $nextPageToken = null;

        do {
            $payload = [
                'query' => "SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '{$startDate}' AND '{$endDate}'",
                'pageSize' => 1000,
            ];
            if ($nextPageToken) {
                $payload['pageToken'] = $nextPageToken;
            }

            $response = $this->executeWithTokenHandling($account, function () use ($headers, $customerId, $payload) {
                return Http::timeout(30)->connectTimeout(10)
                    ->withHeaders($headers)
                    ->post("https://googleads.googleapis.com/{$this->apiVersion}/customers/{$customerId}/googleAds:search", $payload);
            }, "customers/{$customerId}/googleAds:search:insights");

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch Google insights: " . $response->body());
            }

            $data = $response->json();
            $rawResponses[] = $data;

            $results = $data['results'] ?? (is_array($data) && isset($data[0]['results']) ? $data[0]['results'] : []);

            foreach ($results as $row) {
                $c = $row['campaign'] ?? [];
                $m = $row['metrics'] ?? [];
                $s = $row['segments'] ?? [];

                $spend = isset($m['costMicros']) ? ((float) $m['costMicros']) / 1000000.0 : 0.0;

                $metrics[] = [
                    'external_campaign_id' => (string) ($c['id'] ?? ''),
                    'external_ad_set_id' => null,
                    'external_ad_id' => null,
                    'date' => $s['date'] ?? $startDate,
                    'spend' => $spend,
                    'impressions' => (int) ($m['impressions'] ?? 0),
                    'clicks' => (int) ($m['clicks'] ?? 0),
                    'conversions' => (float) ($m['conversions'] ?? 0),
                    'conversion_value' => (float) ($m['conversionsValue'] ?? 0),
                ];
            }

            $nextPageToken = !empty($data['nextPageToken']) ? $data['nextPageToken'] : null;
        } while ($nextPageToken !== null);

        return [
            'endpoint' => "/customers/{$customerId}/googleAds:search/insights",
            'raw' => $rawResponses,
            'metrics' => $metrics,
        ];
    }

    /**
     * Execute an HTTP call with automatic token refresh if token expired.
     */
    protected function executeWithTokenHandling(ConnectedAdAccount $account, callable $callback, string $endpoint): Response
    {
        $response = $this->executeHttp($callback, $endpoint);

        if ($response->status() === 401 && !empty($account->refresh_token)) {
            Log::info("Google access token expired for account #{$account->id}. Attempting automatic refresh...");
            try {
                $refreshed = $this->refreshToken($account->refresh_token);
                $account->update([
                    'access_token' => $refreshed['access_token'],
                    'expires_at' => $refreshed['expires_at'],
                ]);
                // Retry once with new token
                $response = $this->executeHttp($callback, $endpoint);
            } catch (Throwable $refreshEx) {
                $account->update(['status' => 'token_expired']);
                throw new OAuthTokenExpiredException("Google OAuth token expired and auto-refresh failed: " . $refreshEx->getMessage(), 'google', $account->id);
            }
        } elseif ($response->status() === 401 || $response->status() === 403) {
            $account->update(['status' => 'token_expired']);
            throw new OAuthTokenExpiredException("Google authorization rejected: HTTP {$response->status()}", 'google', $account->id);
        }

        return $response;
    }

    /**
     * Execute HTTP request with exponential backoff for transient 429/5xx errors.
     */
    protected function executeHttp(callable $call, string $endpoint): Response
    {
        $start = microtime(true);
        $attempts = 0;
        $maxAttempts = 3;
        $delayMs = 200;

        while (true) {
            $attempts++;
            try {
                /** @var Response $response */
                $response = $call();

                if ($response->status() === 429) {
                    if ($attempts >= $maxAttempts) {
                        Log::warning("Google Ads rate limit hit (429) after {$attempts} attempts", [
                            'endpoint' => $endpoint,
                            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                        ]);
                        throw new ProviderRateLimitException("Google Ads rate limit reached.", 'google');
                    }
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    continue;
                }

                if ($response->serverError() && $attempts < $maxAttempts) {
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    continue;
                }

                return $response;
            } catch (ConnectionException $e) {
                if ($attempts >= $maxAttempts) {
                    Log::error("Google Ads network connection error", [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }
    }

    protected function ensureConfigured(): void
    {
        if (!$this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException("Google Ads API credentials have not been configured.");
        }
    }
}
