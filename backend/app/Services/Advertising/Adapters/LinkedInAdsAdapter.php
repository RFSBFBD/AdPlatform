<?php
namespace App\Services\Advertising\Adapters;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Versioned read adapter, UTC daily account scope. Never tested live. */
class LinkedInAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE='https://api.linkedin.com/rest';
    public function __construct(private ?ProviderDeveloperConfig $config=null) { $this->config??=ProviderDeveloperConfig::where('provider','linkedin')->first(); }
    public function getProviderName(): string { return 'linkedin'; }
    private function configured(): void { if (!$this->config?->is_enabled || !$this->config?->app_id || !$this->config?->app_secret) { throw new RuntimeException('LinkedIn app must be configured and enabled.'); } }
    public function getAuthorizationUrl(string $state,string $redirectUri): string
    {
        $this->configured();return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query(['client_id'=>$this->config->app_id,'response_type'=>'code','state'=>$state,'redirect_uri'=>$redirectUri,'scope'=>'r_ads r_ads_reporting'],'','&',PHP_QUERY_RFC3986);
    }
    public function handleOAuthCallback(string $code,string $redirectUri): array { return $this->token(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri]); }
    public function refreshToken(string $refreshToken): array
    {
        if ($refreshToken==='') { throw new RuntimeException('LinkedIn programmatic refresh is partner-only; reconnect when no refresh token was issued.'); }
        return $this->token(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],$refreshToken);
    }
    private function token(array $body,?string $fallback=null): array
    {
        $this->configured();$r=Http::asForm()->timeout(30)->connectTimeout(10)->post('https://www.linkedin.com/oauth/v2/accessToken',$body+['client_id'=>$this->config->app_id,'client_secret'=>$this->config->app_secret]);
        if ($r->failed() || !$r->json('access_token') || !is_numeric($r->json('expires_in'))) { throw new RuntimeException('LinkedIn token exchange failed (HTTP '.$r->status().').'); }
        return ['access_token'=>$r->json('access_token'),'refresh_token'=>$r->json('refresh_token')??$fallback,'expires_at'=>now()->addSeconds(max(0,(int)$r->json('expires_in')))];
    }
    private function get(string $token,string $path,array $params): array
    {
        $v=$this->config?->api_version;
        if (!is_string($v) || !preg_match('/^20\d{2}(0[1-9]|1[0-2])$/',$v)) { throw new RuntimeException('Choose a currently supported LinkedIn YYYYMM API version in app setup.'); }
        $r=Http::withToken($token)->withHeaders(['Linkedin-Version'=>$v,'X-Restli-Protocol-Version'=>'2.0.0'])->timeout(30)->connectTimeout(10)->get(self::BASE.$path,$params);
        if ($r->status()===401) { throw new \App\Exceptions\OAuthTokenExpiredException('LinkedIn token expired; refresh may require MDP partnership.','linkedin'); }
        if ($r->status()===429) { throw new \App\Exceptions\ProviderRateLimitException('LinkedIn API rate limited.','linkedin'); }
        $j=$r->json();if ($r->failed() || !is_array($j) || !isset($j['elements']) || !is_array($j['elements'])) { throw new RuntimeException('LinkedIn response failed validation (HTTP '.$r->status().').'); }return $j;
    }
    public function discoverAccounts(string $accessToken): array
    {
        $out=[];$token=null;$seen=[];
        do {
            $j=$this->get($accessToken,'/adAccounts',array_filter(['q'=>'search','pageSize'=>1000,'pageToken'=>$token],fn($v)=>$v!==null));
            foreach ($j['elements'] as $a) {
                if (empty($a['id'])) { throw new RuntimeException('LinkedIn account has no id.'); }
                $out[(string)$a['id']]=['provider'=>'linkedin','external_account_id'=>(string)$a['id'],'account_name'=>$a['name']??(string)$a['id'],'currency'=>$a['currency']??null,'timezone'=>'UTC'];
            }
            $token=$j['metadata']['nextPageToken']??null;
            if ($token && isset($seen[$token])) { throw new RuntimeException('LinkedIn repeated page token.'); }if ($token) { $seen[$token]=true; }
            if (count($seen)>1000) { throw new RuntimeException('LinkedIn pagination safety limit reached.'); }
        } while ($token);
        return array_values($out);
    }
    public function fetchCampaigns(ConnectedAdAccount $account): array { return ['endpoint'=>'not_requested_account_level_reporting','raw'=>[],'campaigns'=>[]]; }
    public function fetchInsights(ConnectedAdAccount $account,string $startDate,string $endDate): array
    {
        // The existing canonical optimizer treats conversion_value as revenue. Do not silently
        // admit LEAD/DOWNLOAD values into it. Require a documented deployment-level verification.
        if (($this->config?->extra_settings['conversion_values_verified_as_revenue']??false)!==true) { throw new RuntimeException('LinkedIn conversion values are advertiser-defined desired-action values, not necessarily sales. Revenue mapping is disabled until conversion rules and values are verified in deployment configuration.'); }
        if (!$account->currency) { throw new RuntimeException('LinkedIn account currency is required.'); }
        $s=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$startDate,'UTC');$e=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$endDate,'UTC');
        if (!$s || !$e || $s->format('Y-m-d')!==$startDate || $e->format('Y-m-d')!==$endDate || $s->gt($e) || $s->lt(now('UTC')->subMonths(6)->startOfDay()) || $e->gt(now('UTC')->startOfDay())) { throw new RuntimeException('LinkedIn daily range must remain within six-month daily retention.'); }
        $date=fn($d)=>'(year:'.$d->year.',month:'.$d->month.',day:'.$d->day.')';
        $j=$this->get($account->access_token,'/adAnalytics',['q'=>'analytics','pivot'=>'ACCOUNT','timeGranularity'=>'DAILY','dateRange'=>'(start:'.$date($s).',end:'.$date($e).')','accounts'=>'List(urn:li:sponsoredAccount:'.$account->external_account_id.')','fields'=>'dateRange,pivotValues,impressions,clicks,costInLocalCurrency,externalWebsiteConversions,conversionValueInLocalCurrency']);
        if (count($j['elements'])>=15000) { throw new RuntimeException('LinkedIn reporting cap reached; no truncated import.'); }
        $metrics=[];$dates=[];
        foreach ($j['elements'] as $r) {
            foreach (['impressions','clicks','costInLocalCurrency','externalWebsiteConversions','conversionValueInLocalCurrency'] as $k) { if (!isset($r[$k]) || !is_numeric($r[$k]) || !is_finite((float)$r[$k]) || $r[$k]<0) { throw new RuntimeException('LinkedIn required metric missing or invalid.'); } }
            $a=$r['dateRange']['start']??[];$b=$r['dateRange']['end']??[];
            if (!isset($a['year'],$a['month'],$a['day']) || $a!=$b || !checkdate((int)$a['month'],(int)$a['day'],(int)$a['year'])) { throw new RuntimeException('LinkedIn report did not preserve daily dates.'); }
            $d=sprintf('%04d-%02d-%02d',$a['year'],$a['month'],$a['day']);
            if ($d<$startDate || $d>$endDate || isset($dates[$d]) || ($r['pivotValues']??[])!==['urn:li:sponsoredAccount:'.$account->external_account_id]) { throw new RuntimeException('LinkedIn day/account mismatch or duplicate.'); }$dates[$d]=true;
            $metrics[]=['external_campaign_id'=>null,'external_ad_set_id'=>null,'external_ad_id'=>null,'date'=>$d,'spend'=>\App\Services\Advertising\MetricOperand::read($r,'costInLocalCurrency'),'impressions'=>\App\Services\Advertising\MetricOperand::read($r,'impressions',true),'clicks'=>\App\Services\Advertising\MetricOperand::read($r,'clicks',true),'conversions'=>\App\Services\Advertising\MetricOperand::read($r,'externalWebsiteConversions'),'conversion_value'=>\App\Services\Advertising\MetricOperand::read($r,'conversionValueInLocalCurrency'),'metric_definition'=>['conversion'=>'advertiser_desired_actions_click_or_view_not_unique_purchases','value'=>'attributed_conversion_value_verified_by_deployment','reporting_timezone'=>'UTC','api_version'=>$this->config->api_version]];
        }
        return ['endpoint'=>'/adAnalytics','raw'=>[$j],'metrics'=>$metrics];
    }
}
