<?php
namespace App\Services\Advertising\Adapters;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Versioned SP read adapter, profile-local daily scope. Never tested live. */
class AmazonAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE='https://advertising-api.amazon.com';
    public function __construct(private ?ProviderDeveloperConfig $config=null) { $this->config??=ProviderDeveloperConfig::where('provider','amazon')->first(); }
    public function getProviderName(): string { return 'amazon'; }
    private function configured(): void { if (!$this->config?->is_enabled || !$this->config?->app_id || !$this->config?->app_secret) { throw new RuntimeException('Amazon app must be configured and enabled.'); } }
    public function getAuthorizationUrl(string $state,string $redirectUri): string
    {
        $this->configured();return 'https://www.amazon.com/ap/oa?'.http_build_query(['client_id'=>$this->config->app_id,'response_type'=>'code','state'=>$state,'redirect_uri'=>$redirectUri,'scope'=>'advertising::campaign_management'],'','&',PHP_QUERY_RFC3986);
    }
    public function handleOAuthCallback(string $code,string $redirectUri): array { return $this->token(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri]); }
    public function refreshToken(string $refreshToken): array
    {
        if ($refreshToken==='') { throw new RuntimeException('Amazon refresh token missing; reconnect.'); }
        return $this->token(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],$refreshToken);
    }
    private function token(array $body,?string $fallback=null): array
    {
        $this->configured();$r=Http::asForm()->timeout(30)->connectTimeout(10)->post('https://api.amazon.com/auth/o2/token',$body+['client_id'=>$this->config->app_id,'client_secret'=>$this->config->app_secret]);
        if ($r->failed() || !$r->json('access_token') || !is_numeric($r->json('expires_in'))) { throw new RuntimeException('Amazon token exchange failed (HTTP '.$r->status().').'); }
        return ['access_token'=>$r->json('access_token'),'refresh_token'=>$r->json('refresh_token')??$fallback,'expires_at'=>now()->addSeconds(max(0,(int)$r->json('expires_in')))];
    }
    private function base(): string
    {
        return match($this->config?->extra_settings['region']??null) {
            'NA'=>'https://advertising-api.amazon.com','EU'=>'https://advertising-api-eu.amazon.com','FE'=>'https://advertising-api-fe.amazon.com',
            default=>throw new RuntimeException('Choose Amazon region NA/EU/FE explicitly in deployment configuration.'),
        };
    }
    private function request(string $token,string $path,?array $body=null,?string $profileId=null): array
    {
        if (!$this->config?->app_id) { throw new RuntimeException('Amazon client ID required.'); }
        $h=Http::withToken($token)->withHeaders(['Amazon-Advertising-API-ClientId'=>$this->config->app_id])->timeout(30)->connectTimeout(10);
        if ($profileId!==null) { $h=$h->withHeaders(['Amazon-Advertising-API-Scope'=>$profileId,'Content-Type'=>'application/vnd.createasyncreportrequest.v3+json','Accept'=>$body===null?'application/vnd.getasyncreportresponse.v3+json':'application/vnd.createasyncreportresponse.v3+json']); }
        $r=$body===null?$h->get($this->base().$path):$h->post($this->base().$path,$body);
        if ($r->status()===401) { throw new \App\Exceptions\OAuthTokenExpiredException('Amazon access expired.','amazon'); }
        if ($r->status()===429) { throw new \App\Exceptions\ProviderRateLimitException('Amazon API rate limited.','amazon'); }
        $j=json_decode($r->body(),true,512,JSON_BIGINT_AS_STRING);if ($r->failed() || !is_array($j)) { throw new RuntimeException('Amazon response failed (HTTP '.$r->status().').'); }return $j;
    }
    public function discoverAccounts(string $accessToken): array
    {
        $j=$this->request($accessToken,'/v2/profiles?apiProgram=report&accessLevel=view');if (!array_is_list($j)) { throw new RuntimeException('Amazon profiles response must be a direct array.'); }$out=[];
        foreach ($j as $a) {
            if (!isset($a['profileId'])) { throw new RuntimeException('Amazon profileId missing; accountInfo.id is not a substitute.'); }
            $out[]=['provider'=>'amazon','external_account_id'=>(string)$a['profileId'],'account_name'=>$a['accountInfo']['name']??('Amazon profile '.$a['profileId']),'currency'=>$a['currencyCode']??null,'timezone'=>$a['timezone']??null,'region'=>$this->config->extra_settings['region']];
        }
        return $out;
    }
    public function fetchCampaigns(ConnectedAdAccount $account): array { return ['endpoint'=>'not_requested_account_campaign_report_only','raw'=>[],'campaigns'=>[]]; }
    public function fetchInsights(ConnectedAdAccount $account,string $startDate,string $endDate): array
    {
        $j=\App\Models\ProviderReportJob::where('connected_ad_account_id',$account->id)->where('provider','amazon')->where('start_date',$startDate)->where('end_date',$endDate)->where('state','ready')->latest('id')->first();
        if (!$j || $j->metrics===null) { throw new RuntimeException('Amazon reporting is asynchronous; submit a report and await verified import.'); }
        return ['endpoint'=>'reporting/v3/spCampaigns','raw'=>[['report_job_id'=>$j->id,'report_id'=>$j->external_report_id,'attribution_window'=>'7d_click','ad_product'=>'SPONSORED_PRODUCTS']],'metrics'=>$j->metrics];
    }
    /** Report-type configuration is deliberately explicit and limited to verified SP columns. */
    public function submitSponsoredProductsReport(ConnectedAdAccount $account,string $start,string $end): array
    {
        if (($account->metadata['region']??$this->config?->extra_settings['region'])!==($this->config?->extra_settings['region']??null)) { throw new RuntimeException('Amazon connected profile region differs from current application region. Reconnect.'); }
        if (!$account->timezone || !in_array($account->timezone,\DateTimeZone::listIdentifiers(),true) || !preg_match('/^[A-Z]{3}$/',$account->currency??'')) { throw new RuntimeException('Amazon profile timezone and currency required.'); }
        $s=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$start,$account->timezone);$e=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$end,$account->timezone);
        if (!$s || !$e || $s->format('Y-m-d')!==$start || $e->format('Y-m-d')!==$end || $s->gt($e) || $e->gte(\Carbon\CarbonImmutable::now($account->timezone)->startOfDay()) || $s->lt(\Carbon\CarbonImmutable::now($account->timezone)->startOfDay()->subDays(95)) || $s->diffInDays($e)>30) { throw new RuntimeException('Amazon SP requires completed dates, maximum 31 days and 95-day retention.'); }
        // Deployment must verify its API boundary semantics. No undocumented date shift.
        $boundary=config('provider_reports.amazon_end_date_semantics');
        if (!in_array($boundary,['inclusive','exclusive'],true)) { throw new RuntimeException('Amazon requested endDate semantics must be verified in deployment configuration.'); }
        $requestEnd=$boundary==='exclusive'?$e->addDay()->toDateString():$end;
        $j=$this->request($account->access_token,'/reporting/reports',['name'=>'AdPlatform SP daily 7d click','startDate'=>$start,'endDate'=>$requestEnd,'configuration'=>['adProduct'=>'SPONSORED_PRODUCTS','groupBy'=>['campaign'],'columns'=>['campaignId','impressions','clicks','cost','purchases7d','sales7d','date'],'reportTypeId'=>'spCampaigns','timeUnit'=>'DAILY','format'=>'GZIP_JSON']],$account->external_account_id);
        return $this->reportState($j);
    }
    public function reportStatus(ConnectedAdAccount $account,string $id): array
    {
        return $this->reportState($this->request($account->access_token,'/reporting/reports/'.rawurlencode($id),null,$account->external_account_id));
    }
    private function reportState(array $j): array
    {
        if (empty($j['reportId']) || !in_array($j['status']??null,['PENDING','PROCESSING','COMPLETED','FAILED'],true)) { throw new RuntimeException('Amazon AsyncReport response invalid.'); }
        if ($j['status']==='COMPLETED') {
            if (empty($j['url']) || empty($j['urlExpiresAt'])) { throw new RuntimeException('Amazon completed report lacks download URL/expiry.'); }
            try { $expiry=\Carbon\CarbonImmutable::parse($j['urlExpiresAt']); } catch (\Throwable) { throw new RuntimeException('Amazon report URL expiry invalid.'); }
            if ($expiry->lte(now())) { throw new RuntimeException('Amazon report URL has expired. Poll again before download.'); }
        }
        return ['provider'=>'amazon','external_report_id'=>$j['reportId'],'state'=>$j['status'],'download_url'=>$j['url']??null,'url_expires_at'=>$j['urlExpiresAt']??null,'file_size'=>$j['fileSize']??null,'failure_reason'=>$j['failureReason']??null,'format'=>'GZIP_JSON','imported'=>false];
    }
}
