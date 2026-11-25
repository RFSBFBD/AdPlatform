<?php

namespace App\Services\Analytics;

/**
 * Campaign Diagnostics (Master Spec sections 32-37, 66, 73).
 *
 * Compares a current window with a baseline window along the funnel
 * Delivery -> Traffic -> Conversion -> Revenue and classifies the failure
 * pattern from deterministic rules. Classification is a labelled hypothesis
 * with the signals that triggered it, never a verdict: the output names the
 * checks to run (creative, audience, landing page, tracking...) instead of
 * declaring a cause.
 *
 * Health states: Healthy / Warning / Critical / Insufficient Data.
 * Decision states (spec 66): NO_ACTION_REQUIRED, MONITOR, INVESTIGATE,
 * OPTIMIZATION_OPPORTUNITY, HIGH_RISK, INSUFFICIENT_DATA.
 */
final class DiagnosticsEngine
{
    public const STAGES = ['delivery', 'traffic', 'conversion', 'revenue'];

    /**
     * @param array $current totals of the current window: spend, impressions, clicks, conversions, conversion_value (+ optionally reach, frequency)
     * @param array $baseline totals of the baseline window (same shape)
     * @param array $meta {days_current:int, days_baseline:int, entity:string, has_frequency:bool}
     */
    public function diagnose(array $current, array $baseline, array $meta = []): array
    {
        $band = (float) config('analytics.diagnostics.change_band_percent', 20);
        $minDays = (int) config('analytics.diagnostics.min_days', 7);
        $entity = (string) ($meta['entity'] ?? 'entity');

        $daysC = (int) ($meta['days_current'] ?? 0);
        $daysB = (int) ($meta['days_baseline'] ?? 0);
        if ($daysC < $minDays || $daysB < $minDays) {
            return [
                'entity' => $entity,
                'health' => 'insufficient_data',
                'decision_state' => 'INSUFFICIENT_DATA',
                'reason' => "Diagnostics need at least {$minDays} complete days in each window (current {$daysC}, baseline {$daysB}). No pattern was classified.",
                'band_percent' => $band,
                'stages' => [],
                'classification' => null,
            ];
        }

        $cur = $this->normalize($current);
        $base = $this->normalize($baseline);

        $metrics = [
            'delivery' => ['spend', 'impressions', 'cpm'],
            'traffic' => ['clicks', 'ctr', 'cpc'],
            'conversion' => ['conversions', 'cvr', 'cpa'],
            'revenue' => ['conversion_value', 'aov', 'roas'],
        ];

        $signals = [];
        foreach ($metrics as $stage => $keys) {
            foreach ($keys as $k) {
                $c = $cur[$k];
                $b = $base[$k];
                $change = null;
                $reason = null;
                if ($c === null || $b === null) {
                    $reason = 'missing, invalid or undefined metric in one of the windows';
                } elseif ($b == 0.0) {
                    $reason = 'baseline is zero; a percent change would be meaningless';
                } else {
                    $change = (($c - $b) / abs($b)) * 100.0;
                }
                $signals[$k] = [
                    'stage' => $stage,
                    'current' => $c,
                    'baseline' => $b,
                    'change_percent' => $change !== null ? round($change, 2) : null,
                    'state' => $change === null ? 'unknown' : (abs($change) <= $band ? 'normal' : ($change < 0 ? 'down' : 'up')),
                    'reason' => $reason,
                ];
            }
        }

        $classification = $this->classify($signals, $band);
        $health = $this->health($signals, $band);
        $decision = $this->decisionState($health, $classification);

        return [
            'entity' => $entity,
            'health' => $health,
            'decision_state' => $decision,
            'band_percent' => $band,
            'stages' => $this->stageSummary($signals),
            'stage_summary_note' => 'Directional metric summary, not causal attribution. Cost increases are adverse; volume/spend changes are neutral. Mixed directions remain mixed, and partial means unknown operands remain. Inspect individual signals even when one directional result is shown.',
            'signals' => $signals,
            'classification' => $classification,
            'note' => 'Changes are window-over-window comparisons of platform-reported data; a classification is a hypothesis to check, not a proven cause.',
        ];
    }

