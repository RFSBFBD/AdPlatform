<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AudienceSaturationEstimator;
use Illuminate\Http\Request;

/** ESTIMATED frequency saturation for one entity; reuses the exact entity/day matrix scope and validation, adds no rows. */
final class AudienceSaturationController extends Controller
{
    public function show(Request $r)
    {
        $v = $r->validate(['account_id' => 'required|integer|min:1', 'entity_level' => 'required|in:campaign,ad_set,ad', 'entity_id' => 'required|integer|min:1', 'metric' => 'required|in:ctr,cpa', 'days' => 'nullable|integer|min:21|max:90']);
        $sub = Request::create('/', 'GET', ['account_id' => $v['account_id'], 'entity_level' => $v['entity_level'], 'days' => $v['days'] ?? 60]);
        $sub->setUserResolver(fn () => $r->user());
        $m = json_decode((new AudienceMatrixController)->show($sub)->getContent(), true)['data'];
        if (($m['status'] ?? null) !== 'ok') {
            return response()->json(['success' => true, 'data' => ['status' => $m['status'] ?? 'invalid_scope', 'type' => 'ESTIMATED', 'saturation_threshold' => null, 'interval' => null, 'reason' => $m['reason'] ?? 'Scope could not be validated.']]);
        }
        $rows = array_values(array_filter($m['rows'], fn ($x) => $x['entity_id'] === (int) $v['entity_id']));
        $out = (new AudienceSaturationEstimator)->estimate($rows, $v['metric']);

        return response()->json(['success' => true, 'data' => $out + ['account_id' => $m['account_id'], 'provider' => $m['provider'], 'entity_level' => $m['entity_level'], 'entity_id' => (int) $v['entity_id'], 'currency' => $m['currency'], 'timezone' => $m['timezone'], 'window' => $m['window'],
            'relevance_note' => 'One entity only. Frequency is the platform-reported daily value and is never averaged across entities. An estimate here is not a recommendation to change budget, and platforms are not compared.']]);
    }
}
