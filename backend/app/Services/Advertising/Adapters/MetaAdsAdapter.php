<?php

namespace App\Services\Advertising\Adapters;

use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Meta OAuth exchange failed: " . $response->body());
        }

        $data = $response->json();
        $shortLivedToken = $data['access_token'];

        // Exchange for long-lived user access token (valid ~60 days)
        $longLivedResponse = Http::get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

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
        $response = Http::withToken($accessToken)
            ->get("https://graph.facebook.com/{$this->apiVersion}/me/adaccounts", [
                'fields' => 'id,account_id,name,currency,timezone_name,account_status',
                'limit' => 100,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to discover Meta ad accounts: " . $response->body());
        }

        $items = $response->json('data', []);
        $accounts = [];

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

        return $accounts;
    }

    public function refreshToken(string $refreshToken): array
    {
        // Meta long-lived tokens do not use traditional refresh tokens; they require re-authentication when expired.
        throw new RuntimeException("Meta does not support refresh tokens. Re-authorization is required.");
    }

    public function fetchCampaigns(\App\Models\ConnectedAdAccount $account): array
    {
        $actId = str_starts_with($account->external_account_id, 'act_')
            ? $account->external_account_id
            : 'act_' . $account->external_account_id;

        $response = Http::withToken($account->access_token)
            ->get("https://graph.facebook.com/{$this->apiVersion}/{$actId}/campaigns", [
                'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,start_time,stop_time',
                'limit' => 200,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch Meta campaigns: " . $response->body());
        }

        $raw = $response->json();
        $campaigns = [];

        foreach ($raw['data'] ?? [] as $item) {
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
            ];
        }

        return [
            'endpoint' => "/{$actId}/campaigns",
            'raw' => $raw,
            'campaigns' => $campaigns,
        ];
    }

    public function fetchInsights(\App\Models\ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $actId = str_starts_with($account->external_account_id, 'act_')
            ? $account->external_account_id
            : 'act_' . $account->external_account_id;

        $response = Http::withToken($account->access_token)
            ->get("https://graph.facebook.com/{$this->apiVersion}/{$actId}/insights", [
                'level' => 'campaign',
                'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions,action_values',
                'time_range' => json_encode(['since' => $startDate, 'until' => $endDate]),
                'time_increment' => 1,
                'limit' => 500,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch Meta insights: " . $response->body());
        }

        $raw = $response->json();
        $metrics = [];

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

        return [
            'endpoint' => "/{$actId}/insights",
            'raw' => $raw,
            'metrics' => $metrics,
        ];
    }

    protected function ensureConfigured(): void
    {
        if (!$this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException("Meta Marketing API credentials have not been configured.");
        }
    }
}
