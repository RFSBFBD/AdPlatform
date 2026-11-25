<?php

namespace App\Services\Advertising\Adapters;

use App\Exceptions\OAuthTokenExpiredException;
use App\Exceptions\ProviderRateLimitException;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\AccountMetadataEvidence;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use App\Services\Advertising\FetchBoundary;
use App\Services\Advertising\MetricOperand;
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
        if ($this->config && ! empty($this->config->api_version)) {
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
            return Http::withoutRedirecting()->timeout(30)->connectTimeout(10)
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
            return Http::withoutRedirecting()->timeout(30)->connectTimeout(10)
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

        $body = $response->json();
        if (! is_array($body) || array_key_exists('page_info', $body['data'] ?? [])) {
            throw new RuntimeException('TikTok discovery paging requires verified handling.');
        }
        $items = FetchBoundary::rows($body['data']['list'] ?? null, 'TikTok discovery');
        $seenAccounts = [];
        $accounts = [];

        foreach ($items as $item) {
            $id = FetchBoundary::id($item['advertiser_id'] ?? null, 'TikTok advertiser');
            FetchBoundary::unique($seenAccounts, $id, 'TikTok advertiser');
            $accounts[] = [
                'provider' => 'tiktok',
                'external_account_id' => $id,
                'account_name' => $item['advertiser_name'] ?? "TikTok Advertiser ({$item['advertiser_id']})",
                'currency' => is_string($item['currency'] ?? null) && preg_match('/^[A-Z]{3}$/D', $item['currency']) ? $item['currency'] : null,
                'timezone' => is_string($item['timezone'] ?? null) && in_array($item['timezone'], \DateTimeZone::listIdentifiers(), true) ? $item['timezone'] : null,
                'metadata_source' => ['version' => AccountMetadataEvidence::VERSION, 'currency' => $item['currency'] ?? null, 'timezone' => $item['timezone'] ?? null],
                'raw_status' => $item['status'] ?? null,
            ];
        }

        return $accounts;
    }

    public function refreshToken(string $refreshToken): array
    {
        $this->ensureConfigured();

        $response = $this->executeHttp(function () use ($refreshToken) {
            return Http::withoutRedirecting()->timeout(30)->connectTimeout(10)
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
        [$groups,$groupRaw] = $this->readPages($account, 'adgroup/get', [], 100);
        [$ads,$adRaw] = $this->readPages($account, 'ad/get', [], 100);
        [$campaignRows,$campaignRaw] = $this->readPages($account, 'campaign/get', [], 100);
        $hierarchy = [];
        $groupParents = [];
        $seenAds = [];
        $campaigns = [];
        $seenCampaigns = [];
        foreach ($groups as $g) {
            $cid = FetchBoundary::id($g['campaign_id'] ?? null, 'TikTok campaign');
            $id = FetchBoundary::id($g['adgroup_id'] ?? null, 'TikTok group');
            FetchBoundary::unique($groupParents, $id, 'TikTok group');
            $groupParents[$id] = $cid;
            $hierarchy[$cid][$id] = ['external_ad_set_id' => $id, 'name' => $g['adgroup_name'] ?? 'Unnamed TikTok group', 'status' => strtoupper($g['operation_status'] ?? 'UNKNOWN'), 'daily_budget' => MetricOperand::read($g, 'budget', false, 1, true), 'ads' => []];
        }
        foreach ($ads as $ad) {
            $gid = FetchBoundary::id($ad['adgroup_id'] ?? null, 'TikTok ad group');
            $id = FetchBoundary::id($ad['ad_id'] ?? null, 'TikTok ad');
            FetchBoundary::unique($seenAds, $id, 'TikTok ad');
            $cid = $groupParents[$gid] ?? null;
            if ($cid === null) {
                throw new RuntimeException('TikTok ad parent missing from fetched hierarchy.');
            }if (isset($ad['campaign_id']) && (string) $ad['campaign_id'] !== $cid) {
                throw new RuntimeException('TikTok ad campaign parent conflicts.');
            }
            $hierarchy[$cid][$gid]['ads'][] = ['external_ad_id' => $id, 'name' => $ad['ad_name'] ?? 'Unnamed TikTok ad', 'status' => strtoupper($ad['operation_status'] ?? 'UNKNOWN')];
        }
        foreach ($campaignRows as $c) {
            $id = FetchBoundary::id($c['campaign_id'] ?? null, 'TikTok campaign');
            FetchBoundary::unique($seenCampaigns, $id, 'TikTok campaign');
            $campaigns[] = ['external_campaign_id' => $id, 'name' => $c['campaign_name'] ?? 'Unnamed TikTok Campaign', 'status' => strtoupper($c['operation_status'] ?? 'UNKNOWN'), 'objective' => $c['objective_type'] ?? null, 'daily_budget' => MetricOperand::read($c, 'budget', false, 1, true), 'lifetime_budget' => null, 'currency' => $account->currency, 'start_date' => null, 'end_date' => null, 'metadata' => $c, 'ad_sets' => array_values($hierarchy[$id] ?? [])];
        }
        foreach (array_keys($hierarchy) as $cid) {
            if (! isset($seenCampaigns[$cid])) {
                throw new RuntimeException('TikTok group parent missing from fetched campaign roster.');
            }
        }

        return ['endpoint' => '/campaign/get/', 'raw' => ['campaign_pages' => $campaignRaw, 'adgroup_pages' => $groupRaw, 'ad_pages' => $adRaw], 'campaigns' => $campaigns, 'fetch_status' => 'complete_local_traversal_not_inventory_verified'];
    }

    /** Reject partial/malformed traversal. Empty intermediate lists do not terminate declared pages. */
    private function readPages(ConnectedAdAccount $account, string $edge, array $query, int $size): array
    {
        $page = 1;
        $expected = null;
        $rows = [];
        $raw = [];
        do {
            $response = $this->executeWithTokenHandling($account, function () use ($account, $edge, $query, $page, $size) {
                return Http::withoutRedirecting()->timeout(30)->connectTimeout(10)->withHeaders(['Access-Token' => $account->access_token])->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/{$edge}/", array_merge($query, ['advertiser_id' => $account->external_account_id, 'page' => $page, 'page_size' => $size]));
            }, 'tiktok:'.$edge);
            if (! $response->successful()) {
                throw new RuntimeException('TikTok paged fetch failed.');
            }
            $body = $response->json();
            $list = FetchBoundary::tiktok($body, $page, $expected);
            $raw[] = $body;
            array_push($rows, ...$list);
            if (count($rows) > 100000) {
                throw new RuntimeException('TikTok local row cap reached.');
            }$page++;
        } while ($page <= $expected);

        return [$rows, $raw];
    }

    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        [$list,$rawResponses] = $this->readPages($account, 'report/integrated/get', [
            'report_type' => 'BASIC', 'data_level' => 'AUCTION_CAMPAIGN', 'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
            'metrics' => json_encode(['spend', 'impressions', 'clicks', 'conversion', 'total_purchase_value']), 'start_date' => $startDate, 'end_date' => $endDate,
        ], 500);
        $metrics = [];
        $seen = [];
        foreach ($list as $item) {
            $dims = $item['dimensions'] ?? [];
            $m = $item['metrics'] ?? [];
            $id = FetchBoundary::id($dims['campaign_id'] ?? null, 'TikTok metric campaign');
            $date = $dims['stat_time_day'] ?? null;
            if (! is_string($date)) {
                throw new RuntimeException('TikTok metric date missing.');
            }FetchBoundary::unique($seen, $id.'|'.$date, 'TikTok metric');
            $metrics[] = ['external_campaign_id' => $id, 'external_ad_set_id' => null, 'external_ad_id' => null, 'date' => $date,
                'spend' => MetricOperand::read($m, 'spend'), 'impressions' => MetricOperand::read($m, 'impressions', true), 'clicks' => MetricOperand::read($m, 'clicks', true), 'conversions' => MetricOperand::read($m, 'conversion'), 'conversion_value' => MetricOperand::read($m, 'total_purchase_value')];
        }

        return [
            'endpoint' => '/report/integrated/get/',
            'fetch_status' => 'complete_local_traversal_not_inventory_verified',
            'raw' => $rawResponses,
            'metrics' => $metrics,
        ];
    }

    protected function executeWithTokenHandling(ConnectedAdAccount $account, callable $callback, string $endpoint): Response
    {
        $response = $this->executeHttp($callback, $endpoint);

        $code = $response->json('code');
        $isTokenExpired = ($response->status() === 401 || $code === 40105 || $code === 40100);

        if ($isTokenExpired && ! empty($account->refresh_token)) {
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
                throw new OAuthTokenExpiredException('TikTok token refresh failed: '.$refreshEx->getMessage(), 'tiktok', $account->id);
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
                        throw new ProviderRateLimitException('TikTok Ads rate limit reached.', 'tiktok');
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
                    Log::error('TikTok network connection error', [
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
        if (! $this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException('TikTok Marketing API credentials have not been configured.');
        }
    }
}
