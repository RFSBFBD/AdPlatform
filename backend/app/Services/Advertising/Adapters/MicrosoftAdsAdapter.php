<?php
namespace App\Services\Advertising\Adapters;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Versioned read adapter, UTC daily account scope. Never tested live. */
class MicrosoftAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE='https://clientcenter.api.bingads.microsoft.com/CustomerManagement/v13';
    public function __construct(private ?ProviderDeveloperConfig $config=null) { $this->config??=ProviderDeveloperConfig::where('provider','microsoft')->first(); }
    public function getProviderName(): string { return 'microsoft'; }
    private function configured(): void { if (!$this->config?->is_enabled || !$this->config?->app_id || !$this->config?->app_secret) { throw new RuntimeException('Microsoft Advertising app must be configured and enabled.'); } }
    public function getAuthorizationUrl(string $state,string $redirectUri): string
    {
        $this->configured();return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query(['client_id'=>$this->config->app_id,'response_type'=>'code','state'=>$state,'redirect_uri'=>$redirectUri,'scope'=>'https://ads.microsoft.com/msads.manage offline_access'],'','&',PHP_QUERY_RFC3986);
    }
    public function handleOAuthCallback(string $code,string $redirectUri): array { return $this->token(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri]); }
    public function refreshToken(string $refreshToken): array
    {
        if ($refreshToken==='') { throw new RuntimeException('Microsoft Advertising refresh token is missing; reconnect.'); }
        return $this->token(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],$refreshToken);
    }
    private function token(array $body,?string $fallback=null): array
    {
        $this->configured();$r=Http::asForm()->timeout(30)->connectTimeout(10)->post('https://login.microsoftonline.com/common/oauth2/v2.0/token',$body+['scope'=>'https://ads.microsoft.com/msads.manage offline_access','client_id'=>$this->config->app_id,'client_secret'=>$this->config->app_secret]);
        if ($r->failed() || !$r->json('access_token') || !is_numeric($r->json('expires_in'))) { throw new RuntimeException('Microsoft Advertising token exchange failed (HTTP '.$r->status().').'); }
        return ['access_token'=>$r->json('access_token'),'refresh_token'=>$r->json('refresh_token')??$fallback,'expires_at'=>now()->addSeconds(max(0,(int)$r->json('expires_in')))];
    }
    private function post(string $token,string $url,array $body,?ConnectedAdAccount $account=null): array
    {
        if (!$this->config?->developer_token) { throw new RuntimeException('Microsoft developer token required independently of OAuth.'); }
        $headers=['DeveloperToken'=>$this->config->developer_token];
        if ($account) {
            $customer=$account->metadata['parent_customer_id']??null;
            if (!$customer) { throw new RuntimeException('Microsoft parent customer ID is required.'); }
            $headers+=['CustomerId'=>(string)$customer,'CustomerAccountId'=>$account->external_account_id];
        }
        $r=Http::withToken($token)->withHeaders($headers)->timeout(30)->connectTimeout(10)->post($url,$body);
        if ($r->status()===401) { throw new \App\Exceptions\OAuthTokenExpiredException('Microsoft access token expired.','microsoft'); }
        if ($r->status()===429) { throw new \App\Exceptions\ProviderRateLimitException('Microsoft API rate limited.','microsoft'); }
        $j=$r->json();if ($r->failed() || !is_array($j)) { throw new RuntimeException('Microsoft REST response failed (HTTP '.$r->status().').'); }return $j;
    }
    public function discoverAccounts(string $accessToken): array
    {
        $u=$this->post($accessToken,self::BASE.'/User/Query',['UserId'=>null]);$id=$u['User']['Id']??null;
        if ($id===null) { throw new RuntimeException('Microsoft GetUser lacks User.Id.'); }
        $out=[];$index=0;$seen=[];
        do {
            $j=$this->post($accessToken,self::BASE.'/Accounts/Search',['Predicates'=>[['Field'=>'UserId','Operator'=>'Equals','Value'=>(string)$id]],'PageInfo'=>['Index'=>$index,'Size'=>100]]);
            $page=$j['Accounts']??null;if (!is_array($page)) { throw new RuntimeException('Microsoft account response lacks Accounts.'); }
            foreach ($page as $a) {
                if (empty($a['Id']) || isset($seen[$a['Id']])) { throw new RuntimeException('Microsoft account id missing or repeated page.'); }$seen[$a['Id']]=true;
                $out[]=['provider'=>'microsoft','external_account_id'=>(string)$a['Id'],'account_name'=>$a['Name']??(string)$a['Id'],'currency'=>$a['CurrencyCode']??null,'timezone'=>config('provider_reports.microsoft_timezone_map.'.($a['TimeZone']??'')), 'report_timezone_enum'=>$a['TimeZone']??null,'parent_customer_id'=>$a['ParentCustomerId']??null,'provider_timezone_enum'=>true];
            }
            $index++;if ($index>1000) { throw new RuntimeException('Microsoft account pagination safety limit reached.'); }
        } while (count($page)===100);
        return $out;
    }
    public function fetchCampaigns(ConnectedAdAccount $account): array { return ['endpoint'=>'not_requested_account_report_only','raw'=>[],'campaigns'=>[]]; }
    public function fetchInsights(ConnectedAdAccount $account,string $startDate,string $endDate): array
    {
        $job=\App\Models\ProviderReportJob::where('connected_ad_account_id',$account->id)->where('start_date',$startDate)->where('end_date',$endDate)->where('state','ready')->latest('id')->first();
        if (!$job || $job->metrics===null) { throw new RuntimeException('Microsoft reporting is asynchronous. Submit a report and await ready state before importing.'); }
        return ['endpoint'=>'reporting/AccountPerformanceReportRequest','raw'=>[['report_job_id'=>$job->id,'report_id'=>$job->external_report_id]],'metrics'=>$job->metrics];
    }
    public function submitReport(ConnectedAdAccount $account,string $startDate,string $endDate): array
    {
        $s=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$startDate,'UTC');$e=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$endDate,'UTC');
        if (!$s || !$e || $s->format('Y-m-d')!==$startDate || $e->format('Y-m-d')!==$endDate || $s->gt($e) || $e->gte(now()->startOfDay())) { throw new RuntimeException('Microsoft requires valid completed reporting dates.'); }
        $tz=$account->metadata['report_timezone_enum']??$account->timezone;
        if (!$tz || in_array($tz,\DateTimeZone::listIdentifiers(),true)) { throw new RuntimeException('Microsoft ReportTimeZone enum must be supplied explicitly, not guessed from an IANA zone.'); }
        $date=fn($d)=>['Day'=>$d->day,'Month'=>$d->month,'Year'=>$d->year];
        $j=$this->post($account->access_token,'https://reporting.api.bingads.microsoft.com/Reporting/v13/GenerateReport/Submit',['ReportRequest'=>['Type'=>'AccountPerformanceReportRequest','ReportName'=>'AdPlatform read-only account daily','Format'=>'Csv','FormatVersion'=>'2.0','ExcludeReportHeader'=>true,'ExcludeReportFooter'=>true,'ExcludeColumnHeaders'=>false,'ReturnOnlyCompleteData'=>true,'Aggregation'=>'Daily','Columns'=>['AccountId','TimePeriod','CurrencyCode','Spend','Impressions','Clicks','Conversions','Revenue'],'Scope'=>['AccountIds'=>[$account->external_account_id]],'Time'=>['CustomDateRangeStart'=>$date($s),'CustomDateRangeEnd'=>$date($e),'ReportTimeZone'=>$tz]]],$account);
        if (empty($j['ReportRequestId'])) { throw new RuntimeException('Microsoft submit response lacks ReportRequestId.'); }
        return ['provider'=>'microsoft','report_request_id'=>$j['ReportRequestId'],'state'=>'submitted_not_imported','submitted_at'=>now()->toIso8601String(),'deadline'=>now()->addHours(23)->toIso8601String(),'note'=>'Local conservative deadline under one day; docs disagree between one and two days. Poll every 2-15 minutes, download immediately when successful.'];
    }
    public function pollReport(ConnectedAdAccount $account,string $reportId): array
    {
        $j=$this->post($account->access_token,'https://reporting.api.bingads.microsoft.com/Reporting/v13/GenerateReport/Poll',['ReportRequestId'=>$reportId],$account);
        $s=$j['ReportRequestStatus']??null;
        if (!is_array($s) || !isset($s['Status'])) { throw new RuntimeException('Microsoft poll shape invalid.'); }
        return ['provider'=>'microsoft','state'=>$s['Status'],'download_url'=>$s['ReportDownloadUrl']??null,'imported'=>false,'note'=>'Download URL expires in 5 minutes. ZIP/CSV importer is not implemented; this is not a completed sync.'];
    }
}
