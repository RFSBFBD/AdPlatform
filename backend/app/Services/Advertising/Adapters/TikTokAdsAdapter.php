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

class TikTokAdsAdapter implements AdvertisingProviderInterface
{
    protected ?ProviderDeveloperConfig $config;
    protected string $apiVersion = 'v1.3';

    public function __construct(?ProviderDeveloperConfig $config = null)
    {
        $this->config = $config ?? ProviderDeveloperConfig::where('provider', 'tiktok')->first();
        if ($this->config && !empty($this->config->api_version)) {
            $this->apiVersion = $this->config->api_version;
        }
    }

    public function getProviderName(): string
    {
        return 'tiktok';
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->ensureConfigured();

        $params = http_build_query([
            'app_id' => $this->config->app_id,
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);

        return "https://business-api.tiktok.com/portal/auth?{$params}";
    }

    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($code) {
            return Http::timeout(30)->connectTimeout(10)
                ->post("https://business-api.tiktok.com/open_api/{$this->apiVersion}/oauth2/access_token/", [
                    'app_id' => $this->config->app_id,
                    'secret' => $this->config->app_secret,
                    'auth_code' => $code,
                ]);
        }, 'tiktok:oauth2/access_token');

        if ($response->failed() || ($response->json('code') !== 0)) {
            $errMsg = $response->json('message') ?? $response->body();
            throw new RuntimeException("TikTok OAuth exchange failed: {$errMsg}");
        }

