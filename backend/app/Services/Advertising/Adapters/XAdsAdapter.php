<?php

namespace App\Services\Advertising\Adapters;

use App\Exceptions\OAuthTokenExpiredException;
use App\Exceptions\ProviderRateLimitException;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use App\Services\Advertising\Reports\XDeliveryParser;
use Carbon\CarbonImmutable;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** OAuth1 user context. Delivery-only until conversion semantics are independently implemented. */
class XAdsAdapter implements AdvertisingProviderInterface
{
    public function __construct(private ?ProviderDeveloperConfig $config = null)
    {
        $this->config ??= ProviderDeveloperConfig::where('provider', 'x')->first();
    }

    public function getProviderName(): string
    {
        return 'x';
    }

    private function credentials(): array
    {
        if (! $this->config?->is_enabled || ! $this->config?->app_id || ! $this->config?->app_secret) {
            throw new RuntimeException('X approved consumer key/secret required.');
        }

        return ['consumer_key' => $this->config->app_id, 'consumer_secret' => $this->config->app_secret, 'signature_method' => 'HMAC-SHA1'];
    }

    private function request(string $method, string $url, array $credentials, array $query = []): array
    {
        $middleware = new Oauth1($this->credentials() + $credentials);
        $h = Http::withMiddleware($middleware)->withOptions(['auth' => 'oauth', 'allow_redirects' => false])->timeout(30)->connectTimeout(10);
        try {
            $r = $h->send($method,$url,$method==='POST'?['body'=>'']:['query'=>$query]);
        } catch (\Throwable) {
            throw new RuntimeException('X signed request failed; credentials and request details are not logged.');
        }
        if ($r->status() === 401) {
            throw new OAuthTokenExpiredException('X user token revoked or expired; reconnect.', 'x');
        }
        if ($r->status() === 429) {
            throw new ProviderRateLimitException('X rate limited. Respect provider reset before retrying.', 'x');
        }
        if (! $r->successful()) {
            throw new RuntimeException('X request failed (HTTP '.$r->status().').');
        }
        $body = $r->body();
        if (str_contains(strtolower($r->header('Content-Type')), 'json') || str_starts_with(ltrim($body), '{')) {
            $j = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        } else {
            parse_str($body, $j);
        }
        if (! is_array($j)) {
            throw new RuntimeException('X response is not a supported JSON/form object.');
        }

return $j;
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $j = $this->request('POST', 'https://api.x.com/oauth/request_token', ['callback' => $redirectUri]);
        if (empty($j['oauth_token']) || empty($j['oauth_token_secret']) || ! in_array($j['oauth_callback_confirmed'] ?? null, ['true', true], true)) {
            throw new RuntimeException('X request-token response incomplete or callback not confirmed.');
        }
        Cache::put('x-oauth:'.$state, Crypt::encryptString(json_encode(['token' => $j['oauth_token'], 'secret' => $j['oauth_token_secret']])), 600);

        return 'https://api.x.com/oauth/authorize?'.http_build_query(['oauth_token' => $j['oauth_token']], '', '&', PHP_QUERY_RFC3986);
    }

