<?php
namespace App\Jobs;
use App\Models\ProviderReportJob;
use App\Services\Advertising\Reports\MicrosoftReportWorkflow;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
class PollMicrosoftReport implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=3;
    public array $backoff=[120,300,900];
    public function __construct(public int $reportJobId) {}
    public function handle(MicrosoftReportWorkflow $workflow,AdDataSyncEngine $sync): void
    {
        $lock=Cache::lock('microsoft-report:'.$this->reportJobId,90);
        if (!$lock->get()) { $this->release(120);return; }
        try {
            $j=ProviderReportJob::find($this->reportJobId);if (!$j) { return; }
            $workflow->advance($j);$j->refresh();
            if (in_array($j->state,['pending','polling'],true)) { self::dispatch($j->id)->delay(now()->addSeconds(120));return; }
            if ($j->state==='ready') {
                $run=$sync->syncAccount($j->account,'manual',$j->start_date->toDateString(),$j->end_date->toDateString());
                $j->update(['state'=>$run->status==='completed'?'imported':'import_failed','error'=>$run->status==='completed'?null:$run->error_message]);
            }
        } finally { $lock->release(); }
    }
    public function failed(?\Throwable $e): void { ProviderReportJob::whereKey($this->reportJobId)->update(['state'=>'failed','error'=>'Report processing failed after bounded retries. Reconnect or submit again.']); }
}
