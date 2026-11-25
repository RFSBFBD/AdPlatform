<?php
namespace App\Services\Advertising\Adapters;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Official v3 contract, account/day purchase reporting. Fixture-tested only. */
class RedditAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE = 'https://ads-api.reddit.com/api/v3';
    private const UA = 'AdPlatform/1.0 read-only advertising analytics';
    public function __construct(private ?ProviderDeveloperConfig $config = null) { $this->config ??= ProviderDeveloperConfig::where('provider','reddit')->first(); }
    public function getProviderName(): string { return 'reddit'; }
    private function configured(): void { if (!$this->config?->is_enabled || !$this->config?->app_id || !$this->config?->app_secret) { throw new RuntimeException('Reddit app must be configured and enabled.'); } }
    public function getAuthorizationUrl(string $state,string $redirectUri): string
    {
        $this->configured();
        return 'https://www.reddit.com/api/v1/authorize?'.http_build_query(['client_id'=>$this->config->app_id,'response_type'=>'code','state'=>$state,'redirect_uri'=>$redirectUri,'duration'=>'permanent','scope'=>'adsread'],'','&',PHP_QUERY_RFC3986);
    }
    public function handleOAuthCallback(string $code,string $redirectUri): array { return $this->token(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri]); }
    public function refreshToken(string $refreshToken): array { return $this->token(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],$refreshToken); }
    private function token(array $body,?string $fallback=null): array
    {
        $this->configured();
        $r=Http::withBasicAuth($this->config->app_id,$this->config->app_secret)->withHeaders(['User-Agent'=>self::UA])->asForm()->timeout(30)->connectTimeout(10)->post('https://www.reddit.com/api/v1/access_token',$body);
        if ($r->failed() || !$r->json('access_token') || !is_numeric($r->json('expires_in'))) { throw new RuntimeException('Reddit token exchange failed (HTTP '.$r->status().').'); }
        return ['access_token'=>$r->json('access_token'),'refresh_token'=>$r->json('refresh_token')??$fallback,'expires_at'=>now()->addSeconds(max(0,(int)$r->json('expires_in')))];
    }
    private array $lastBodies=[];
    private function pages(string $token,string $path,?array $body=null): array
    {
        $url=self::BASE.$path; $out=[]; $seen=[]; $this->lastBodies=[];
        do {
            $u=parse_url($url);
            if (($u['scheme']??'')!=='https' || ($u['host']??'')!=='ads-api.reddit.com' || ($u['path']??'')!=='/api/v3'.$path || isset($u['user']) || isset($u['port']) || isset($seen[$url])) { throw new RuntimeException('Reddit unsafe or repeated pagination URL.'); }
            $seen[$url]=true;
            $h=Http::withToken($token)->withHeaders(['User-Agent'=>self::UA])->timeout(30)->connectTimeout(10)->withoutRedirecting();
            $r=$body===null?$h->get($url):$h->post($url,$body);
            if ($r->status()===401) { throw new \App\Exceptions\OAuthTokenExpiredException('Reddit token expired.','reddit'); }
            if ($r->status()===429) { throw new \App\Exceptions\ProviderRateLimitException('Reddit reporting rate limited.','reddit'); }
            $j=$r->json();
            if ($r->failed() || $r->redirect() || !is_array($j) || !isset($j['data']) || !is_array($j['data'])) { throw new RuntimeException('Reddit response failed validation (HTTP '.$r->status().').'); }
            $this->lastBodies[]=['body'=>$r->body(),'status'=>$r->status()];
            $out[]=$j; $url=$j['pagination']['next_url']??null;
            if (count($seen)>1000) { throw new RuntimeException('Reddit pagination safety limit reached.'); }
        } while ($url);
        return $out;
    }
    public function discoverAccounts(string $accessToken): array
    {
        $out=[];
        foreach ($this->pages($accessToken,'/me/businesses') as $p) {
            foreach ($p['data'] as $b) {
                if (empty($b['id'])) { throw new RuntimeException('Reddit business has no id.'); }
                foreach ($this->pages($accessToken,'/businesses/'.rawurlencode($b['id']).'/ad_accounts') as $j) {
                    foreach ($j['data'] as $a) {
                        if (empty($a['id'])) { throw new RuntimeException('Reddit account has no id.'); }
                        $out[$a['id']]=['provider'=>'reddit','external_account_id'=>$a['id'],'account_name'=>$a['name']??$a['id'],'currency'=>$a['currency']??null,'timezone'=>$a['time_zone_id']??null];
                    }
                }
            }
        }
        return array_values($out);
    }
    public function fetchCampaigns(ConnectedAdAccount $account): array { return ['endpoint'=>'not_requested_account_level_reporting','raw'=>[],'campaigns'=>[]]; }
    public function fetchInsights(ConnectedAdAccount $account,string $startDate,string $endDate): array
    {
        if (!$account->currency) { throw new RuntimeException('Reddit account currency is required.'); }
        // Use explicit UTC reporting, and preserve that choice in every metric lineage.
        $s=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$startDate,'UTC');$e=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$endDate,'UTC');
        if (!$s || !$e || $s->format('Y-m-d')!==$startDate || $e->format('Y-m-d')!==$endDate || $s->gt($e) || $s->lt(now('UTC')->subMonths(24)->startOfDay()) || $e->gt(now('UTC')->startOfDay())) { throw new RuntimeException('Invalid Reddit daily range or outside 24-month delivery history.'); }
        $path='/ad_accounts/'.rawurlencode($account->external_account_id).'/reports';
        $fields=\App\Services\Advertising\Sync\RedditNativeRecordedMetrics::FIELDS;
        $body=['data'=>['starts_at'=>$s->format('Y-m-d\TH:00:00\Z'),'ends_at'=>$e->addDay()->format('Y-m-d\TH:00:00\Z'),'fields'=>$fields,'breakdowns'=>['DATE'],'time_zone_id'=>'UTC']];
        $pages=$this->pages($account->access_token,$path,$body);$metrics=[];$dates=[];
        $recorded=[];$bytes=0;
        foreach($this->lastBodies as $n=>$lb){$bytes+=strlen($lb['body']);if(strlen($lb['body'])>8*1024*1024||$bytes>16*1024*1024)throw new RuntimeException('Reddit original reporting pages exceed evidence capture limit; no partial import.');
            $recorded[]=['body'=>$lb['body'],'sha256'=>hash('sha256',$lb['body']),'request'=>['endpoint'=>$path,'api_version'=>'v3','body'=>$body,'page_index'=>$n],'account_external_id'=>$account->external_account_id,
                'currency'=>$account->currency,'start_date'=>$startDate,'end_date'=>$endDate,'retrieved_at'=>now()->toIso8601String(),'status'=>$lb['status'],'precision_state'=>'original_body_retained_not_yet_qualified'];}
        foreach ($pages as $p) {
            if (!isset($p['data']['metrics']) || !is_array($p['data']['metrics'])) { throw new RuntimeException('Reddit report lacks data.metrics.'); }
            foreach ($p['data']['metrics'] as $r) {
                foreach (['spend','impressions','clicks','conversion_purchase_clicks','conversion_purchase_views','conversion_purchase_total_value'] as $k) {
                    if (!isset($r[$k]) || !is_numeric($r[$k]) || !is_finite((float)$r[$k]) || $r[$k]<0) { throw new RuntimeException('Reddit report contains missing, suppressed or invalid metrics.'); }
                }
                $d=$r['date']??'';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d) || $d<$startDate || $d>$endDate || isset($dates[$d]) || ($r['currency']??null)!==$account->currency) { throw new RuntimeException('Reddit date, duplicate day or currency mismatch.'); }
                $dates[$d]=true;
                $metrics[]=['external_campaign_id'=>null,'external_ad_set_id'=>null,'external_ad_id'=>null,'date'=>$d,
                    'spend'=>\App\Services\Advertising\MetricOperand::read($r,'spend',false,1000000),'impressions'=>\App\Services\Advertising\MetricOperand::read($r,'impressions',true),'clicks'=>\App\Services\Advertising\MetricOperand::read($r,'clicks',true),
                    'conversions'=>\App\Services\Advertising\MetricOperand::read($r,'conversion_purchase_clicks')+\App\Services\Advertising\MetricOperand::read($r,'conversion_purchase_views'),'conversion_value'=>\App\Services\Advertising\MetricOperand::read($r,'conversion_purchase_total_value',false,100),
                    'metric_definition'=>['conversion'=>'purchase_click_plus_view','reporting_timezone'=>'UTC','attribution'=>'account_configured','spend_unit'=>'microcurrency','conversion_value_unit'=>'hundredths_of_currency']];
            }
        }
        return ['endpoint'=>$path,'raw'=>$pages,'metrics'=>$metrics,'recorded_response_pages'=>$recorded];
    }
}