        $data = $response->json('data', []);

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
            'advertiser_ids' => $data['advertiser_ids'] ?? [],
        ];
    }

    public function discoverAccounts(string $accessToken): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($accessToken) {
            return Http::timeout(30)->connectTimeout(10)
                ->withHeaders(['Access-Token' => $accessToken])
                ->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/oauth2/advertiser/get/", [
                    'app_id' => $this->config->app_id,
                    'secret' => $this->config->app_secret,
                ]);
        }, 'tiktok:oauth2/advertiser/get');

        if ($response->failed() || ($response->json('code') !== 0)) {
            $errMsg = $response->json('message') ?? $response->body();
            throw new RuntimeException("Failed to discover TikTok advertisers: {$errMsg}");
        }

        $items = $response->json('data.list', []);
        $accounts = [];

        foreach ($items as $item) {
            $accounts[] = [
                'provider' => 'tiktok',
                'external_account_id' => (string) $item['advertiser_id'],
                'account_name' => $item['advertiser_name'] ?? "TikTok Advertiser ({$item['advertiser_id']})",
                'currency' => $item['currency'] ?? 'USD',
                'timezone' => $item['timezone'] ?? 'UTC',
                'raw_status' => $item['status'] ?? 'STATUS_ENABLE',
            ];
        }

        return $accounts;
    }

    public function refreshToken(string $refreshToken): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($refreshToken) {
            return Http::timeout(30)->connectTimeout(10)
                ->post("https://business-api.tiktok.com/open_api/{$this->apiVersion}/oauth2/refresh_token/", [
                    'app_id' => $this->config->app_id,
                    'secret' => $this->config->app_secret,
                    'refresh_token' => $refreshToken,
                ]);
        }, 'tiktok:oauth2/refresh_token');

        if ($response->failed() || ($response->json('code') !== 0)) {
            $errMsg = $response->json('message') ?? $response->body();
            throw new OAuthTokenExpiredException("Failed to refresh TikTok token: {$errMsg}", 'tiktok');
        }

        $data = $response->json('data', []);

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
        ];
    }

    public function fetchCampaigns(ConnectedAdAccount $account): array
    {
        $adHierarchy = [];

        // 1. Fetch Ad Groups with complete pagination
        try {
            $agPage = 1;
            do {
                $agRes = $this->executeWithTokenHandling($account, function () use ($account, $agPage) {
                    return Http::timeout(30)->connectTimeout(10)
                        ->withHeaders(['Access-Token' => $account->access_token])
                        ->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/adgroup/get/", [
                            'advertiser_id' => $account->external_account_id,
                            'page' => $agPage,
                            'page_size' => 100,
                        ]);
                }, 'tiktok:adgroup/get');

                if ($agRes->successful() && ($agRes->json('code') === 0)) {
                    $list = $agRes->json('data.list', []);
                    foreach ($list as $ag) {
                        $campId = (string) ($ag['campaign_id'] ?? '');
                        $agId = (string) ($ag['adgroup_id'] ?? '');
                        if ($campId && $agId) {
                            $adHierarchy[$campId][$agId] = [
                                'external_ad_set_id' => $agId,
                                'name' => $ag['adgroup_name'] ?? "Ad Group {$agId}",
                                'status' => strtoupper($ag['operation_status'] ?? 'ENABLE'),
                                'daily_budget' => isset($ag['budget']) ? (float) $ag['budget'] : null,
                                'ads' => [],
                            ];
                        }
                    }

                    $totalPage = (int) ($agRes->json('data.page_info.total_page') ?? 1);
                    $agPage++;
                } else {
                    break;
                }
            } while ($agPage <= $totalPage && !empty($list));
        } catch (Throwable $e) {
            Log::debug("TikTok adgroup fetch warning: " . $e->getMessage());
        }

        // 2. Fetch Ads with complete pagination
        try {
            $adPage = 1;
            do {
                $adRes = $this->executeWithTokenHandling($account, function () use ($account, $adPage) {
                    return Http::timeout(30)->connectTimeout(10)
                        ->withHeaders(['Access-Token' => $account->access_token])
                        ->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/ad/get/", [
                            'advertiser_id' => $account->external_account_id,
                            'page' => $adPage,
                            'page_size' => 100,
                        ]);
                }, 'tiktok:ad/get');

                if ($adRes->successful() && ($adRes->json('code') === 0)) {
                    $list = $adRes->json('data.list', []);
                    foreach ($list as $ad) {
                        $agId = (string) ($ad['adgroup_id'] ?? '');
                        $adId = (string) ($ad['ad_id'] ?? '');
                        foreach ($adHierarchy as $campId => &$groups) {
                            if (isset($groups[$agId]) && $adId) {
                                $groups[$agId]['ads'][] = [
                                    'external_ad_id' => $adId,
                                    'name' => $ad['ad_name'] ?? "Ad {$adId}",
                                    'status' => strtoupper($ad['operation_status'] ?? 'ENABLE'),
                                ];
                            }
                        }
                    }

                    $totalPage = (int) ($adRes->json('data.page_info.total_page') ?? 1);
                    $adPage++;
                } else {
                    break;
                }
            } while ($adPage <= $totalPage && !empty($list));
        } catch (Throwable $e) {
            Log::debug("TikTok ad fetch warning: " . $e->getMessage());
        }

        // 3. Fetch Campaigns with complete pagination
        $campaigns = [];
        $rawResponses = [];
        $campPage = 1;

        do {
            $response = $this->executeWithTokenHandling($account, function () use ($account, $campPage) {
                return Http::timeout(30)->connectTimeout(10)
                    ->withHeaders(['Access-Token' => $account->access_token])
                    ->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/campaign/get/", [
                        'advertiser_id' => $account->external_account_id,
                        'page' => $campPage,
                        'page_size' => 100,
                    ]);
            }, 'tiktok:campaign/get');

            if ($response->failed() || ($response->json('code') !== 0)) {
                $msg = $response->json('message') ?? $response->body();
                throw new RuntimeException("Failed to fetch TikTok campaigns: {$msg}");
            }

            $raw = $response->json();
            $rawResponses[] = $raw;
            $list = $raw['data']['list'] ?? [];

            foreach ($list as $item) {
                $campId = (string) ($item['campaign_id'] ?? '');
                $adSets = [];
                if (isset($adHierarchy[$campId])) {
                    $adSets = array_values($adHierarchy[$campId]);
                }

                $campaigns[] = [
                    'external_campaign_id' => $campId,
                    'name' => $item['campaign_name'] ?? 'Unnamed TikTok Campaign',
                    'status' => strtoupper($item['operation_status'] ?? 'ENABLE'),
                    'objective' => $item['objective_type'] ?? null,
                    'daily_budget' => isset($item['budget']) ? (float) $item['budget'] : null,
                    'lifetime_budget' => null,
                    'currency' => $account->currency ?? 'USD',
                    'start_date' => null,
                    'end_date' => null,
                    'metadata' => $item,
                    'ad_sets' => $adSets,
                ];
            }

            $totalPage = (int) ($response->json('data.page_info.total_page') ?? 1);
            $campPage++;
        } while ($campPage <= $totalPage && !empty($list));

        return [
            'endpoint' => '/campaign/get/',
            'raw' => $rawResponses,
            'campaigns' => $campaigns,
        ];
    }

    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $metrics = [];
        $rawResponses = [];
        $page = 1;

        do {
            $response = $this->executeWithTokenHandling($account, function () use ($account, $startDate, $endDate, $page) {
                return Http::timeout(30)->connectTimeout(10)
                    ->withHeaders(['Access-Token' => $account->access_token])
                    ->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/report/integrated/get/", [
                        'advertiser_id' => $account->external_account_id,
                        'report_type' => 'BASIC',
                        'data_level' => 'AUCTION_CAMPAIGN',
                        'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
                        'metrics' => json_encode(['spend', 'impressions', 'clicks', 'conversion', 'total_purchase_value']),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'page' => $page,
                        'page_size' => 500,
                    ]);
            }, 'tiktok:report/integrated/get');

            if ($response->failed() || ($response->json('code') !== 0)) {
                $msg = $response->json('message') ?? $response->body();
                throw new RuntimeException("Failed to fetch TikTok insights: {$msg}");
            }

            $raw = $response->json();
            $rawResponses[] = $raw;
            $list = $raw['data']['list'] ?? [];

            foreach ($list as $item) {
                $dims = $item['dimensions'] ?? [];
                $m = $item['metrics'] ?? [];

                $metrics[] = [
                    'external_campaign_id' => (string) ($dims['campaign_id'] ?? ''),
                    'external_ad_set_id' => null,
                    'external_ad_id' => null,
                    'date' => substr($dims['stat_time_day'] ?? $startDate, 0, 10),
                    'spend' => (float) ($m['spend'] ?? 0),
                    'impressions' => (int) ($m['impressions'] ?? 0),
                    'clicks' => (int) ($m['clicks'] ?? 0),
                    'conversions' => (float) ($m['conversion'] ?? 0),
                    'conversion_value' => (float) ($m['total_purchase_value'] ?? 0),
                ];
            }

            $totalPage = (int) ($response->json('data.page_info.total_page') ?? 1);
            $page++;
        } while ($page <= $totalPage && !empty($list));

        return [
            'endpoint' => '/report/integrated/get/',
            'raw' => $rawResponses,
            'metrics' => $metrics,
        ];
    }

    protected function executeWithTokenHandling(ConnectedAdAccount $account, callable $callback, string $endpoint): Response
    {
        $response = $this->executeHttp($callback, $endpoint);

        $code = $response->json('code');
        $isTokenExpired = ($response->status() === 401 || $code === 40105 || $code === 40100);

        if ($isTokenExpired && !empty($account->refresh_token)) {
            Log::info("TikTok access token expired for account #{$account->id}. Attempting automatic refresh...");
            try {
                $refreshed = $this->refreshToken($account->refresh_token);
                $account->update([
                    'access_token' => $refreshed['access_token'],
                    'refresh_token' => $refreshed['refresh_token'],
                    'expires_at' => $refreshed['expires_at'],
                ]);
                $response = $this->executeHttp($callback, $endpoint);
            } catch (Throwable $refreshEx) {
                $account->update(['status' => 'token_expired']);
                throw new OAuthTokenExpiredException("TikTok token refresh failed: " . $refreshEx->getMessage(), 'tiktok', $account->id);
            }
        } elseif ($response->status() === 401 || $response->status() === 403 || $code === 40105) {
            $account->update(['status' => 'token_expired']);
            throw new OAuthTokenExpiredException("TikTok authorization rejected: HTTP {$response->status()}", 'tiktok', $account->id);
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

                if ($response->status() === 429 || $response->json('code') === 40101) {
                    if ($attempts >= $maxAttempts) {
                        Log::warning("TikTok Ads rate limit hit after {$attempts} attempts", [
                            'endpoint' => $endpoint,
                            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                        ]);
                        throw new ProviderRateLimitException("TikTok Ads rate limit reached.", 'tiktok');
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
                    Log::error("TikTok network connection error", [
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
            throw new RuntimeException("TikTok Marketing API credentials have not been configured.");
        }
    }
}
