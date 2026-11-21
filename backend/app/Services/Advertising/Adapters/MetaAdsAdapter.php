<?php

namespace App\Services\Advertising\Adapters;

use App\Exceptions\OAuthTokenExpiredException;
use App\Exceptions\ProviderRateLimitException;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetaAdsAdapter implements AdvertisingProviderInterface
{
    protected ?ProviderDeveloperConfig $config;
    protected string $apiVersion = 'v21.0';

    public function __construct(?ProviderDeveloperConfig $config = null)
    {
        $this->config = $config ?? ProviderDeveloperConfig::where('provider', 'meta')->first();
        if ($this->config && !empty($this->config->api_version)) {
            $this->apiVersion = $this->config->api_version;
        }
    }

    public function getProviderName(): string
    {
        return 'meta';
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->ensureConfigured();

        $params = http_build_query([
            'client_id' => $this->config->app_id,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => 'ads_read,ads_management,read_insights',
        ]);

        return "https://www.facebook.com/{$this->apiVersion}/dialog/oauth?{$params}";
    }

    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($code, $redirectUri) {
            return Http::timeout(30)->connectTimeout(10)
                ->get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
                    'client_id' => $this->config->app_id,
                    'client_secret' => $this->config->app_secret,
                    'redirect_uri' => $redirectUri,
                    'code' => $code,
                ]);
        }, 'meta:oauth/access_token');

        if ($response->failed()) {
            throw new RuntimeException("Meta OAuth exchange failed: " . $response->body());
        }

        $data = $response->json();
        $shortLivedToken = $data['access_token'];

        // Exchange for long-lived user access token (valid ~60 days)
        $longLivedResponse = $this->executeHttp(function () use ($shortLivedToken) {
            return Http::timeout(30)->connectTimeout(10)
                ->get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $this->config->app_id,
                    'client_secret' => $this->config->app_secret,
                    'fb_exchange_token' => $shortLivedToken,
                ]);
        }, 'meta:oauth/fb_exchange_token');

        if ($longLivedResponse->successful()) {
            $longLivedData = $longLivedResponse->json();
            return [
                'access_token' => $longLivedData['access_token'],
                'refresh_token' => null,
                'expires_at' => now()->addSeconds($longLivedData['expires_in'] ?? 5184000),
            ];
        }

        return [
            'access_token' => $shortLivedToken,
            'refresh_token' => null,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 7200),
        ];
    }

    public function discoverAccounts(string $accessToken): array
    {
        $accounts = [];
        $nextUrl = "https://graph.facebook.com/{$this->apiVersion}/me/adaccounts";
        $params = [
            'fields' => 'id,account_id,name,currency,timezone_name,account_status',
            'limit' => 100,
        ];

        do {
            $response = $this->executeHttp(function () use ($accessToken, $nextUrl, $params) {
                return Http::timeout(30)->connectTimeout(10)
                    ->withToken($accessToken)
                    ->get($nextUrl, $params);
            }, 'meta:me/adaccounts');

            if ($response->failed()) {
                throw new RuntimeException("Failed to discover Meta ad accounts: " . $response->body());
            }

            $json = $response->json();
            $items = $json['data'] ?? [];

            foreach ($items as $item) {
                $accounts[] = [
                    'provider' => 'meta',
                    'external_account_id' => $item['account_id'] ?? str_replace('act_', '', $item['id']),
                    'account_name' => $item['name'] ?? 'Meta Ad Account ' . ($item['account_id'] ?? $item['id']),
                    'currency' => $item['currency'] ?? 'USD',
                    'timezone' => $item['timezone_name'] ?? 'UTC',
                    'raw_status' => $item['account_status'] ?? 1,
                ];
            }

            $nextUrl = $json['paging']['next'] ?? null;
            $params = []; // subsequent pages already include full query string in nextUrl
        } while (!empty($nextUrl));

        return $accounts;
    }

    public function refreshToken(string $refreshToken): array
    {
        throw new RuntimeException("Meta does not support refresh tokens. Re-authorization is required.");
    }

    public function fetchCampaigns(ConnectedAdAccount $account): array
    {
        $actId = str_starts_with($account->external_account_id, 'act_')
            ? $account->external_account_id
            : 'act_' . $account->external_account_id;

        $campaigns = [];
        $rawResponses = [];
        $nextUrl = "https://graph.facebook.com/{$this->apiVersion}/{$actId}/campaigns";
        $params = [
            'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,start_time,stop_time,adsets{id,name,status,daily_budget,targeting,ads{id,name,status}}',
            'limit' => 100,
        ];
        $pageCount = 0;
        $maxPages = 100;

        do {
            $pageCount++;
            if ($pageCount > $maxPages) {
                Log::warning("Max pagination depth ({$maxPages}) reached for Meta campaigns on {$actId}");
                break;
            }

            $response = $this->executeWithTokenHandling($account, function () use ($account, $nextUrl, $params) {
                return Http::timeout(30)->connectTimeout(10)
                    ->withToken($account->access_token)
                    ->get($nextUrl, $params);
            }, "meta:{$actId}/campaigns");

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch Meta campaigns: " . $response->body());
            }

            $raw = $response->json();
            $rawResponses[] = $raw;

            foreach ($raw['data'] ?? [] as $item) {
                $adSets = [];
                if (!empty($item['adsets']['data'])) {
                    foreach ($item['adsets']['data'] as $as) {
                        $ads = [];
                        if (!empty($as['ads']['data'])) {
                            foreach ($as['ads']['data'] as $ad) {
                                $ads[] = [
                                    'external_ad_id' => (string) $ad['id'],
                                    'name' => $ad['name'] ?? 'Ad ' . $ad['id'],
                                    'status' => strtoupper($ad['status'] ?? 'ACTIVE'),
                                ];
                            }
                        }
                        $adSets[] = [
                            'external_ad_set_id' => (string) $as['id'],
                            'name' => $as['name'] ?? 'Ad Set ' . $as['id'],
                            'status' => strtoupper($as['status'] ?? 'ACTIVE'),
                            'daily_budget' => isset($as['daily_budget']) ? ((float) $as['daily_budget']) / 100 : null,
                            'targeting' => $as['targeting'] ?? null,
                            'ads' => $ads,
                        ];
                    }
                }

                $campaigns[] = [
                    'external_campaign_id' => (string) $item['id'],
                    'name' => $item['name'] ?? 'Unnamed Campaign',
                    'status' => strtoupper($item['status'] ?? 'ACTIVE'),
                    'objective' => $item['objective'] ?? null,
                    'daily_budget' => isset($item['daily_budget']) ? ((float) $item['daily_budget']) / 100 : null,
                    'lifetime_budget' => isset($item['lifetime_budget']) ? ((float) $item['lifetime_budget']) / 100 : null,
                    'currency' => $account->currency ?? 'USD',
                    'start_date' => $item['start_time'] ?? null,
                    'end_date' => $item['stop_time'] ?? null,
                    'metadata' => $item,
                    'ad_sets' => $adSets,
                ];
            }

            $nextUrl = $raw['paging']['next'] ?? null;
            $params = [];
        } while (!empty($nextUrl));

        return [
            'endpoint' => "/{$actId}/campaigns",
            'raw' => $rawResponses,
            'campaigns' => $campaigns,
        ];
    }

    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $actId = str_starts_with($account->external_account_id, 'act_')
            ? $account->external_account_id
            : 'act_' . $account->external_account_id;

        $metrics = [];
        $rawResponses = [];
        $nextUrl = "https://graph.facebook.com/{$this->apiVersion}/{$actId}/insights";
        $params = [
            'level' => 'campaign',
            'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions,action_values',
            'time_range' => json_encode(['since' => $startDate, 'until' => $endDate]),
            'time_increment' => 1,
            'limit' => 200,
        ];
        $pageCount = 0;
        $maxPages = 100;

        do {
            $pageCount++;
            if ($pageCount > $maxPages) {
                Log::warning("Max pagination depth ({$maxPages}) reached for Meta insights on {$actId}");
                break;
            }

            $response = $this->executeWithTokenHandling($account, function () use ($account, $nextUrl, $params) {
                return Http::timeout(30)->connectTimeout(10)
                    ->withToken($account->access_token)
                    ->get($nextUrl, $params);
            }, "meta:{$actId}/insights");

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch Meta insights: " . $response->body());
            }

            $raw = $response->json();
            $rawResponses[] = $raw;

            foreach ($raw['data'] ?? [] as $item) {
                $conversions = 0.0;
                $conversionValue = 0.0;

                if (isset($item['actions']) && is_array($item['actions'])) {
                    foreach ($item['actions'] as $action) {
                        if (in_array($action['action_type'] ?? '', ['purchase', 'omni_purchase', 'lead', 'offsite_conversion.fb_pixel_purchase'])) {
                            $conversions += (float) ($action['value'] ?? 0);
                        }
                    }
                }

                if (isset($item['action_values']) && is_array($item['action_values'])) {
                    foreach ($item['action_values'] as $val) {
                        if (in_array($val['action_type'] ?? '', ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'])) {
                            $conversionValue += (float) ($val['value'] ?? 0);
                        }
                    }
                }

                $metrics[] = [
                    'external_campaign_id' => (string) ($item['campaign_id'] ?? ''),
                    'external_ad_set_id' => null,
                    'external_ad_id' => null,
                    'date' => $item['date_start'],
                    'spend' => (float) ($item['spend'] ?? 0),
                    'impressions' => (int) ($item['impressions'] ?? 0),
                    'clicks' => (int) ($item['clicks'] ?? 0),
                    'conversions' => $conversions,
                    'conversion_value' => $conversionValue,
                ];
            }

            $nextUrl = $raw['paging']['next'] ?? null;
            $params = [];
        } while (!empty($nextUrl));

        return [
            'endpoint' => "/{$actId}/insights",
            'raw' => $rawResponses,
            'metrics' => $metrics,
        ];
    }

    protected function executeWithTokenHandling(ConnectedAdAccount $account, callable $callback, string $endpoint): Response
    {
        $response = $this->executeHttp($callback, $endpoint);

        $fbErrorCode = $response->json('error.code');
        $isTokenExpired = ($response->status() === 401 || $fbErrorCode === 190);

        if ($isTokenExpired) {
            $account->update(['status' => 'token_expired']);
            Log::warning("Meta access token expired for account #{$account->id}. Marked token_expired.");
            throw new OAuthTokenExpiredException("Meta OAuth token is expired or invalidated. User must re-authenticate.", 'meta', $account->id);
        }

        return $response;
    }

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

                $fbCode = $response->json('error.code');
                $isRateLimit = ($response->status() === 429 || in_array($fbCode, [4, 17, 613], true));

                if ($isRateLimit) {
                    if ($attempts >= $maxAttempts) {
                        Log::warning("Meta Ads rate limit hit after {$attempts} attempts", [
                            'endpoint' => $endpoint,
                            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                        ]);
                        throw new ProviderRateLimitException("Meta Ads rate limit reached.", 'meta');
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
                    Log::error("Meta network connection error", [
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
            throw new RuntimeException("Meta Marketing API credentials have not been configured.");
        }
    }
}