    /** Rule table from spec sections 34-35, keyed by the observed signal pattern. */
    private function classify(array $s, float $band): ?array
    {
        $state = fn(string $k) => $s[$k]['state'] ?? 'unknown';

        // Pre-click deterioration: cost per impression stable, click-through down,
        // cost per click up, on-site conversion stable (spec section 34).
        if ($state('cpm') === 'normal' && $state('ctr') === 'down' && $state('cpc') === 'up' && $state('cvr') === 'normal') {
            return [
                'pattern' => 'pre_click_deterioration',
                'stage' => 'traffic',
                'hypotheses' => ['creative fatigue or weaker creative', 'audience quality or saturation', 'placement mix shift'],
                'evidence' => $this->evidence($s, ['cpm', 'ctr', 'cpc', 'cvr']),
            ];
        }

        // Post-click deterioration: traffic stable, conversion rate down, CPA up (spec section 35).
        if ($state('ctr') === 'normal' && $state('cpc') === 'normal' && $state('cvr') === 'down' && $state('cpa') === 'up') {
            return [
                'pattern' => 'post_click_deterioration',
                'stage' => 'conversion',
                'hypotheses' => ['landing page problem', 'audience quality (wrong clicks)', 'conversion tracking gap', 'offer change', 'device or geo mix shift'],
                'evidence' => $this->evidence($s, ['ctr', 'cpc', 'cvr', 'cpa']),
            ];
        }

        // Delivery contraction: spend and impressions both down beyond the band.
        if ($state('spend') === 'down' && $state('impressions') === 'down') {
            return [
                'pattern' => 'delivery_contraction',
                'stage' => 'delivery',
                'hypotheses' => ['budget or bid limit', 'campaign status or schedule change', 'audience too narrow', 'platform delivery learning'],
                'evidence' => $this->evidence($s, ['spend', 'impressions']),
            ];
        }

        // Cost inflation: CPM up beyond band while click-through holds.
        if ($state('cpm') === 'up' && $state('ctr') === 'normal') {
            return [
                'pattern' => 'auction_cost_inflation',
                'stage' => 'delivery',
                'hypotheses' => ['auction competition', 'seasonal CPM rise', 'placement cost shift'],
                'evidence' => $this->evidence($s, ['cpm', 'ctr']),
            ];
        }

        return null; // no clean single-stage pattern; signals are still returned raw
    }

    private function health(array $s, float $band): string
    {
        $worst = 0.0;
        $known = 0;
        foreach (['ctr', 'cvr', 'cpa', 'roas'] as $k) {
            $sig = $s[$k] ?? null;
            if (!$sig || $sig['change_percent'] === null) {
                continue;
            }
            $known++;
            $bad = $sig['change_percent'];
            // For cpa, up is bad; for the others, down is bad.
            $harm = $k === 'cpa' ? $bad : -$bad;
            $worst = max($worst, $harm);
        }
        if ($worst >= 2 * $band) {
            return 'critical';
        }
        if ($worst >= $band) {
            return 'warning';
        }
        return $known === 4 ? 'healthy' : 'insufficient_data';
    }

    private function decisionState(string $health, ?array $classification): string
    {
        return match ($health) {
            'critical' => 'INVESTIGATE',
            'insufficient_data' => 'INSUFFICIENT_DATA',
            'warning' => $classification !== null ? 'MONITOR' : 'MONITOR',
            default => $classification !== null ? 'MONITOR' : 'NO_ACTION_REQUIRED',
        };
    }

    private function stageSummary(array $signals): array
    {
        $out = [];
        foreach (self::STAGES as $stage) {
            $directions = [];
            foreach ($signals as $k => $sig) {
                if ($sig['stage'] !== $stage) continue;
                if ($sig['state'] === 'unknown') { $directions[]='unknown'; continue; }
                if ($sig['state'] === 'normal') { $directions[]='stable'; continue; }
                // Volume changes and spend are not good/bad in isolation. Costs run opposite to rates/value.
                $higherIsBetter = match($k) {
                    'ctr','cvr','roas','aov','conversion_value' => true,
                    'cpm','cpc','cpa' => false,
                    default => null,
                };
                $directions[] = $higherIsBetter === null ? 'changing' :
                    (($sig['state']==='up')===$higherIsBetter ? 'improving' : 'deteriorating');
            }
            $has=fn(string $v)=>in_array($v,$directions,true);
            $out[$stage] = $has('deteriorating') && $has('improving') ? 'mixed' :
                ($has('deteriorating') ? 'deteriorating' : ($has('improving') ? 'improving' :
                ($has('changing') ? 'changing' : ($has('unknown') ? ($has('stable') ? 'partial' : 'unknown') : 'stable'))));
        }
        return $out;
    }

    private function evidence(array $s, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = [
                'current' => $s[$k]['current'] ?? null,
                'baseline' => $s[$k]['baseline'] ?? null,
                'change_percent' => $s[$k]['change_percent'] ?? null,
            ];
        }
        return $out;
    }

    /** Derived funnel metrics; null where a denominator is zero (never a substitute). */
    private function normalize(array $t): array
    {
        $g = fn(string $k) => isset($t[$k]) && is_numeric($t[$k]) && is_finite((float) $t[$k]) && (float)$t[$k]>=0 ? (float) $t[$k] : null;
        $spend = $g('spend');
        $imp = $g('impressions');
        $clicks = $g('clicks');
        $conv = $g('conversions');
        $value = $g('conversion_value');
        return [
            'spend' => $spend,
            'impressions' => $imp,
            'clicks' => $clicks,
            'conversions' => $conv,
            'conversion_value' => $value,
            'cpm' => $spend !== null && $imp ? $spend / $imp * 1000 : null,
            'ctr' => $clicks!==null && $imp ? $clicks / $imp : null,
            'cpc' => $spend!==null && $clicks ? $spend / $clicks : null,
            'cvr' => $conv!==null && $clicks ? $conv / $clicks : null,
            'cpa' => $spend!==null && $conv ? $spend / $conv : null,
            'aov' => $value!==null && $conv ? $value / $conv : null,
            'roas' => $value!==null && $spend ? $value / $spend : null,
        ];
    }
}
