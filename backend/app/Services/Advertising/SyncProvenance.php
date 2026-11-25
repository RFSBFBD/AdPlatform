<?php
namespace App\Services\Advertising;
use App\Models\{PerformanceMetric,SyncRun};
/** Presence of stored rows is not evidence of a successful provider pull. */
final class SyncProvenance
{
 public function forAccounts($accounts):array{
  $ids=$accounts->pluck('id');
  $lastIds=SyncRun::whereIn('connected_ad_account_id',$ids)->selectRaw('MAX(id)')->groupBy('connected_ad_account_id');
  $lastRuns=SyncRun::whereIn('id',$lastIds)->get()->keyBy('connected_ad_account_id');
  $successes=SyncRun::whereIn('connected_ad_account_id',$ids)->where('status','completed')->whereNotNull('completed_at')->selectRaw('connected_ad_account_id, MAX(completed_at) AS last_completed_at')->groupBy('connected_ad_account_id')->get()->keyBy('connected_ad_account_id');
  $rows=PerformanceMetric::whereIn('connected_ad_account_id',$ids)->selectRaw("connected_ad_account_id, COUNT(*) AS row_count, SUM(CASE WHEN metadata->>'provider_endpoint' IS NOT NULL AND metadata->>'synced_at' IS NOT NULL THEN 1 ELSE 0 END) AS sourced_count")->groupBy('connected_ad_account_id')->get()->keyBy('connected_ad_account_id');$out=[];
  foreach($accounts as$a){$last=$lastRuns->get($a->id);$success=$successes->get($a->id);$data=$rows->get($a->id);$count=(int)($data?->row_count??0);$sourced=(int)($data?->sourced_count??0);
   $out[$a->id]=['stored_rows'=>$count,'row_provenance'=>$count===0?'no_stored_rows':($sourced===$count?'provider_lineage_recorded':($sourced===0?'unverified_import_or_seed':'mixed_or_partial_lineage')),'last_attempt_status'=>$last?->status,'last_successful_sync_at'=>$success?->last_completed_at?\Illuminate\Support\Carbon::parse($success->last_completed_at)->toIso8601String():null,'legacy_sync_timestamp'=>$a->last_synced_at?->toIso8601String(),'success_basis'=>$success?'completed_sync_audit':'no_completed_sync_audit'];
  }return $out;
 }
}
