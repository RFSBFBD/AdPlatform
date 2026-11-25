<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Side-by-side platform view with explicit comparability gates (attribution, conversion definition, currency, data window,
 * sample size, objective, audience, funnel position). It never ranks platforms and never declares a winner. A dimension that is
 * not recorded is "unknown", not "same". Objective, audience and funnel position are never inferred from platform data.
 */
final class PlatformComparisonGate
{
    /** Engineering guard for "enough conversions to read a ratio", not statistical power. */
    public const MIN_CONVERSIONS = 30;

    public function compare(array $summaries): array
    {
        $platforms = [];
        foreach ($summaries as $s) {
            $platforms[] = ['account_id' => $s['account_id'], 'provider' => $s['provider'], 'type' => 'ACTUAL_RECORDED_TOTALS', 'currency' => $s['currency'], 'window' => $s['window'],
                'spend' => $s['spend'], 'conversions' => $s['conversions'], 'conversion_value' => $s['conversion_value'] ?? null,
                'definition' => ['attribution' => $s['attribution'] ?? null, 'conversion' => $s['definition_key'] ?? null], 'objective' => $s['objective'] ?? null, 'audience' => $s['audience'] ?? null, 'funnel_position' => $s['funnel_position'] ?? null];
        }
        $pairs = [];
        for ($i = 0; $i < count($summaries); $i++) {
            for ($j = $i + 1; $j < count($summaries); $j++) {
                $pairs[] = $this->pair($summaries[$i], $summaries[$j]);
            }
        }

        return ['status' => count($summaries) < 2 ? 'insufficient_platforms' : 'ok', 'type' => 'PLATFORM_COMPARABILITY_VIEW', 'platforms' => $platforms, 'pairs' => $pairs, 'ranking' => null,
            'note' => 'No platform is ranked or called better. A ratio such as ROAS or CPA from two platforms answers different questions unless every gate is satisfied, and the objective, audience and funnel gates also need a declaration that platform data cannot supply.'];
    }

    private function pair(array $a, array $b): array
    {
        $same = fn ($x, $y) => $x === null || $y === null ? 'unknown' : ($x === $y ? 'same' : 'different');
        $checks = [
            'attribution' => $same($a['attribution'] ?? null, $b['attribution'] ?? null),
            'conversion_definition' => $same($a['definition_key'] ?? null, $b['definition_key'] ?? null),
            'currency' => $same($a['currency'] ?? null, $b['currency'] ?? null),
            'data_window' => (($a['window']['start'] ?? 0) === ($b['window']['start'] ?? 1) && ($a['window']['end'] ?? 0) === ($b['window']['end'] ?? 1) && ($a['window']['timezone'] ?? 0) === ($b['window']['timezone'] ?? 1)) ? 'same' : 'different',
            'sample_size' => ((float) $a['conversions'] >= self::MIN_CONVERSIONS && (float) $b['conversions'] >= self::MIN_CONVERSIONS) ? 'both_at_least_'.self::MIN_CONVERSIONS.'_conversions' : 'below_guard',
            'objective' => $same($a['objective'] ?? null, $b['objective'] ?? null),
            'audience' => $same($a['audience'] ?? null, $b['audience'] ?? null),
            'funnel_position' => $same($a['funnel_position'] ?? null, $b['funnel_position'] ?? null),
        ];
        $blockers = [];
        foreach ($checks as $k => $v) {
            if (in_array($v, ['different', 'unknown', 'below_guard'], true)) {
                $blockers[] = $k.': '.$v;
            }
        }
        // Cross-source equivalence is never granted by this view: provider outcome meanings are not proven equal.
        $blockers[] = 'cross_provider_outcome_equivalence: not established';

        return ['accounts' => [$a['account_id'], $b['account_id']], 'providers' => [$a['provider'], $b['provider']], 'checks' => $checks, 'verdict' => 'not_comparable', 'blockers' => $blockers,
            'allowed_use' => 'Read each platform on its own definition. Do not rank, add, or choose between them from this view.'];
    }
}
