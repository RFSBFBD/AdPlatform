<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OperationsResearch\EntityOptimizationService;
use Illuminate\Http\Request;

class EntityOptimizationController extends Controller
{
    public function run(Request $r, EntityOptimizationService $service)
    {
        $v = $r->validate(['conversion_definition_id'=>'required_if:objective,MAXIMIZE_LEADS,MAXIMIZE_QUALIFIED_LEADS|nullable|integer|min:1','robust_bounds'=>'nullable|array|max:100','robust_bounds.*'=>'required|numeric|min:0|lt:1','target_reported_value'=>'required_if:objective,TARGET_REVENUE|nullable|numeric|gt:0|max:999999999','custom_allocation'=>'nullable|array|max:100','custom_allocation.*'=>'required|numeric|min:0|max:999999999','solver'=>'nullable|in:continuous,discrete_milp','budget_step'=>'required_if:solver,discrete_milp|nullable|numeric|gt:0|max:999999999','maximum_active'=>'required_if:solver,discrete_milp|nullable|integer|min:1|max:100','entity_level' => 'required|in:campaign,ad_set,ad', 'objective' => 'required|in:MAXIMIZE_REVENUE,MAXIMIZE_CONVERSIONS,MAXIMIZE_ROAS,MINIMIZE_CPA,TARGET_CPA,TARGET_ROAS,TARGET_REVENUE,MAXIMIZE_LEADS,MAXIMIZE_QUALIFIED_LEADS', 'target_ratio' => 'required_if:objective,TARGET_CPA,TARGET_ROAS|nullable|numeric|gt:0|max:999999999', 'minimum_total_spend' => 'required_if:objective,MAXIMIZE_ROAS,MINIMIZE_CPA,TARGET_CPA,TARGET_ROAS|nullable|numeric|gt:0|max:999999999', 'budget' => 'required|numeric|gt:0|max:999999999', 'provider' => 'nullable|in:meta,google,tiktok,snapchat,taboola,pinterest,reddit,spotify,linkedin,microsoft,amazon', 'entity_limits' => 'nullable|array|max:100', 'entity_limits.*.min' => 'nullable|numeric|min:0|max:999999999', 'entity_limits.*.max' => 'nullable|numeric|gt:0|max:999999999']);

        return response()->json(['success' => true, 'data' => $service->run($r->user()->id, $v)]);
    }
}
