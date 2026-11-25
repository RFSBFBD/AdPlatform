<?php
namespace App\Services\Advertising\Adapters;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Official Spotify v3 contract, account/day purchase reporting. Fixture-tested only. */
class SpotifyAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE = 'https://api-partner.spotify.com/ads/v3';
    private const UA = 'AdPlatform/1.0 read-only advertising analytics';
    public function __construct(private ?ProviderDeveloperConfig $config = null) { $this->config ??= ProviderDeveloperConfig::where('provider','spotify')->first(); }
    public function getProviderName(): string { return 'spotify'; }
    private function configured(): void { if (!$this->config?->is_enabled || !$this->config?->app_id || !$this->config?->app_secret) { throw new RuntimeException('Spotify app must be configured and enabled.'); } }
    public function getAuthorizationUrl(string $state,string $redirectUri): string
    {
        $this->configured();
        return 'https://accounts.spotify.com/authorize/?'.http_build_query(['client_id'=>$this->config->app_id,'response_type'=>'code','state'=>$state,'redirect_uri'=>$redirectUri,'show_dialog'=>'false'],'','&',PHP_QUERY_RFC3986);
    }
    public function handleOAuthCallback(string $code,string $redirectUri): array { return $this->token(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri]); }
    public function refreshToken(string $refreshToken): array { return $this->token(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],$refreshToken); }
    private function token(array $body,?string $fallback=null): array
    {
        $this->configured();
        $r=Http::withBasicAuth($this->config->app_id,$this->config->app_secret)->withHeaders(['User-Agent'=>self::UA])->asForm()->timeout(30)->connectTimeout(10)->post('https://accounts.spotify.com/api/token',$body);
        if ($r->failed() || !$r->json('access_token') || !is_numeric($r->json('expires_in'))) { throw new RuntimeException('Spotify token exchange failed (HTTP '.$r->status().').'); }
        return ['access_token'=>$r->json('access_token'),'refresh_token'=>$r->json('refresh_token')??$fallback,'expires_at'=>now()->addSeconds(max(0,(int)$r->json('expires_in')))];
    }
    private ?string $lastBody=null; private ?int $lastStatus=null;
    private function get(string $token,string $path,?array $params=null): array
    {
        $h=Http::withToken($token)->timeout(30)->connectTimeout(10);
        $r=$params===null?$h->get(self::BASE.$path):$h->get(self::BASE.$path,$params);
        if ($r->status()===401) { throw new \App\Exceptions\OAuthTokenExpiredException('Spotify token expired.','spotify'); }
        if ($r->status()===429) { throw new \App\Exceptions\ProviderRateLimitException('Spotify API rate limited.','spotify'); }
        $this->lastBody=$r->body();$this->lastStatus=$r->status();
        $j=$r->json();if ($r->failed() || !is_array($j)) { throw new RuntimeException('Spotify response failed validation (HTTP '.$r->status().').'); }return $j;
    }
    public function discoverAccounts(string $accessToken): array
    {
        $j=$this->get($accessToken,'/businesses');if (!isset($j['businesses']) || !is_array($j['businesses'])) { throw new RuntimeException('Spotify response lacks businesses.'); }
        $out=[];
        foreach ($j['businesses'] as $b) {
            if (empty($b['id'])) { throw new RuntimeException('Spotify business has no id.'); }
            $a=$this->get($accessToken,'/businesses/'.rawurlencode($b['id']).'/ad_accounts');
            if (!isset($a['ad_accounts']) || !is_array($a['ad_accounts'])) { throw new RuntimeException('Spotify response lacks ad_accounts.'); }
            foreach ($a['ad_accounts'] as $r) {
                if (empty($r['id'])) { throw new RuntimeException('Spotify account has no id.'); }
                $out[$r['id']]=['provider'=>'spotify','external_account_id'=>$r['id'],'account_name'=>$r['name']??$r['id'],'currency'=>$r['currency_code']??null,'timezone'=>'UTC'];
            }
        }
        return array_values($out);
    }
    public function fetchCampaigns(ConnectedAdAccount $account): array { return ['endpoint'=>'not_requested_account_level_reporting','raw'=>[],'campaigns'=>[]]; }
    public function fetchInsights(ConnectedAdAccount $account,string $startDate,string $endDate): array
    {
        if (!$account->currency) { throw new RuntimeException('Spotify billing currency must be known.'); }
        $s=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$startDate,'UTC');$e=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$endDate,'UTC');
        if (!$s || !$e || $s->format('Y-m-d')!==$startDate || $e->format('Y-m-d')!==$endDate || $s->gt($e) || $s->diffInDays($e)>=90 || $e->gt(now('UTC')->startOfDay())) { throw new RuntimeException('Invalid Spotify daily range; at most 90 days supported.'); }
        $path='/ad_accounts/'.rawurlencode($account->external_account_id).'/aggregate_reports';
        // fields are repeated query keys, not comma-delimited and not PHP fields[0] keys.
        $query='entity_type=AD_ACCOUNT&granularity=DAY&report_start='.rawurlencode($s->format('Y-m-d\TH:00:00\Z')).'&report_end='.rawurlencode($e->format('Y-m-d\TH:00:00\Z')).'&limit=50';
        $required=\App\Services\Advertising\Sync\SpotifyNativeRecordedMetrics::FIELDS;foreach ($required as $f) { $query.='&fields='.$f; }
        $pages=[];$metrics=[];$seen=[];$dates=[];$recorded=[];$bytes=0;
        $shape=['endpoint'=>$path,'api_version'=>'v3','entity_type'=>'AD_ACCOUNT','granularity'=>'DAY','fields'=>$required,'report_start'=>$s->format('Y-m-d\TH:00:00\Z'),'report_end'=>$e->format('Y-m-d\TH:00:00\Z'),'limit'=>50];
        do {
            $j=$this->get($account->access_token,$path.'?'.$query);$pages[]=$j;
            $bytes+=strlen((string)$this->lastBody);if(strlen((string)$this->lastBody)>8*1024*1024||$bytes>16*1024*1024)throw new RuntimeException('Spotify original reporting pages exceed evidence capture limit; no partial import.');
            $recorded[]=['body'=>$this->lastBody,'sha256'=>hash('sha256',(string)$this->lastBody),'request'=>$shape+['page_index'=>count($recorded),'continuation_used'=>str_starts_with($query,'continuation_token=')],'account_external_id'=>$account->external_account_id,'currency'=>$account->currency,'start_date'=>$startDate,'end_date'=>$endDate,'retrieved_at'=>now()->toIso8601String(),'status'=>$this->lastStatus,'precision_state'=>'original_body_retained_not_yet_qualified'];
            if (!isset($j['rows']) || !is_array($j['rows'])) { throw new RuntimeException('Spotify report lacks rows.'); }
            if (!empty($j['warnings'])) { throw new RuntimeException('Spotify report returned warnings; review raw contract before import.'); }
            foreach ($j['rows'] as $r) {
                $v=[];
                foreach ($r['stats']??[] as $st) {
                    $k=$st['field_type']??'';
                    if (array_key_exists($k,$v)) { throw new RuntimeException('Spotify duplicate metric.'); }
                    $v[$k]=$st['field_value']??null;
                }
                foreach ($required as $k) {
                    if (!isset($v[$k]) || !is_numeric($v[$k]) || !is_finite((float)$v[$k]) || $v[$k]<0) { throw new RuntimeException('Spotify missing or suppressed metric (including privacy sentinel -5); no partial report imported.'); }
                }
                $start=$r['start_time']??'';$d=substr($start,0,10);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00Z$/',$start) || $d<$startDate || $d>$endDate || isset($dates[$d]) || ($r['entity_type']??null)!=='AD_ACCOUNT' || ($r['entity_id']??null)!==$account->external_account_id) { throw new RuntimeException('Spotify invalid day, duplicate or account mismatch.'); }
                $dates[$d]=true;
                $metrics[]=['external_campaign_id'=>null,'external_ad_set_id'=>null,'external_ad_id'=>null,'date'=>$d,'spend'=>\App\Services\Advertising\MetricOperand::read($v,'SPEND'),'impressions'=>\App\Services\Advertising\MetricOperand::read($v,'IMPRESSIONS',true),'clicks'=>\App\Services\Advertising\MetricOperand::read($v,'CLICKS',true),'conversions'=>\App\Services\Advertising\MetricOperand::read($v,'PURCHASES'),'conversion_value'=>\App\Services\Advertising\MetricOperand::read($v,'REVENUE'),
                    'metric_definition'=>['conversion'=>'purchases','reporting_timezone'=>'UTC','spend_unit'=>'billing_currency','attribution'=>'provider_reported_not_deduplicated']];
            }
            $token=$j['continuation_token']??null;
            if ($token && isset($seen[$token])) { throw new RuntimeException('Spotify repeated continuation token.'); }
            if ($token) { $seen[$token]=true; }if (count($seen)>1000) { throw new RuntimeException('Spotify pagination safety limit reached.'); }
            // Provider ignores other parameters on subsequent pages, send only token.
            $query='continuation_token='.rawurlencode($token??'');
        } while ($token);
        return ['endpoint'=>$path,'raw'=>$pages,'metrics'=>$metrics,'recorded_response_pages'=>$recorded];
    }
}
