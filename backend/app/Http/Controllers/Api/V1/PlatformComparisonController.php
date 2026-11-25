<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{ConnectedAdAccount,PerformanceMetric};
use App\Services\Analytics\{MetaDefinitionTransitionGuard,PlatformComparisonGate};
use Illuminate\Http\Request;

/** Per-account recorded campaign-level totals with comparability gates. No ranking, no merged total. */
final class PlatformComparisonController extends Controller
{
    public function show(Request $r)
    {
        $v = $r->validate(['account_ids' => 'required|array|min:1|max:8', 'account_ids.*' => 'integer|min:1|distinct', 'days' => 'nullable|integer|min:1|max:90']);
        $accounts = ConnectedAdAccount::where('user_id', $r->user()->id)->whereIn('id', $v['account_ids'])->get()->keyBy('id');
        if ($accounts->count() !== count($v['account_ids'])) {
            abort(404);
        }
        $n = $v['days'] ?? 30;
        $summaries = [];
        $excluded = [];
        foreach ($v['account_ids'] as $id) {
            $a = $accounts[$id];
            if (! $a->currency || ! in_array($a->timezone, \DateTimeZone::listIdentifiers(), true)) {
                $excluded[] = ['account_id' => $a->id, 'provider' => $a->provider, 'reason' => 'Known currency and verified IANA timezone required.'];

                continue;
            }
            $today = now()->setTimezone($a->timezone);
            $start = $today->copy()->subDays($n)->toDateString();
            $end = $today->copy()->subDay()->toDateString();
            $rows = PerformanceMetric::where('connected_ad_account_id', $a->id)->whereBetween('date', [$start, $end])->whereNotNull('campaign_id')->whereNull('ad_set_id')->whereNull('ad_id')->orderBy('date')->limit(5001)->get();
            if ($rows->count() > 5000) {
                $excluded[] = ['account_id' => $a->id, 'provider' => $a->provider, 'reason' => 'More than 5000 campaign/day rows; no partial totals shown.'];

                continue;
            }
            if ($rows->isEmpty() || ($problem = (new MetaDefinitionTransitionGuard)->problem($rows))) {
                $excluded[] = ['account_id' => $a->id, 'provider' => $a->provider, 'reason' => $rows->isEmpty() ? 'No recorded campaign-level rows in the window. Missing is unknown, not zero.' : $problem];

                continue;
            }
            $bad = $rows->first(fn ($x) => $x->currency !== $a->currency || $x->provider !== $a->provider || $x->conversions === null || $x->spend === null);
            if ($bad) {
                $excluded[] = ['account_id' => $a->id, 'provider' => $a->provider, 'reason' => 'Currency, provider or missing metric inconsistency. No total shown.'];

                continue;
            }
            $contracts = $rows->map(fn ($x) => json_encode($x->metadata['outcome_contract'] ?? null))->unique()->values();
            $contract = $contracts->count() === 1 ? json_decode($contracts[0], true) : null;
            $summaries[] = ['account_id' => $a->id, 'provider' => $a->provider, 'currency' => $a->currency, 'window' => ['start' => $start, 'end' => $end, 'timezone' => $a->timezone],
                'spend' => bcadd('0', (string) $rows->reduce(fn ($c, $x) => bcadd($c, (string) $x->spend, 4), '0'), 4), 'conversions' => $rows->reduce(fn ($c, $x) => bcadd($c, (string) $x->conversions, 4), '0'),
                'conversion_value' => $rows->reduce(fn ($c, $x) => bcadd($c, (string) ($x->conversion_value ?? 0), 4), '0'),
                'attribution' => $contract['attribution_basis'] ?? null, 'definition_key' => $contract ? ($contract['provider'] ?? '').':'.($contract['count_metric'] ?? '').'|'.($contract['value_metric'] ?? '') : null];
        }
        $out = (new PlatformComparisonGate)->compare($summaries);

        return response()->json(['success' => true, 'data' => $out + ['excluded' => $excluded, 'window_days' => $n]]);
    }
}