    /** Dedicated callback supplies state, returned token, verifier. Standard OAuth2 callback is refused. */
    public function complete(string $state, string $token, string $verifier): array
    {
        $raw = Cache::get('x-oauth:'.$state);
        if (! $raw) {
            throw new RuntimeException('X request token expired. Start again.');
        }$request = json_decode(Crypt::decryptString($raw), true);
        if (! hash_equals($request['token'], $token) || $verifier === '') {
            throw new RuntimeException('X callback token mismatch or verifier missing.');
        }
        $j = $this->request('POST', 'https://api.x.com/oauth/access_token', ['token' => $token, 'token_secret' => $request['secret'], 'verifier' => $verifier]);
        if (empty($j['oauth_token']) || empty($j['oauth_token_secret'])) {
            throw new RuntimeException('X access token and secret required.');
        }
        Cache::forget('x-oauth:'.$state);

        return ['access_token' => json_encode(['token' => $j['oauth_token'], 'token_secret' => $j['oauth_token_secret']], JSON_THROW_ON_ERROR), 'refresh_token' => null, 'expires_at' => null];
    }

    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        throw new RuntimeException('X uses its OAuth1 token/verifier callback, not OAuth2 code.');
    }

    public function refreshToken(string $refreshToken): array
    {
        throw new RuntimeException('X OAuth1 has no OAuth2 refresh grant. Reauthorize when revoked.');
    }

    public function api(string $encoded, string $path, array $query = []): array
    {
        $pair = json_decode($encoded, true);
        if (! is_array($pair) || empty($pair['token']) || empty($pair['token_secret'])) {
            throw new RuntimeException('X OAuth1 credential pair missing.');
        }
        $version = $this->config?->api_version ?: '12';
        if (! preg_match('/^\d{1,3}$/', $version)) {
            throw new RuntimeException('X API version must be numeric.');
        }

        return $this->request('GET', 'https://ads-api.x.com/'.$version.$path, $pair, $query);
    }

    private function pages(string $token, string $path): array
    {
        $out = [];
        $cursor = null;
        $seen = [];
        do {
            $j = $this->api($token, $path, array_filter(['count' => 1000, 'cursor' => $cursor], fn ($x) => $x !== null));
            if (! isset($j['data']) || ! is_array($j['data'])) {
                throw new RuntimeException('X collection data missing.');
            }array_push($out, ...$j['data']);
            $cursor = $j['next_cursor'] ?? null;
            if ($cursor === '0' || $cursor === 0 || $cursor === '') {
                $cursor = null;
            }if ($cursor !== null && isset($seen[$cursor])) {
                throw new RuntimeException('X repeated collection cursor.');
            }if ($cursor !== null) {
                $seen[$cursor] = true;
            }if (count($seen) > 1000) {
                throw new RuntimeException('X pagination local cap.');
            }
        } while ($cursor !== null);

        return $out;
    }

    public function discoverAccounts(string $accessToken): array
    {
        $out = [];
        foreach ($this->pages($accessToken, '/accounts') as $a) {
            if (empty($a['id'])) {
                throw new RuntimeException('X account ID missing.');
            }$curr = [];
            foreach ($this->pages($accessToken, '/accounts/'.rawurlencode($a['id']).'/funding_instruments') as $f) {
                if (! empty($f['currency'])) {
                    $curr[] = $f['currency'];
                } else {
                    throw new RuntimeException('X funding instrument currency missing.');
                }
            }
            $curr = array_values(array_unique($curr));
            $out[] = ['provider' => 'x', 'external_account_id' => (string) $a['id'], 'account_name' => $a['name'] ?? $a['id'], 'timezone' => $a['timezone'] ?? null, 'currency' => count($curr) === 1 ? $curr[0] : null, 'reporting_mode' => 'campaign_delivery_only', 'funding_currency_count' => count($curr)];
        }

return $out;
    }

    public function fetchCampaigns(ConnectedAdAccount $a): array
    {
        return ['endpoint' => 'not_requested_delivery_only', 'raw' => [], 'campaigns' => []];
    }

    public function fetchInsights(ConnectedAdAccount $a, string $s, string $e): array
    {
        throw new RuntimeException('X BILLING/ENGAGEMENT delivery is separate. Conversion/revenue import unavailable, not zero.');
    }

    public function deliveryReport(ConnectedAdAccount $a, string $start, string $end): array
    {
        if (! $a->timezone || ! in_array($a->timezone, \DateTimeZone::listIdentifiers(), true) || ! $a->currency) {
            throw new RuntimeException('X verified account timezone and funding currency required.');
        }
        $s = CarbonImmutable::createFromFormat('!Y-m-d', $start, $a->timezone);
        $e = CarbonImmutable::createFromFormat('!Y-m-d', $end, $a->timezone);
        if (! $s || ! $e || $s->toDateString() !== $start || $e->toDateString() !== $end || $s->gt($e) || $s->diffInDays($e) > 6 || $e->gte(CarbonImmutable::now($a->timezone)->startOfDay())) {
            throw new RuntimeException('X synchronous delivery supports1-7 completed local calendar days.');
        }
        // Re-read currencies to reject a funding change after discovery.
        $funding = $this->pages($a->access_token, '/accounts/'.rawurlencode($a->external_account_id).'/funding_instruments');
        $fundingIds = [];
        foreach ($funding as $f) {
            if (($f['currency'] ?? null) !== $a->currency || empty($f['id'])) {
                throw new RuntimeException('X mixed/changed/missing funding currency. Reconnect or split accounts.');
            }$fundingIds[] = $f['id'];
        }
        $campaigns = $this->pages($a->access_token, '/accounts/'.rawurlencode($a->external_account_id).'/campaigns');
        $rows = [];
        foreach ($campaigns as $c) {
            if (empty($c['id']) || ! in_array($c['funding_instrument_id'] ?? null, $fundingIds, true)) {
                throw new RuntimeException('X campaign funding linkage missing or unknown.');
            }
        }
        if (count($campaigns) > 1000) {
            throw new RuntimeException('X local campaign cap reached; use future async backfill.');
        }
        foreach (array_chunk(array_column($campaigns, 'id'), 20) as $ids) {
            $j = $this->api($a->access_token, '/stats/accounts/'.rawurlencode($a->external_account_id), ['entity' => 'CAMPAIGN', 'entity_ids' => implode(',', $ids), 'start_time' => $s->toIso8601String(), 'end_time' => $e->addDay()->toIso8601String(), 'granularity' => 'DAY', 'placement' => 'ALL_ON_TWITTER', 'metric_groups' => 'ENGAGEMENT,BILLING']);
            array_push($rows, ...(new XDeliveryParser)->parse($j,$ids,$start,$end,$a->timezone,$a->currency));
        }

        return ['provider' => 'x', 'status' => 'delivery_only', 'rows' => $rows, 'placement' => 'ALL_ON_TWITTER', 'revenue_optimizer_eligible' => false, 'limits' => ['Source-derived DAY fixtures only, no live daily report tested.', 'Clicks are aggregate engagement clicks, not guaranteed link clicks.', 'Off-platform placements and conversion JSON metrics are not implemented.', 'Billed spend is preliminary and can restate up to14days.']];
    }
}
