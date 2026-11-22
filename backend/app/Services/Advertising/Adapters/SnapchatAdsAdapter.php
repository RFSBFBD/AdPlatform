<?php

namespace App\Services\Advertising\Adapters;

use App\Exceptions\OAuthTokenExpiredException;
use App\Exceptions\ProviderRateLimitException;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Snapchat Marketing API (Ads API). CODE ONLY: never run against a live Snap account.
 * Endpoint shapes come from the official docs (developers.snap.com/marketing-api/Ads-API/*),
 * listed in docs/PROVIDER_SOURCES.md. Reporting is ACCOUNT-LEVEL DAILY only (no campaign breakdown),
 * because the breakdown response shape was not verifiable in the docs.
 */
class SnapchatAdsAdapter implements AdvertisingProviderInterface
{
    public const API = 'https://adsapi.snapchat.com/v1';
    public const AUTH = 'https://accounts.snapchat.com/login/oauth2';
    /** Documented defaults, sent explicitly so the attribution rule is never implicit. */
    public const SWIPE_WINDOW = '28_DAY';
    public const VIEW_WINDOW = '1_DAY';
    /** Days per stats request. The documented API gives no DAY-range limit, so this is a cautious engineering choice. */
    public const CHUNK_DAYS = 30;

    protected ?ProviderDeveloperConfig $config;

    public function __construct(?ProviderDeveloperConfig $config = null)
    {
        $this->config = $config ?? ProviderDeveloperConfig::where('provider', 'snapchat')->first();
    }

    public function getProviderName(): string
    {
        return 'snapchat';
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->ensureConfigured();

        return self::AUTH . '/authorize?' . http_build_query([
            'client_id' => $this->config->app_id,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'snapchat-marketing-api',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        $this->ensureConfigured();
        $res = Http::asForm()->timeout(30)->connectTimeout(10)->post(self::AUTH . '/access_token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
        if ($res->failed() || empty($res->json('access_token'))) {
            throw new RuntimeException('Snapchat OAuth exchange failed (HTTP ' . $res->status() . ').');
        }

        return $this->tokenPayload($res, null);
    }

    public function refreshToken(string $refreshToken): array
    {
        $this->ensureConfigured();
        $res = Http::asForm()->timeout(30)->connectTimeout(10)->post(self::AUTH . '/access_token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'refresh_token' => $refreshToken,
        ]);
        if ($res->failed() || empty($res->json('access_token'))) {
            throw new OAuthTokenExpiredException('Failed to refresh Snapchat token (HTTP ' . $res->status() . ').', 'snapchat');
        }

        return $this->tokenPayload($res, $refreshToken);
    }

    private function tokenPayload(Response $res, ?string $fallbackRefresh): array
    {
        // Documented lifetime is 3600 seconds; use the returned value, never a longer assumed one.
        $ttl = (int) ($res->json('expires_in') ?? 3600);

        return [
            'access_token' => $res->json('access_token'),
            'refresh_token' => $res->json('refresh_token') ?? $fallbackRefresh,
            'expires_at' => now()->addSeconds(max(0, $ttl)),
        ];
    }

    public function discoverAccounts(string $accessToken): array
    {
        $res = $this->get($accessToken, '/me/organizations', ['with_ad_accounts' => 'true']);
        if ($res->failed() || strtoupper((string) $res->json('request_status')) !== 'SUCCESS') {
            throw new RuntimeException('Failed to discover Snapchat ad accounts (HTTP ' . $res->status() . ').');
        }

        $accounts = [];
        foreach ((array) $res->json('organizations', []) as $row) {
            $org = $row['organization'] ?? [];
            foreach ((array) ($org['ad_accounts'] ?? []) as $a) {
                if (empty($a['id'])) {
                    continue;
                }
                // Currency and timezone must come from the account itself. No default is invented.
                $accounts[] = [
                    'provider' => 'snapchat',
                    'external_account_id' => (string) $a['id'],
                    'account_name' => $a['name'] ?? ('Snapchat ad account ' . $a['id']),
                    'currency' => $a['currency'] ?? null,
                    'timezone' => $a['timezone'] ?? null,
                    'raw_status' => $a['status'] ?? null,
                ];
            }
        }

        return $accounts;
    }

    /** Campaign-level sync is not built: the campaign list response was not verifiable. Reporting is account-level. */
    public function fetchCampaigns(ConnectedAdAccount $account): array
    {
        return ['endpoint' => 'campaigns (not synced; account-level reporting only)', 'raw' => [], 'campaigns' => []];
    }

    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        $tz = (string) $account->timezone;
        if ($tz === '' || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Snapchat account timezone is missing or unknown; daily boundaries cannot be set, so nothing was pulled.');
        }
        if (empty($account->currency)) {
            throw new RuntimeException('Snapchat account currency is missing; nothing was pulled.');
        }

        $metrics = [];
        $raw = [];
        $cursor = Carbon::parse($startDate, $tz)->startOfDay();
        $last = Carbon::parse($endDate, $tz)->startOfDay();

        while ($cursor->lte($last)) {
            $chunkEnd = $cursor->copy()->addDays(self::CHUNK_DAYS - 1);
            if ($chunkEnd->gt($last)) {
                $chunkEnd = $last->copy();
            }
            $res = $this->statsRequest($account, $cursor, $chunkEnd->copy()->addDay());
            $json = $res->json();
            $raw[] = $json;

            if ($res->failed() || strtoupper((string) ($json['request_status'] ?? '')) !== 'SUCCESS') {
                throw new RuntimeException('Failed to fetch Snapchat stats (HTTP ' . $res->status() . ').');
            }
            foreach ((array) ($json['timeseries_stats'] ?? []) as $entry) {
                if (strtoupper((string) ($entry['sub_request_status'] ?? '')) !== 'SUCCESS') {
                    throw new RuntimeException('Snapchat stats returned a failed sub-request; nothing was stored for this range.');
                }
                foreach ((array) ($entry['timeseries_stat']['timeseries'] ?? []) as $day) {
                    $s = $day['stats'] ?? [];
                    // `spend` and purchase value are micro-currency of the account currency (1,000,000 = 1.00).
                    $metrics[] = [
                        'external_campaign_id' => null,
                        'external_ad_set_id' => null,
                        'external_ad_id' => null,
                        'date' => substr((string) $day['start_time'], 0, 10),
                        'spend' => ((float) ($s['spend'] ?? 0)) / 1000000,
                        'impressions' => (int) ($s['impressions'] ?? 0),
                        // Snap reports swipe-ups, not link clicks. They are stored in `clicks` and are not the same thing.
                        'clicks' => (int) ($s['swipes'] ?? 0),
                        'conversions' => (float) ($s['conversion_purchases'] ?? 0),
                        // Present only for ads tracked through Snap Pixel; absent means 0 reported, not "no sales".
                        'conversion_value' => ((float) ($s['conversion_purchases_value'] ?? 0)) / 1000000,
                    ];
                }
            }
            $cursor = $chunkEnd->copy()->addDay();
        }

        return ['endpoint' => '/adaccounts/{id}/stats (DAY, account level)', 'raw' => $raw, 'metrics' => $metrics];
    }

    private function statsRequest(ConnectedAdAccount $account, Carbon $start, Carbon $endExclusive): Response
    {
        $fmt = 'Y-m-d\TH:i:s.vP';
        $query = [
            'granularity' => 'DAY',
            'fields' => 'impressions,swipes,spend,conversion_purchases,conversion_purchases_value',
            'start_time' => $start->format($fmt),
            'end_time' => $endExclusive->format($fmt),
            'swipe_up_attribution_window' => self::SWIPE_WINDOW,
            'view_attribution_window' => self::VIEW_WINDOW,
        ];
        $path = '/adaccounts/' . $account->external_account_id . '/stats';

        $res = $this->get((string) $account->access_token, $path, $query);
        if ($res->status() === 401 && !empty($account->refresh_token)) {
            try {
                $new = $this->refreshToken($account->refresh_token);
                $account->update(['access_token' => $new['access_token'], 'refresh_token' => $new['refresh_token'], 'token_expires_at' => $new['expires_at']]);
            } catch (\Throwable $e) {
                $account->update(['status' => 'token_expired']);
                throw new OAuthTokenExpiredException('Snapchat token refresh failed.', 'snapchat', $account->id);
            }
            $res = $this->get((string) $account->access_token, $path, $query);
        }
        if (in_array($res->status(), [401, 403], true)) {
            $account->update(['status' => 'token_expired']);
            throw new OAuthTokenExpiredException('Snapchat authorization rejected: HTTP ' . $res->status(), 'snapchat', $account->id);
        }

        return $res;
    }

    private function get(string $token, string $path, array $query = []): Response
    {
        $delayMs = 200;
        for ($attempt = 1; ; $attempt++) {
            $res = Http::withToken($token)->timeout(30)->connectTimeout(10)->get(self::API . $path, $query);
            if ($res->status() === 429) {
                if ($attempt >= 3) {
                    throw new ProviderRateLimitException('Snapchat rate limit reached.', 'snapchat');
                }
            } elseif (!($res->serverError() && $attempt < 3)) {
                return $res;
            }
            usleep($delayMs * 1000);
            $delayMs *= 2;
        }
    }

    protected function ensureConfigured(): void
    {
        if (!$this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException('Snapchat OAuth app credentials have not been configured.');
        }
    }
}
