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
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Snapchat Marketing API (Ads API). CODE ONLY: never run against a live Snap account.
 * Endpoint shapes come from the official docs (developers.snap.com/marketing-api/Ads-API/*),
 * listed in docs/PROVIDER_SOURCES.md. Bare ACCOUNT-LEVEL DAILY stats supports spend only.
 * Return separate spend inspection, never canonical delivery or conversion metrics.
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

        return self::AUTH.'/authorize?'.http_build_query([
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
        $res = Http::asForm()->timeout(30)->connectTimeout(10)->post(self::AUTH.'/access_token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
        if ($res->failed() || empty($res->json('access_token'))) {
            throw new RuntimeException('Snapchat OAuth exchange failed (HTTP '.$res->status().').');
        }

        return $this->tokenPayload($res, null);
    }

    public function refreshToken(string $refreshToken): array
    {
        $this->ensureConfigured();
        $res = Http::asForm()->timeout(30)->connectTimeout(10)->post(self::AUTH.'/access_token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config->app_id,
            'client_secret' => $this->config->app_secret,
            'refresh_token' => $refreshToken,
        ]);
        if ($res->failed() || empty($res->json('access_token'))) {
            throw new OAuthTokenExpiredException('Failed to refresh Snapchat token (HTTP '.$res->status().').', 'snapchat');
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
            throw new RuntimeException('Failed to discover Snapchat ad accounts (HTTP '.$res->status().').');
        }

        $body = $res->json();
        if (! is_array($body) || array_key_exists('paging', $body)) {
            throw new RuntimeException('Snapchat discovery paging requires verified handling.');
        }
        $accounts = [];
        $seenAccounts = [];
        $seenOrgs = [];
        foreach (FetchBoundary::rows($body['organizations'] ?? null, 'Snapchat organizations') as $row) {
            if (($row['sub_request_status'] ?? null) !== 'SUCCESS') {
                throw new RuntimeException('Snapchat organization subrequest failed.');
            }
            $org = $row['organization'] ?? [];
            FetchBoundary::unique($seenOrgs, FetchBoundary::id($org['id'] ?? null, 'Snapchat organization'), 'Snapchat organization');
            if (array_key_exists('paging', $org)) {
                throw new RuntimeException('Snapchat nested discovery paging requires verified handling.');
            }
            foreach (FetchBoundary::rows($org['ad_accounts'] ?? null, 'Snapchat account roster') as $a) {
                $id = FetchBoundary::id($a['id'] ?? null, 'Snapchat account');
                FetchBoundary::unique($seenAccounts, $id, 'Snapchat account');
                // Currency and timezone must come from the account itself. No default is invented.
                $accounts[] = [
                    'provider' => 'snapchat',
                    'external_account_id' => (string) $a['id'],
                    'account_name' => $a['name'] ?? ('Snapchat ad account '.$a['id']),
                    'currency' => $a['currency'] ?? null,
                    'timezone' => $a['timezone'] ?? null,
                    'metadata_source' => ['version' => AccountMetadataEvidence::VERSION, 'currency' => $a['currency'] ?? null, 'timezone' => $a['timezone'] ?? null],
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
        if ($tz === '' || ! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Snapchat account timezone is missing or unknown; daily boundaries cannot be set, so nothing was pulled.');
        }
        if (empty($account->currency)) {
            throw new RuntimeException('Snapchat account currency is missing; nothing was pulled.');
        }

        $metrics = [];
        $raw = [];
        $seenDays = [];
        foreach ([$startDate, $endDate] as $d) {
            $v = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if (! $v || $v->format('Y-m-d') !== $d) {
                throw new RuntimeException('Snapchat daily range invalid.');
            }
        }
        if ($startDate > $endDate || (new \DateTimeImmutable($startDate))->diff(new \DateTimeImmutable($endDate))->days > 365) {
            throw new RuntimeException('Snapchat range exceeds local366-day limit or is reversed.');
        }
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
                throw new RuntimeException('Failed to fetch Snapchat stats (HTTP '.$res->status().').');
            }
            if (! is_array($json) || array_is_list($json) || array_key_exists('paging', $json)) {
                throw new RuntimeException('Snapchat envelope/paging requires verification.');
            }
            $entries = FetchBoundary::rows($json['timeseries_stats'] ?? null, 'Snapchat timeseries_stats');
            if (count($entries) !== 1) {
                throw new RuntimeException('Snapchat account stats require exactly one explicit subrequest.');
            }
            foreach ($entries as $entry) {
                if (strtoupper((string) ($entry['sub_request_status'] ?? '')) !== 'SUCCESS') {
                    throw new RuntimeException('Snapchat stats returned a failed sub-request; nothing was stored for this range.');
                }
                $series = $entry['timeseries_stat'] ?? null;
                if (! is_array($series) || array_key_exists('paging', $series)) {
                    throw new RuntimeException('Snapchat timeseries wrapper/paging invalid.');
                }
                foreach (FetchBoundary::rows($series['timeseries'] ?? null, 'Snapchat daily series') as $day) {
                    $stamp = $day['start_time'] ?? null;
                    if (! is_string($stamp) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $stamp)) {
                        throw new RuntimeException('Snapchat daily timestamp missing.');
                    }
                    $date = substr($stamp, 0, 10);
                    if ($date < $cursor->format('Y-m-d') || $date > $chunkEnd->format('Y-m-d')) {
                        throw new RuntimeException('Snapchat day outside requested chunk.');
                    }
                    FetchBoundary::unique($seenDays, $date, 'Snapchat daily report');
                    $s = $day['stats'] ?? [];
                    // Bare ad-account stats documents spend only. Extra payload fields do not establish support.
                    $metrics[] = ['date' => $date, 'spend' => MetricOperand::read($s, 'spend', false, 1000000),
                        'currency' => $account->currency, 'source_scope' => 'bare_ad_account_spend_only'];
                }
            }
            $cursor = $chunkEnd->copy()->addDay();
        }

        return ['fetch_status' => 'spend_inspection_only_not_canonical', 'canonical_import_status' => 'unsupported_source_scope', 'reason' => 'Bare Snapchat ad-account stats supports spend only. Canonical delivery and outcome import is unavailable; no replacement hierarchy is inferred.', 'endpoint' => '/adaccounts/{id}/stats (DAY, spend only)', 'raw' => $raw, 'spend_inspection' => $metrics, 'metrics' => []];
    }

    private function statsRequest(ConnectedAdAccount $account, Carbon $start, Carbon $endExclusive): Response
    {
        $fmt = 'Y-m-d\TH:i:s.vP';
        $query = [
            'granularity' => 'DAY',
            'fields' => 'spend',
            'omit_empty' => 'false',
            'start_time' => $start->format($fmt),
            'end_time' => $endExclusive->format($fmt),
        ];
        $path = '/adaccounts/'.$account->external_account_id.'/stats';

        $res = $this->get((string) $account->access_token, $path, $query);
        if ($res->status() === 401 && ! empty($account->refresh_token)) {
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
            throw new OAuthTokenExpiredException('Snapchat authorization rejected: HTTP '.$res->status(), 'snapchat', $account->id);
        }

        return $res;
    }

    private function get(string $token, string $path, array $query = []): Response
    {
        $delayMs = 200;
        for ($attempt = 1; ; $attempt++) {
            $res = Http::withoutRedirecting()->withToken($token)->timeout(30)->connectTimeout(10)->get(self::API.$path, $query);
            if ($res->status() === 429) {
                if ($attempt >= 3) {
                    throw new ProviderRateLimitException('Snapchat rate limit reached.', 'snapchat');
                }
            } elseif (! ($res->serverError() && $attempt < 3)) {
                return $res;
            }
            usleep($delayMs * 1000);
            $delayMs *= 2;
        }
    }

    protected function ensureConfigured(): void
    {
        if (! $this->config || empty($this->config->app_id) || empty($this->config->app_secret)) {
            throw new RuntimeException('Snapchat OAuth app credentials have not been configured.');
        }
    }
}
