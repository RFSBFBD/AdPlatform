<?php
namespace App\Services\Advertising\Adapters;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only API v5. Contract/fixture verified, not verified against a live account. */
class PinterestAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE = 'https://api.pinterest.com/v5';
    public function __construct(private ?ProviderDeveloperConfig $config = null)
    {
        $this->config ??= ProviderDeveloperConfig::where('provider', 'pinterest')->first();
    }
    public function getProviderName(): string { return 'pinterest'; }
    private function configured(): void
    {
        if (!$this->config?->is_enabled || !$this->config?->app_id || !$this->config?->app_secret) {
            throw new RuntimeException('Pinterest app must be configured and enabled.');
        }
    }
    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        $this->configured();
        return 'https://www.pinterest.com/oauth/?'.http_build_query(['client_id' => $this->config->app_id,
            'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => 'ads:read', 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }
    public function handleOAuthCallback(string $code, string $redirectUri): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }
    public function refreshToken(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $refreshToken);
    }
    private function token(array $body, ?string $fallback = null): array
    {
        $this->configured();
        $body['continuous_refresh'] = 'true';
        $r = Http::withBasicAuth($this->config->app_id, $this->config->app_secret)->asForm()->timeout(30)->connectTimeout(10)->post(self::BASE.'/oauth/token', $body);
        if ($r->failed() || !$r->json('access_token') || !is_numeric($r->json('expires_in'))) {
            throw new RuntimeException('Pinterest token exchange failed (HTTP '.$r->status().').');
        }
        return ['access_token' => $r->json('access_token'), 'refresh_token' => $r->json('refresh_token') ?? $fallback,
            'expires_at' => now()->addSeconds(max(0, (int) $r->json('expires_in')))];
    }
    public function discoverAccounts(string $accessToken): array
    {
        $accounts = []; $bookmark = null; $seen = [];
        do {
            $j = $this->get($accessToken, '/ad_accounts', array_filter(['page_size' => 250, 'bookmark' => $bookmark], fn($v) => $v !== null));
            if (!isset($j['items']) || !is_array($j['items'])) { throw new RuntimeException('Pinterest account response lacks items.'); }
            foreach ($j['items'] as $a) {
                if (empty($a['id'])) { throw new RuntimeException('Pinterest account lacks id.'); }
                $accounts[(string) $a['id']] = ['provider' => 'pinterest', 'external_account_id' => (string) $a['id'],
                    'account_name' => $a['name'] ?? (string) $a['id'], 'currency' => $a['currency'] ?? null, 'timezone' => $a['time_zone'] ?? null];
            }
            $bookmark = $j['bookmark'] ?? null;
            if ($bookmark && isset($seen[$bookmark])) { throw new RuntimeException('Pinterest repeated pagination bookmark.'); }
            if ($bookmark) { $seen[$bookmark] = true; }
            if (count($seen) > 1000) { throw new RuntimeException('Pinterest pagination safety limit reached.'); }
        } while ($bookmark);
        return array_values($accounts);
    }
    public function fetchCampaigns(ConnectedAdAccount $account): array
    {
        return ['endpoint' => 'not_requested_account_level_reporting', 'raw' => [], 'campaigns' => []];
    }
    public function fetchInsights(ConnectedAdAccount $account, string $startDate, string $endDate): array
    {
        // Official glossary: micro-dollar spend represents advertiser microcurrency.
        if (!$account->currency) { throw new RuntimeException('Pinterest advertiser currency is required.'); }
        if (!$account->timezone || !in_array($account->timezone, \DateTimeZone::listIdentifiers(), true)) { throw new RuntimeException('Pinterest account timezone is required for account-day reporting.'); }
        $start = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $startDate, 'UTC');
        $end = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'UTC');
        $today = now('UTC')->startOfDay();
        if (!$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate || $start->gt($end) || $end->gt($today)) {
            throw new RuntimeException('Pinterest daily reports require a valid date range that does not end in the future.');
        }
        if ($start->lt($today->subDays(90)) || $start->diffInDays($end) >= 90) {
            return $this->fetchInsightsAsync($account, $start, $end);
        }
        $columns = self::COLUMNS;
        $path = '/ad_accounts/'.rawurlencode($account->external_account_id).'/analytics';
        $query = ['start_date' => $startDate, 'end_date' => $endDate,
            'columns' => implode(',', $columns), 'granularity' => 'DAY', 'click_window_days' => 30,
            'engagement_window_days' => 30, 'view_window_days' => 1, 'conversion_report_time' => 'TIME_OF_AD_ACTION', 'reporting_timezone' => 'AD_ACCOUNT_TIME_ZONE'];
        [$j, $response] = $this->getWithResponse($account->access_token, $path, $query);
        $body = $response->body();
        if (strlen($body) > 8 * 1024 * 1024) { throw new RuntimeException('Pinterest original reporting page exceeds evidence capture limit; no partial import.'); }
        $recordedPages = [['body' => $body, 'sha256' => hash('sha256', $body),
            'request' => ['endpoint' => $path, 'api_version' => 'v5'] + $query, 'account_external_id' => $account->external_account_id,
            'currency' => $account->currency, 'timezone' => $account->timezone, 'start_date' => $startDate, 'end_date' => $endDate,
            'retrieved_at' => now()->toIso8601String(), 'status' => $response->status(), 'precision_state' => 'original_body_retained_not_yet_qualified']];
        if (!array_is_list($j)) { throw new RuntimeException('Pinterest report response must be a row array.'); }
        $dates = [];
        $metrics = $this->normalizeRows($j, $account, $startDate, $endDate, $dates);
        return ['endpoint' => $path, 'raw' => [$j], 'metrics' => $metrics, 'recorded_response_pages' => $recordedPages];
    }
    /** Columns shared by the synchronous and the asynchronous account-day report. */
    private const COLUMNS = ['SPEND_IN_MICRO_DOLLAR','PAID_IMPRESSION','OUTBOUND_CLICK_1','TOTAL_CHECKOUT','TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR'];
    /** Status values seen in the async report flow. Anything else is refused. */
    private const ASYNC_FAILED = ['DOES_NOT_EXIST', 'EXPIRED', 'FAILED', 'CANCELLED'];
    private const ASYNC_MAX_RANGE_DAYS = 180;
    private const ASYNC_MAX_LOOKBACK_DAYS = 914;
    private const ASYNC_MAX_POLLS = 30;
    private const ASYNC_MAX_BYTES = 8 * 1024 * 1024;
    /** @var null|callable(int):void Seconds to wait between polls. Replaceable in tests. */
    private $pollWait = null;
    public function setPollWait(callable $wait): void { $this->pollWait = $wait; }

    /**
     * Long-history account/day report through the async flow: POST /ad_accounts/{id}/reports,
     * poll GET /ad_accounts/{id}/reports?token=, then download the pre-signed url.
     * Official contract: granularity other than HOUR allows up to 914 days back and a 186-day range.
     * We use 180-day chunks. Any failed, expired or malformed chunk refuses the whole import: no partial data.
     * Fixture and contract verified only. The downloaded file shape is taken from a third-party connector, not an official page.
     */
    private function fetchInsightsAsync(ConnectedAdAccount $account, \Carbon\CarbonImmutable $start, \Carbon\CarbonImmutable $end): array
    {
        $today = now('UTC')->startOfDay();
        if ($start->lt($today->subDays(self::ASYNC_MAX_LOOKBACK_DAYS))) {
            throw new RuntimeException('Pinterest daily reports reach back at most '.self::ASYNC_MAX_LOOKBACK_DAYS.' days.');
        }
        $path = '/ad_accounts/'.rawurlencode($account->external_account_id).'/reports';
        $metrics = []; $raw = []; $pages = []; $dates = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDays(self::ASYNC_MAX_RANGE_DAYS)) {
            $chunkEnd = $cursor->addDays(self::ASYNC_MAX_RANGE_DAYS - 1);
            if ($chunkEnd->gt($end)) { $chunkEnd = $end; }
            $from = $cursor->format('Y-m-d'); $to = $chunkEnd->format('Y-m-d');
            $body = ['start_date' => $from, 'end_date' => $to, 'level' => 'ADVERTISER', 'granularity' => 'DAY',
                'columns' => array_merge(['AD_ACCOUNT_ID'], self::COLUMNS), 'click_window_days' => 30,
                'engagement_window_days' => 30, 'view_window_days' => 1, 'conversion_report_time' => 'TIME_OF_AD_ACTION',
                'reporting_timezone' => 'AD_ACCOUNT_TIME_ZONE', 'report_format' => 'JSON'];
            $created = $this->send($account->access_token, 'post', $path, $body);
            $token = $created['token'] ?? null;
            if (!is_string($token) || $token === '') { throw new RuntimeException('Pinterest async report was not created (no token).'); }
            $url = $this->waitForReportUrl($account->access_token, $path, $token);
            $download = Http::timeout(60)->connectTimeout(10)->get($url);
            $text = $download->body();
            if ($download->failed()) { throw new RuntimeException('Pinterest report download failed (HTTP '.$download->status().').'); }
            if (strlen($text) > self::ASYNC_MAX_BYTES) { throw new RuntimeException('Pinterest async report exceeds evidence capture limit; no partial import.'); }
            $json = json_decode($text, true);
            if (!is_array($json)) { throw new RuntimeException('Pinterest async report is not valid JSON.'); }
            $rows = [];
            foreach ($json as $group) {
                if (!is_array($group) || !array_is_list($group)) { throw new RuntimeException('Pinterest async report has an unexpected shape; import refused.'); }
                foreach ($group as $r) { if (!is_array($r)) { throw new RuntimeException('Pinterest async report has an unexpected row; import refused.'); } $rows[] = $r; }
            }
            $metrics = array_merge($metrics, $this->normalizeRows($rows, $account, $from, $to, $dates));
            $raw[] = $json;
            $pages[] = ['body' => $text, 'sha256' => hash('sha256', $text),
                'request' => ['endpoint' => $path, 'api_version' => 'v5', 'mode' => 'async'] + $body,
                'account_external_id' => $account->external_account_id, 'currency' => $account->currency, 'timezone' => $account->timezone,
                'start_date' => $from, 'end_date' => $to, 'retrieved_at' => now()->toIso8601String(), 'status' => $download->status(),
                'precision_state' => 'original_body_retained_not_yet_qualified'];
        }
        return ['endpoint' => $path, 'raw' => $raw, 'metrics' => $metrics, 'recorded_response_pages' => $pages];
    }

    private function waitForReportUrl(string $token, string $path, string $reportToken): string
    {
        for ($i = 0; $i < self::ASYNC_MAX_POLLS; $i++) {
            $j = $this->send($token, 'get', $path, ['token' => $reportToken]);
            $status = $j['report_status'] ?? null;
            if ($status === 'FINISHED') {
                $url = $j['url'] ?? null;
                $p = is_string($url) ? parse_url($url) : false;
                $host = is_array($p) ? strtolower((string) ($p['host'] ?? '')) : '';
                if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || $host === '' || isset($p['user']) || isset($p['pass'])
                    || (isset($p['port']) && (int) $p['port'] !== 443) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
                    || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                    throw new RuntimeException('Pinterest report download url is not an acceptable public https address.');
                }
                return $url;
            }
            if (in_array($status, self::ASYNC_FAILED, true)) { throw new RuntimeException('Pinterest async report ended as '.$status.'; import refused.'); }
            if ($status !== 'IN_PROGRESS') { throw new RuntimeException('Pinterest async report returned an unknown status; import refused.'); }
            ($this->pollWait ?? static fn (int $s) => sleep($s))(3);
        }
        throw new RuntimeException('Pinterest async report did not finish in time; import refused.');
    }

    /** @param list<array<string,mixed>> $rows */
    private function normalizeRows(array $rows, ConnectedAdAccount $account, string $startDate, string $endDate, array &$dates): array
    {
        $columns = self::COLUMNS; $metrics = [];
        foreach ($rows as $r) {
            foreach ($columns as $key) {
                if (!isset($r[$key]) || !is_numeric($r[$key]) || !is_finite((float)$r[$key]) || $r[$key] < 0) {
                    throw new RuntimeException('Pinterest report contains missing, suppressed or invalid metrics; import refused.');
                }
            }
            $d = $r['DATE'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < $startDate || $d > $endDate || isset($dates[$d]) || (string)($r['AD_ACCOUNT_ID'] ?? '') !== $account->external_account_id) {
                throw new RuntimeException('Pinterest report contains an invalid date, duplicate day or different account.');
            }
            $dates[$d] = true;
            $metrics[] = ['external_campaign_id' => null, 'external_ad_set_id' => null, 'external_ad_id' => null,
                'date' => $d, 'spend' => \App\Services\Advertising\MetricOperand::read($r,'SPEND_IN_MICRO_DOLLAR',false,1000000), 'impressions' => \App\Services\Advertising\MetricOperand::read($r,'PAID_IMPRESSION',true),
                'clicks' => \App\Services\Advertising\MetricOperand::read($r,'OUTBOUND_CLICK_1',true), 'conversions' => \App\Services\Advertising\MetricOperand::read($r,'TOTAL_CHECKOUT'),
                'conversion_value' => \App\Services\Advertising\MetricOperand::read($r,'TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR',false,1000000),
                'metric_definition' => ['conversion' => 'checkout', 'clicks' => 'outbound_clicks', 'click_window_days' => 30, 'engagement_window_days' => 30, 'view_window_days' => 1, 'conversion_report_time' => 'TIME_OF_AD_ACTION', 'reporting_timezone' => $account->timezone]];
        }
        return $metrics;
    }

    private function send(string $token, string $method, string $path, array $params): array
    {
        $req = Http::withToken($token)->timeout(30)->connectTimeout(10);
        $r = $method === 'post' ? $req->asJson()->post(self::BASE.$path, $params) : $req->get(self::BASE.$path, $params);
        if ($r->status() === 401) { throw new \App\Exceptions\OAuthTokenExpiredException('Pinterest token expired.', 'pinterest'); }
        if ($r->status() === 429) { throw new \App\Exceptions\ProviderRateLimitException('Pinterest API rate limited the request.', 'pinterest'); }
        $j = $r->json();
        if ($r->failed() || !is_array($j)) { throw new RuntimeException('Pinterest response failed (HTTP '.$r->status().').'); }
        return $j;
    }
    private function get(string $token, string $path, array $params): array
    {
        return $this->getWithResponse($token, $path, $params)[0];
    }
    private function getWithResponse(string $token, string $path, array $params): array
    {
        $r = Http::withToken($token)->timeout(30)->connectTimeout(10)->get(self::BASE.$path, $params);
        if ($r->status() === 401) { throw new \App\Exceptions\OAuthTokenExpiredException('Pinterest token expired.', 'pinterest'); }
        if ($r->status() === 429) { throw new \App\Exceptions\ProviderRateLimitException('Pinterest API rate limited the request.', 'pinterest'); }
        $j = $r->json();
        if ($r->failed() || !is_array($j)) { throw new RuntimeException('Pinterest response failed (HTTP '.$r->status().').'); }
        return [$j, $r];
    }
}
