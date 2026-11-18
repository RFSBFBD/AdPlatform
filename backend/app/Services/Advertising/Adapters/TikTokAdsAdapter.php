<?php

namespace App\Services\Advertising\Adapters;

use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

        $response = Http::post("https://business-api.tiktok.com/open_api/{$this->apiVersion}/oauth2/access_token/", [
            'app_id' => $this->config->app_id,
            'secret' => $this->config->app_secret,
            'auth_code' => $code,
        ]);

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

        $response = Http::withHeaders([
            'Access-Token' => $accessToken,
        ])->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/oauth2/advertiser/get/", [
            'app_id' => $this->config->app_id,
            'secret' => $this->config->app_secret,
        ]);

        if ($response->failed() || ($response->json('code') !== 0)) {
            $errMsg = $response->json('message') ?? $response->body();
            throw new RuntimeException("Failed to discover TikTok advertisers: {$errMsg}");
        }

        $items = $response->json('data.list', []);
        $accounts = [];

        foreach ($items as $item) {
            $accounts[] = [
                'provider' => 'tiktok',
                'external_account_id' => (string)$item['advertiser_id'],
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

        $response = Http::post("https://business-api.tiktok.com/open_api/{$this->apiVersion}/oauth2/refresh_token/", [
            'app_id' => $this->config->app_id,
            'secret' => $this->config->app_secret,
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed() || ($response->json('code') !== 0)) {
            $errMsg = $response->json('message') ?? $response->body();
            throw new RuntimeException("Failed to refresh TikTok token: {$errMsg}");
        }

        $data = $response->json('data', []);

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
        ];
    }

    public function fetchCampaigns(\App\Models\ConnectedAdAccount $account): array
    {
        $response = Http::withHeaders([
            'Access-Token' => $account->access_token,
        ])->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/campaign/get/", [
            'advertiser_id' => $account->external_account_id,
            'page_size' => 100,
        ]);

        if ($response->failed() || ($response->json('code') !== 0)) {
            $msg = $response->json('message') ?? $response->body();
            throw new RuntimeException("Failed to fetch TikTok campaigns: {$msg}");
        }

        $raw = $response->json();
        $campaigns = [];

        foreach ($raw['data']['list'] ?? [] as $item) {
            $campaigns[] = [
                'external_campaign_id' => (string) ($item['campaign_id'] ?? ''),
                'name' => $item['campaign_name'] ?? 'Unnamed TikTok Campaign',
                'status' => strtoupper($item['operation_status'] ?? 'ENABLE'),
                'objective' => $item['objective_type'] ?? null,
                'daily_budget' => isset($item['budget']) ? (float) $item['budget'] : null,
                'lifetime_budget' => null,
                'currency' => $account->currency ?? 'USD',
                'start_date' => null,
                'end_date' => null,
                'metadata' => $item,
            ];
        }

        return [
            'endpoint' => '/campaign/get/',
            'raw' => $raw,
            'campaigns' => $campaigns,
        ];
    }

    public function fetchInsights(\App\Models\ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $response = Http::withHeaders([
            'Access-Token' => $account->access_token,
        ])->get("https://business-api.tiktok.com/open_api/{$this->apiVersion}/report/integrated/get/", [
            'advertiser_id' => $account->external_account_id,
            'report_type' => 'BASIC',
            'data_level' => 'AUCTION_CAMPAIGN',
            'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
            'metrics' => json_encode(['spend', 'impressions', 'clicks', 'conversion', 'total_purchase_value']),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'page_size' => 500,
        ]);

        if ($response->failed() || ($response->json('code') !== 0)) {
            $msg = $response->json('message') ?? $response->body();
            throw new RuntimeException("Failed to fetch TikTok insights: {$msg}");
        }

        $raw = $response->json();
        $metrics = [];

        foreach ($raw['data']['list'] ?? [] as $item) {
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

        return [
            'endpoint' => '/report/integrated/get/',
            'raw' => $raw,
            'metrics' => $metrics,
        ];
    }

    protected function ensureConfigured(): void
    {
        if (!$this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException("TikTok Marketing API credentials have not been configured.");
        }
    }
}
