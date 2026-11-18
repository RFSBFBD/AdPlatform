<?php

namespace App\Services\Advertising\Adapters;

use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

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

        $response = Http::withToken($accessToken)
            ->withHeaders($headers)
            ->get("https://googleads.googleapis.com/{$this->apiVersion}/customers:listAccessibleCustomers");

        if ($response->failed()) {
            throw new RuntimeException("Failed to discover Google Ads customers: " . $response->body());
        }

        $customerResourceNames = $response->json('resourceNames', []);
        $accounts = [];

        foreach ($customerResourceNames as $resourceName) {
            // e.g. "customers/1234567890"
            $customerId = str_replace('customers/', '', $resourceName);

            $accounts[] = [
                'provider' => 'google',
                'external_account_id' => $customerId,
                'account_name' => "Google Ads Customer ({$customerId})",
                'currency' => 'USD',
                'timezone' => 'UTC',
                'raw_status' => 'ACCESSIBLE',
            ];
        }

        return $accounts;
    }

    public function refreshToken(string $refreshToken): array
    {
        $this->ensureConfigured();

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to refresh Google access token: " . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $refreshToken,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ];
    }

    public function fetchCampaigns(\App\Models\ConnectedAdAccount $account): array
    {
        $this->ensureConfigured();
        $customerId = str_replace('-', '', $account->external_account_id);

        $query = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type FROM campaign ORDER BY campaign.id";

        $response = Http::withHeaders([
            'developer-token' => $this->config->developer_token ?? '',
            'Authorization' => "Bearer {$account->access_token}",
        ])->post("https://googleads.googleapis.com/{$this->apiVersion}/customers/{$customerId}/googleAds:searchStream", [
            'query' => $query,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch Google campaigns: " . $response->body());
        }

        $raw = $response->json();
        $campaigns = [];

        foreach ($raw as $batch) {
            foreach ($batch['results'] ?? [] as $row) {
                $c = $row['campaign'] ?? [];
                $campaigns[] = [
                    'external_campaign_id' => (string) ($c['id'] ?? ''),
                    'name' => $c['name'] ?? 'Unnamed Google Campaign',
                    'status' => strtoupper($c['status'] ?? 'ENABLED'),
                    'objective' => $c['advertisingChannelType'] ?? null,
                    'daily_budget' => null,
                    'lifetime_budget' => null,
                    'currency' => $account->currency ?? 'USD',
                    'start_date' => null,
                    'end_date' => null,
                    'metadata' => $c,
                ];
            }
        }

        return [
            'endpoint' => "/customers/{$customerId}/googleAds:searchStream",
            'raw' => $raw,
            'campaigns' => $campaigns,
        ];
    }

    public function fetchInsights(\App\Models\ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $this->ensureConfigured();
        $customerId = str_replace('-', '', $account->external_account_id);

        $query = "SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '{$startDate}' AND '{$endDate}'";

        $response = Http::withHeaders([
            'developer-token' => $this->config->developer_token ?? '',
            'Authorization' => "Bearer {$account->access_token}",
        ])->post("https://googleads.googleapis.com/{$this->apiVersion}/customers/{$customerId}/googleAds:searchStream", [
            'query' => $query,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch Google insights: " . $response->body());
        }

        $raw = $response->json();
        $metrics = [];

        foreach ($raw as $batch) {
            foreach ($batch['results'] ?? [] as $row) {
                $c = $row['campaign'] ?? [];
                $m = $row['metrics'] ?? [];
                $s = $row['segments'] ?? [];

                // Google cost_micros is micro-currency (1 USD = 1,000,000 micros)
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
        }

        return [
            'endpoint' => "/customers/{$customerId}/googleAds:searchStream/insights",
            'raw' => $raw,
            'metrics' => $metrics,
        ];
    }

    protected function ensureConfigured(): void
    {
        if (!$this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException("Google Ads API credentials have not been configured.");
        }
    }
}
