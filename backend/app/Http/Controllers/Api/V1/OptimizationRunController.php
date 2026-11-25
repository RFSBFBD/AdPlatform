<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\OptimizationRun;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\OptimizationOrchestrator;
use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;
use Illuminate\Http\Request;
class OptimizationRunController extends Controller
{
    public function customLead(Request $request,int $id)
    {
        $v=$request->validate(['custom_allocation'=>'required|array|min:1|max:100','custom_allocation.*'=>'required|numeric|min:0|max:999999999']);
        $run=OptimizationRun::where('user_id',$request->user()->id)->findOrFail($id);
        return response()->json(['success'=>true,'data'=>(new \App\Services\OperationsResearch\FrozenLeadScenario)->evaluate($run,$v['custom_allocation'])]);
    }
    public function compare(Request $request,int $id)
    {
        $v=$request->validate(['other_run_id'=>'required|integer|min:1']);$first=OptimizationRun::where('user_id',$request->user()->id)->findOrFail($id);$second=OptimizationRun::where('user_id',$request->user()->id)->findOrFail($v['other_run_id']);
        $guard=new \App\Services\Analytics\MetaDefinitionTransitionGuard;
        if($problem=$guard->archiveProblem($first)??$guard->archiveProblem($second))return response()->json(['success'=>true,'data'=>['status'=>'not_computable','reason'=>$problem]]);
        return response()->json(['success'=>true,'data'=>(new \App\Services\OperationsResearch\ArchivedScenarioComparison)->compare($first,$second)]);
    }
    public function report(Request $request,int $id)
    {
        $r=OptimizationRun::where('user_id',$request->user()->id)->findOrFail($id);
        abort_if((new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r)!==null,409,'Archived outcome definitions are unverified. No decision report exported.');
        abort_unless($r->engine==='entity'&&$r->status==='ok',422,'Successful entity archive required.');
        abort_if($r->integrityStatus()==='mismatch',409,'Archived input/result integrity mismatch.');
        abort_unless($r->source_snapshot&&hash_equals($r->snapshot_sha256??'',hash('sha256',json_encode($r->source_snapshot))),409,'Source snapshot missing or checksum mismatch.');
        return response()->view('reports.entity-run',['run'=>$r,'result'=>$r->result])->header('Content-Type','text/html; charset=UTF-8')->header('Content-Disposition','attachment; filename="adplatform-entity-run-'.$r->id.'.html"')->header('Content-Security-Policy',"default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")->header('X-Content-Type-Options','nosniff')->header('Cache-Control','private, no-store');
    }
    public function index(Request $request)
    {
        $v=$request->validate(['before_id'=>'nullable|integer|min:1','limit'=>'nullable|integer|min:1|max:25']);$limit=(int)($v['limit']??10);
        $runs=OptimizationRun::where('user_id',$request->user()->id)->where('engine','entity')->when($v['before_id']??null,fn($q,$id)=>$q->where('id','<',$id))->orderByDesc('id')->limit($limit+1)->get();$more=$runs->count()>$limit;$runs=$runs->take($limit);
        return response()->json(['success'=>true,'data'=>['rows'=>$runs->map(fn($r)=>['id'=>$r->id,'engine'=>$r->engine,'engine_version'=>$r->engine_version,'status'=>$r->status,'created_at'=>$r->created_at,'objective'=>$r->result['objective']??null,'entity_level'=>$r->result['entity_level']??null,'currency'=>$r->result['currency']??null,'window'=>$r->result['window']??null,'scenario_spend'=>(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r)?null:($r->result['scenario_total_spend']??null),'modeled_response'=>(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r)?null:($r->result['scenario_response']??null),'result_integrity'=>$r->integrityStatus(),'archive_present'=>$r->source_snapshot!==null,'outcome_definition_warning'=>(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r)])->values(),'next_before_id'=>$more?$runs->last()->id:null,'note'=>'Your stored entity scenarios, newest first. Stored operands, not current performance. Opening an archive does not re-run or change a campaign.']]);
    }
    public function show(Request $request,int $id)
    {
        $r=OptimizationRun::where('user_id',$request->user()->id)->findOrFail($id);
        return response()->json(['success'=>true,'data'=>['id'=>$r->id,'engine'=>$r->engine,'engine_version'=>$r->engine_version,'outcome_definition_warning'=>(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r),'inputs'=>$r->inputs,'result'=>($warning=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r))?['status'=>'not_computable','reason'=>$warning,'stored_output_withheld'=>true]:$r->result,'snapshot_sha256'=>$r->snapshot_sha256,'archive_present'=>$r->source_snapshot!==null,'result_integrity'=>$r->integrityStatus(),'integrity_note'=>'Accidental-change checksum over stored operands and result, not protection against privileged database tampering. Legacy unsealed rows cannot be retroactively verified.','created_at'=>$r->created_at]]);
    }
    public function explain(Request $request,int $id)
    {
        $v=$request->validate(['question'=>'required|in:what_is_optimized,why_not_confident,how_allocation_changed,what_are_limits']);
        $r=OptimizationRun::where('user_id',$request->user()->id)->findOrFail($id);
        if($r->integrityStatus()==='mismatch')return response()->json(['success'=>false,'message'=>'Archived result or input integrity mismatch.'],409);
        if($r->engine!=='entity')return response()->json(['success'=>true,'data'=>['status'=>'not_computable','reason'=>'Closed-question explanation currently supports entity archives only.']]);
        if(!$r->source_snapshot||!hash_equals($r->snapshot_sha256??'',hash('sha256',json_encode($r->source_snapshot))))return response()->json(['success'=>false,'message'=>'Archived snapshot missing or checksum mismatch.'],409);
        if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r))return response()->json(['success'=>true,'data'=>['status'=>'not_computable','reason'=>$problem]]);
        $answer=(new \App\Services\Advertising\EntityRunExplainer)->answer($r->result,$v['question']);
        return response()->json(['success'=>true,'data'=>$answer+['run_id'=>$r->id,'snapshot_sha256'=>$r->snapshot_sha256,'result_integrity'=>$r->integrityStatus(),'archive_note'=>'Snapshot checksum detects accidental source changes, not privileged tampering. Explanation reads stored result, it does not re-execute or independently validate that result.']]);
    }
    /** Replay archived fitted curves with the matching current solver, no live metric query. */
    public function replay(Request $request,int $id)
    {
        $v=$request->validate(['scenario'=>'nullable|in:base,robust']);
        $r=OptimizationRun::where('user_id',$request->user()->id)->findOrFail($id);$s=$r->source_snapshot;
        if($r->integrityStatus()==='mismatch')return response()->json(['success'=>false,'message'=>'Archived result or input integrity mismatch.'],409);
        if (!$s) { return response()->json(['success'=>false,'message'=>'This run has no archived snapshot. Older runs cannot be reconstructed from a hash.'],422); }
        if (!hash_equals($r->snapshot_sha256??'',hash('sha256',json_encode($s)))) { return response()->json(['success'=>false,'message'=>'Archived snapshot checksum mismatch. No replay produced.'],409); }
        if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r))return response()->json(['success'=>true,'data'=>['status'=>'not_computable','reason'=>$problem]]);
        if($r->engine===OptimizationOrchestrator::ENGINE && $r->status!=='ok')return response()->json(['success'=>true,'data'=>['status'=>'not_computable','reason'=>'Archived run abstained. No curves are replayed and no objective is substituted.','objective_preflight'=>$s['objective_preflight']??null]]);
        if($r->engine==='entity'){return response()->json(['success'=>true,'data'=>app(\App\Services\OperationsResearch\EntityOptimizationArchive::class)->replay($r,$v['scenario']??'base')]);}
        if(($v['scenario']??'base')==='robust')return response()->json(['success'=>true,'data'=>['status'=>'not_computable','reason'=>'Robust re-execution currently supports entity archives only. No nominal base replay substituted.']]);
        if ($r->engine_version!==OptimizationOrchestrator::ENGINE_VERSION || ($s['curve_mode']??null)!=='fitted') { return response()->json(['success'=>false,'message'=>'Replay requires fitted curves and the same engine version. Stored original result remains available.'],422); }
        $channels=[];
        foreach ($s['channels'] as $ch) {
            $c=$ch['curve'];$p=$c['params'];$max=(float)$c['support_max'];$min=(float)$c['support_min'];
            $curve=match($c['name']) {
                'linear'=>ResponseCurve::linear((float)$p['a'],$max,$min),
                'log'=>ResponseCurve::log((float)$p['a'],(float)$p['b'],$max,$min),
                'power'=>ResponseCurve::power((float)$p['a'],(float)$p['b'],$max,$min),
                'saturation'=>ResponseCurve::saturation((float)$p['a'],(float)$p['b'],$max,$min),
                default=>null,
            };
            if (!$curve) { return response()->json(['success'=>false,'message'=>'Archived curve unsupported.'],422); }
            $ch['curve']=$curve;$channels[]=$ch;
        }
        $oldBand=config('or.maintain_band_percent');
        try {
            config(['or.maintain_band_percent'=>$s['configuration']['or']['maintain_band_percent']]);
            $replayed=(new GenericCurveOptimizer())->optimize((float)$r->result['total_budget'],$channels,['budget_mode'=>$r->inputs['budget_mode']??'exact','min_marginal_roas'=>$r->inputs['min_marginal_roas']??null])->toArray();
        } finally { config(['or.maintain_band_percent'=>$oldBand]); }
        $original=array_column($r->result['channel_allocations']??[],'optimal_spend','channel');$rerun=array_column($replayed['channel_allocations']??[],'optimal_spend','channel');$maxDelta=0.0;
        foreach ($original as $channel=>$x) { $maxDelta=max($maxDelta,abs($x-($rerun[$channel]??INF))); }
        $matched=$r->status==='ok' && $replayed['status']==='ok' && array_keys($original)===array_keys($rerun) && $maxDelta<=0.01 && abs($r->result['optimal_total_revenue']-$replayed['optimal_total_revenue'])<=0.01;
        return response()->json(['success'=>true,'data'=>['run_id'=>$r->id,'type'=>'REPLAY','matches_archived_base_result'=>$matched,'max_allocation_delta'=>$maxDelta,'result'=>$replayed,'snapshot_sha256'=>$r->snapshot_sha256,'note'=>'Archived fitted base curves and constraints only. No current metrics, refitting, forecasts, robust scenario or live provider call. Stored original result retains those additional analyses. Checksum detects accidental changes, not privileged database tampering.']]);
    }
}
