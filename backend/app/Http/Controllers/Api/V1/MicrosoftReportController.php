<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Jobs\PollMicrosoftReport;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderReportJob;
use App\Services\Advertising\Reports\MicrosoftReportWorkflow;
use Illuminate\Http\Request;
class MicrosoftReportController extends Controller
{
    public function submit(Request $r,int $id,MicrosoftReportWorkflow $workflow)
    {
        $a=ConnectedAdAccount::where('user_id',$r->user()->id)->where('provider','microsoft')->findOrFail($id);
        $v=$r->validate(['start_date'=>'required|date_format:Y-m-d','end_date'=>'required|date_format:Y-m-d|after_or_equal:start_date']);
        if (config('queue.default')==='sync') { return response()->json(['success'=>false,'message'=>'Asynchronous reports require a durable queue worker, not the synchronous driver.'],422); }
        $j=$workflow->submit($a,$v['start_date'],$v['end_date']);PollMicrosoftReport::dispatch($j->id)->delay($j->next_poll_at);
        return response()->json(['success'=>true,'data'=>['report_job_id'=>$j->id,'state'=>$j->state,'imported'=>false,'next_poll_at'=>$j->next_poll_at]],202);
    }
    public function status(Request $r,int $id,string $job)
    {
        $a=ConnectedAdAccount::where('user_id',$r->user()->id)->where('provider','microsoft')->findOrFail($id);
        $q=ProviderReportJob::where('connected_ad_account_id',$a->id)->where('provider','microsoft');
        $j=$job==='latest'?$q->latest('id')->firstOrFail():$q->findOrFail($job);
        return response()->json(['success'=>true,'data'=>['report_job_id'=>$j->id,'state'=>$j->state,'imported'=>$j->state==='imported','poll_count'=>$j->poll_count,'next_poll_at'=>$j->next_poll_at,'deadline'=>$j->deadline_at,'error'=>$j->error]]);
    }
}
