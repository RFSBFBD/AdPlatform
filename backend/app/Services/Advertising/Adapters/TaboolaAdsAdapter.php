<?php

namespace App\Services\Advertising\Adapters;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Documented client-credentials and account/day reporting. Fixture tested, not live verified. */
class TaboolaAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE = 'https://backstage.taboola.com/backstage';
    private ?ProviderDeveloperConfig $config;

    public function __construct(?ProviderDeveloperConfig $config = null)
    {
        $this->config = $config ?? ProviderDeveloperConfig::where('provider', 'taboola')->first();
    }

    public function getProviderName(): string { return 'taboola'; }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        throw new RuntimeException('Taboola uses administrator-configured client credentials, not browser OAuth. Use the credential-account discovery flow.');
    }

    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        throw new RuntimeException('Taboola does not use an authorization-code callback.');
    }

    public function issueToken(): array
    {
        if (!$this->config?->is_enabled || !$this->config?->app_id || !$this->config?->app_secret) {
            throw new RuntimeException('Taboola client credentials must be configured and enabled.');
        }
        $r = Http::asForm()->timeout(30)->connectTimeout(10)->post(self::BASE . '/oauth/token', [
            'client_id' => $this->config->app_id, 'client_secret' => $this->config->app_secret,
            'grant_type' => 'client_credentials',
        ]);
        if ($r->failed() || !$r->json('access_token')) {
            throw new RuntimeException('Taboola token grant failed (HTTP '.$r->status().').');
        }
        return ['access_token' => $r->json('access_token'), 'refresh_token' => null,
            'expires_at' => now()->addSeconds(max(0, (int) ($r->json('expires_in') ?? 43200)))];
    }

    public function refreshToken(string $refreshToken): array { return $this->issueToken(); }

    public function discoverAccounts(string $accessToken): array
    {
        $j = $this->get($accessToken, '/users/current/allowed-accounts');
        $out = [];
        foreach ($j['results'] as $a) {
            if (empty($a['account_id'])) { throw new RuntimeException('Taboola account response lacks account_id.'); }
            $out[] = ['provider' => 'taboola', 'external_account_id' => (string) $a['account_id'],
                'account_name' => $a['name'] ?? $a['account_id'], 'currency' => $a['currency'] ?? null,
                'timezone' => $a['time_zone_name'] ?? null];
        }
        return $out;
    }

    public function fetchCampaigns(ConnectedAdAccount $account): array
    {
        // Scope is account/day reporting. An empty campaign list is not a live sync assertion.
        return ['endpoint' => 'not_requested_account_level_reporting', 'raw' => [], 'campaigns' => []];
    }

    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || $startDate > $endDate) {
            throw new RuntimeException('Invalid Taboola reporting range.');
        }
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $t = $this->issueToken();
            $account->update(['access_token' => $t['access_token'], 'token_expires_at' => $t['expires_at']]);
        }
        $path = '/'.rawurlencode($account->external_account_id).'/reports/campaign-summary/dimensions/day';
        $j = $this->get($account->access_token, $path, ['start_date' => $startDate, 'end_date' => $endDate]);
        $metrics = [];
        foreach ($j['results'] as $r) {
            if (!isset($r['date'], $r['spent'], $r['clicks'], $r['impressions'], $r['cpa_actions_num'], $r['conversions_value'])) {
                throw new RuntimeException('Taboola report lacks required metrics; no partial result imported.');
            }
            if (isset($r['currency']) && $r['currency'] !== $account->currency) {
                throw new RuntimeException('Taboola report currency differs from account currency.');
            }
            $metrics[] = ['external_campaign_id' => null, 'external_ad_set_id' => null, 'external_ad_id' => null,
                'date' => substr($r['date'], 0, 10), 'spend' => \App\Services\Advertising\MetricOperand::read($r,'spent'),
                'impressions' => \App\Services\Advertising\MetricOperand::read($r,'impressions',true), 'clicks' => \App\Services\Advertising\MetricOperand::read($r,'clicks',true),
                'conversions' => \App\Services\Advertising\MetricOperand::read($r,'cpa_actions_num'),
                'conversion_value' => \App\Services\Advertising\MetricOperand::read($r,'conversions_value'),
                'metric_availability' => ['conversions' => isset($r['cpa_actions_num']), 'conversion_value' => isset($r['conversions_value'])],
                'reporting_caveat' => 'Clicks may change for 48 hours and conversions for 30 days.'];
        }
        return ['endpoint' => $path, 'raw' => [$j], 'metrics' => $metrics];
    }

    private function get(string $token, string $path, array $params = []): array
    {
        $r = Http::withToken($token)->timeout(30)->connectTimeout(10)->get(self::BASE.'/api/1.0'.$path, $params);
        if ($r->status() === 401) { throw new \App\Exceptions\OAuthTokenExpiredException('Taboola token expired; renew the client-credentials grant.', 'taboola'); }
        if ($r->status() === 429) { throw new \App\Exceptions\ProviderRateLimitException('Taboola rate limited the request.', 'taboola'); }
        $j = $r->json();
        if ($r->failed() || !is_array($j) || !isset($j['results']) || !is_array($j['results'])) {
            throw new RuntimeException('Taboola response failed validation (HTTP '.$r->status().').');
        }
        return $j;
    }
}
