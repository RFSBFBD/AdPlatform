<?php
namespace App\Services\Advertising\Reports;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderReportJob;
use App\Services\Advertising\Adapters\MicrosoftAdsAdapter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Persistent async state; a submitted report is never a completed sync. */
class MicrosoftReportWorkflow
{
    public function submit(ConnectedAdAccount $a,string $start,string $end): ProviderReportJob
    {
        $active=ProviderReportJob::where('connected_ad_account_id',$a->id)->whereIn('state',['pending','polling','ready'])->first();
        if ($active) { throw new RuntimeException('An account report is already in progress or awaiting import.'); }
        $adapter=app(MicrosoftAdsAdapter::class);$this->renew($adapter,$a);
        $r=$adapter->submitReport($a,$start,$end);
        return ProviderReportJob::create(['connected_ad_account_id'=>$a->id,'provider'=>'microsoft','external_report_id'=>$r['report_request_id'],'start_date'=>$start,'end_date'=>$end,'deadline_at'=>now()->addHours(23),'state'=>'pending','next_poll_at'=>now()->addSeconds(120)]);
    }
    private function renew(MicrosoftAdsAdapter $adapter,ConnectedAdAccount $a): void
    {
        if ($a->token_expires_at && $a->token_expires_at->lte(now()->addMinute())) {
            if (!$a->refresh_token) { throw new RuntimeException('Microsoft refresh token missing. Reconnect.'); }
            $t=$adapter->refreshToken($a->refresh_token);$a->update(['access_token'=>$t['access_token'],'refresh_token'=>$t['refresh_token'],'token_expires_at'=>$t['expires_at']]);
        }
    }
    public function advance(ProviderReportJob $job): void
    {
        if (!in_array($job->state,['pending','polling'],true)) { return; }
        if ($job->deadline_at->isPast()) { $job->update(['state'=>'expired','error'=>'Local conservative report deadline reached. Submit a new report.']);return; }
        if ($job->next_poll_at && $job->next_poll_at->isFuture()) { return; }
        $a=$job->account;$adapter=app(MicrosoftAdsAdapter::class);$this->renew($adapter,$a);
        $job->increment('poll_count');$r=$adapter->pollReport($a,$job->external_report_id);
        if ($r['state']==='Pending') { $job->update(['state'=>'polling','next_poll_at'=>now()->addSeconds(120)]);return; }
        if ($r['state']!=='Success') { $job->update(['state'=>'failed','error'=>'Microsoft report ended with status '.$r['state']]);return; }
        $url=$r['download_url']??'';$u=parse_url($url);$hosts=config('provider_reports.microsoft_download_hosts',[]);
        if (!is_array($u) || ($u['scheme']??'')!=='https' || !in_array(strtolower($u['host']??''),$hosts,true) || isset($u['user']) || isset($u['pass']) || isset($u['port']) || filter_var($u['host']??'',FILTER_VALIDATE_IP)) {
            $job->update(['state'=>'blocked_download_host','error'=>'Report host not in deployment-approved HTTPS allowlist. URL and tokens are not exposed.']);return;
        }
        $file=tempnam(sys_get_temp_dir(),'ad-download-');if ($file===false) { throw new RuntimeException('Cannot create bounded download.'); }
        try {
            // No Authorization header on a signed download URL; no redirects to a second host.
            $res=Http::timeout(60)->connectTimeout(10)->withoutRedirecting()->withOptions(['sink'=>$file,'progress'=>function($total,$downloaded) {
                if ($total>MicrosoftReportParser::MAX_COMPRESSED || $downloaded>MicrosoftReportParser::MAX_COMPRESSED) { throw new RuntimeException('Report exceeds local compressed size policy.'); }
            }])->get($url);
            if (!$res->successful()) { throw new RuntimeException('Microsoft report download failed (HTTP '.$res->status().').'); }
            $bytes=file_get_contents($file);
            // Http fake does not implement sink; bounded fixture body fallback.
            if ($bytes==='' && app()->runningUnitTests()) { $bytes=$res->body(); }
            if (!is_string($bytes)) { throw new RuntimeException('Cannot read report download.'); }
            $metrics=(new MicrosoftReportParser())->parse($bytes,$a->external_account_id,$a->currency,$job->start_date->toDateString(),$job->end_date->toDateString());
            $job->update(['state'=>'ready','metrics'=>$metrics,'error'=>null,'next_poll_at'=>null]);
        } finally { @unlink($file); }
    }
}
