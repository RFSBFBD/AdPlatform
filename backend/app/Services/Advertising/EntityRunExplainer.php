<?php
namespace App\Services\Advertising;
/** Closed-question, deterministic archive explanation. Never calls an LLM or reads live metrics. */
final class EntityRunExplainer
{
 public function answer(array $result,string $question):array{
  if(($result['status']??null)!=='ok')return ['status'=>'not_computable','reason'=>'Successful archived entity result required.'];
  $common=['status'=>'ok','question'=>$question,'source'=>'stored_entity_result','type'=>'DETERMINISTIC_EXPLANATION','scope'=>'Archived scenario only, not current account state. No provider or AI request. No campaign execution.'];
  return $common+match($question){
   'what_is_optimized'=>['summary'=>'The explicit stored objective controls optimization. Reported response is not unique people, profit or guaranteed future outcome.','evidence'=>['objective'=>$result['objective'],'solver'=>$result['solver']??'continuous','response_metric'=>$result['response_metric'],'currency'=>$result['currency'],'window'=>$result['window'],'budget_cap'=>$result['total_budget'],'selected_spend'=>$result['scenario_total_spend'],'modeled_response'=>$result['scenario_response'],'target_reported_value'=>$result['target_reported_value']??null,'target_ratio'=>$result['target_ratio']??null,'achieved_modeled_ratio'=>$result['ratio_objective_value']??null]],
   'why_not_confident'=>['summary'=>'No calibrated allocation-confidence probability is available. Fitted observations do not establish causal response. Engineering stability, RMSE stress and explicit robust scale bounds are different objects, not interchangeable confidence measures.','evidence'=>['allocation_confidence'=>null,'stability'=>$result['sensitivity']['stability']??null,'sensitivity_status'=>$result['sensitivity']['status']??'not_computable','sensitivity_note'=>$result['sensitivity']['note']??$result['sensitivity']['label']??null,'stress_status'=>$result['stress']['status']??'not_computable','stress_label'=>$result['stress']['label']??null,'robust_status'=>$result['robust_scenario']['status']??'not_requested']],
   'how_allocation_changed'=>['summary'=>'Historical actual spend and same-scenario-spend baseline are separate comparisons. A larger share is not necessarily an absolute spend increase, and unequal total spends are not pure reallocations.','evidence'=>['actual_total_spend'=>array_sum(array_column($result['allocations'],'actual_spend')),'scenario_total_spend'=>$result['scenario_total_spend'],'entities'=>array_map(fn($a)=>['entity_key'=>$a['entity_key'],'actual_spend'=>$a['actual_spend'],'scenario_spend'=>$a['scenario_spend'],'absolute_spend_change'=>$a['scenario_spend']-$a['actual_spend']],$result['allocations']),'same_scenario_spend_baseline'=>$result['comparison']['same_scenario_spend_baseline']??null]],
   'what_are_limits'=>['summary'=>'This is a read-only fitted scenario. Solver certificates apply only to their stated model and scope. No live outcome, causal lift or unique-conversion guarantee follows.','evidence'=>['limits'=>$result['limits']??[],'certificate'=>$result['certificate']??null,'ratio_search'=>$result['ratio_search']??null,'target_search'=>$result['target_search']??null,'extrapolated_entities'=>array_values(array_column(array_filter($result['allocations'],fn($a)=>$a['outside_observed_support']??false),'entity_key'))]],
   default=>['summary'=>'Unsupported closed question.','evidence'=>[]],
  };
 }
}
