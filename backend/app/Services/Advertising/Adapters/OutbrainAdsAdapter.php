<?php
namespace App\Services\Advertising\Adapters;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Token-only Amplify reader. ET reporting offset/DST unresolved, never enters pooled OR. */
class OutbrainAdsAdapter implements AdvertisingProviderInterface
{
    public const BASE='https://api.outbrain.com/amplify/v0.1';
    public function __construct(private ?ProviderDeveloperConfig $config=null) { $this->config??=ProviderDeveloperConfig::where('provider','outbrain')->first(); }
    public function getProviderName(): string { return 'outbrain'; }
    public function getAuthorizationUrl(string $state,string $redirectUri): string { throw new RuntimeException('Outbrain uses a provisioned API token. Browser OAuth is not supported.'); }
    public function handleOAuthCallback(string $code,string $redirectUri): array { throw new RuntimeException('Outbrain has no OAuth callback.'); }
    public function refreshToken(string $refreshToken): array { throw new RuntimeException('Outbrain token expires after 30 days; provision a new token. No password login or fabricated refresh grant.'); }
    private function get(string $token,string $path,array $params=[]): array
    {
        $r=Http::withHeaders(['OB-TOKEN-V1'=>$token])->timeout(30)->connectTimeout(10)->get(self::BASE.$path,$params);
        if ($r->status()===401) { throw new \App\Exceptions\OAuthTokenExpiredException('Outbrain API token expired; reconnect.','outbrain'); }
        if ($r->status()===429) { throw new \App\Exceptions\ProviderRateLimitException('Outbrain API rate limited.','outbrain'); }
        $j=$r->json();if ($r->failed() || !is_array($j)) { throw new RuntimeException('Outbrain response failed validation (HTTP '.$r->status().').'); }return $j;
    }
    public function discoverAccounts(string $accessToken): array
    {
        $j=$this->get($accessToken,'/marketers');if (!isset($j['marketers']) || !is_array($j['marketers'])) { throw new RuntimeException('Outbrain response lacks marketers.'); }$out=[];
        foreach ($j['marketers'] as $a) {
            if (empty($a['id'])) { throw new RuntimeException('Outbrain marketer id missing.'); }
            $b=$this->get($accessToken,'/marketers/'.rawurlencode($a['id']).'/budgets');if (!isset($b['budgets']) || !is_array($b['budgets'])) { throw new RuntimeException('Outbrain response lacks budgets.'); }
            $currencies=array_unique(array_map(fn($r)=>(string)($r['currency']??''),$b['budgets']));
            $currency=count($currencies)===1 && reset($currencies)!==''?reset($currencies):null;
            $out[]=['provider'=>'outbrain','external_account_id'=>(string)$a['id'],'account_name'=>$a['name']??$a['id'],'currency'=>$currency,'timezone'=>null,'provider_reporting_timezone'=>'ET','unresolved_offset'=>true,'readiness'=>'blocked_timezone_definition'];
        }
        return $out;
    }
    public function fetchCampaigns(ConnectedAdAccount $account): array { return ['endpoint'=>'not_requested_marketer_day_only','raw'=>[],'campaigns'=>[]]; }
    public function fetchInsights(ConnectedAdAccount $account,string $startDate,string $endDate): array { throw new RuntimeException('Outbrain ET daily boundary/DST is not yet verified. Unified revenue import is disabled; use provider-native periodic report.'); }
    public function periodicReport(ConnectedAdAccount $account,string $startDate,string $endDate): array
    {
        $s=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$startDate,'UTC');$e=\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$endDate,'UTC');
        if (!$s || !$e || $s->format('Y-m-d')!==$startDate || $e->format('Y-m-d')!==$endDate || $s->gt($e) || $s->lt(now()->subYears(3)->startOfDay()) || $e->gte(now()->startOfDay()) || !$account->currency) { throw new RuntimeException('Outbrain requires valid completed dates within three years and verified marketer currency.'); }
        $offset=0;$out=[];$seen=[];$raw=[];
        do {
            $j=$this->get($account->access_token,'/reports/marketers/'.rawurlencode($account->external_account_id).'/periodic',['from'=>$startDate,'to'=>$endDate,'breakdown'=>'daily','limit'=>500,'offset'=>$offset,'includeArchivedCampaigns'=>'true','includeConversionDetails'=>'true','conversionsByClickDate'=>'false']);$raw[]=$j;
            if (!isset($j['results'],$j['totalResults']) || !is_array($j['results']) || !is_numeric($j['totalResults'])) { throw new RuntimeException('Outbrain periodic report shape invalid.'); }
            foreach ($j['results'] as $r) {
                $d=$r['metadata']['fromDate']??'';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d) || ($r['metadata']['toDate']??null)!==$d || $d<$startDate || $d>$endDate || isset($seen[$d])) { throw new RuntimeException('Outbrain invalid or duplicate daily period.'); }$seen[$d]=true;$m=$r['metrics']??[];
                foreach (['spend','impressions','clicks','totalConversions','totalSumValue'] as $k) { if (!isset($m[$k]) || !is_numeric($m[$k]) || !is_finite((float)$m[$k]) || $m[$k]<0) { throw new RuntimeException('Outbrain required metric unavailable or invalid.'); } }
                $out[]=['kind'=>'ACTUAL','date'=>$d,'spend'=>\App\Services\Advertising\MetricOperand::read($m,'spend'),'currency'=>$account->currency,'impressions'=>\App\Services\Advertising\MetricOperand::read($m,'impressions',true),'clicks'=>\App\Services\Advertising\MetricOperand::read($m,'clicks',true),'platform_reported_conversions'=>\App\Services\Advertising\MetricOperand::read($m,'totalConversions'),'attributed_conversion_value'=>\App\Services\Advertising\MetricOperand::read($m,'totalSumValue'),'provider_reporting_timezone'=>'ET','unresolved_offset'=>true,'attribution_date'=>'conversion_date'];
            }
            $offset+=count($j['results']);if ($offset<(int)$j['totalResults'] && $j['results']===[]) { throw new RuntimeException('Outbrain pagination stalled.'); }if (count($raw)>1000) { throw new RuntimeException('Outbrain pagination safety limit reached.'); }
        } while ($offset<(int)$j['totalResults']);
        return ['provider'=>'outbrain','status'=>'provider_native_only','rows'=>$out,'raw'=>$raw,'revenue_optimizer_eligible'=>false,'reason'=>'ET daily reporting offset and DST policy unresolved; conversion value not certified sales.'];
    }
}
