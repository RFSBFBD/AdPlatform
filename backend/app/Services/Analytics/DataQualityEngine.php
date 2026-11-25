<?php

namespace App\Services\Analytics;

/**
 * Data Quality Engine (Master Spec sections 10, 65, 67).
 *
 * Produces a deterministic 0-100 score per channel from explicit checks. The
 * rubric is an engineering scoring rule (versioned, config-tunable), NOT a
 * calibrated statistical measure; the version is returned with every score so a
 * 94 today and a 94 after a rubric change are never conflated.
 *
 * Below the configured portfolio threshold the platform's answer is
 * DO NOT OPTIMIZE / INSUFFICIENT_DATA (spec section 67), not a weaker optimization.
 */
final class DataQualityEngine
{
    /**
     * Score one channel from plain facts (pure function; the caller gathers facts).
     *
     * @param array $facts {
     *   days_with_data:int, window_days:int, latest_date:?string(Y-m-d), today:string(Y-m-d),
     *   currencies:string[], has_conversion_value:bool, duplicate_rows:int,
     *   last_synced_at:?string, syncs_failed:int
     * }
     * @return array{score:float,level:string,checks:array,rubric_version:string}
     */
    public function scoreChannel(array $facts): array
    {
        $cfg = config('analytics.data_quality');
        $checks = [];
        $deduct = 0.0;

        $windowDays = max(1, (int) ($facts['window_days'] ?? 30));
        $days = max(0, (int) ($facts['days_with_data'] ?? 0));
        $missing = max(0, $windowDays - min($days, $windowDays));
        if ($missing > 0) {
            $d = min($missing * (float) $cfg['missing_day_deduction'], (float) $cfg['missing_day_deduction_cap']);
            $deduct += $d;
            $checks[] = $this->check('completeness', 'fail', $d, "Only {$days} of {$windowDays} window days have data ({$missing} missing). Delayed conversions and reporting lag can cause recent gaps.");
        } else {
            $checks[] = $this->check('completeness', 'pass', 0, "All {$windowDays} window days have data.");
        }

        $latest = $facts['latest_date'] ?? null;
        $today = (string) ($facts['today'] ?? date('Y-m-d'));
        $staleDays = $latest ? max(0, (int) floor((strtotime($today) - strtotime(substr((string) $latest, 0, 10))) / 86400)) : null;
        if ($latest === null) {
            $d = (float) $cfg['stale_day_deduction_cap'];
            $deduct += $d;
            $checks[] = $this->check('freshness', 'fail', $d, 'No dated rows at all.');
        } elseif ($staleDays > 1) {
            $d = min(($staleDays - 1) * (float) $cfg['stale_day_deduction'], (float) $cfg['stale_day_deduction_cap']);
            $deduct += $d;
            $checks[] = $this->check('freshness', 'fail', $d, "Latest data day is {$latest} ({$staleDays} days before today). Ad platforms report with a lag; very recent days may still change.");
        } else {
            $checks[] = $this->check('freshness', 'pass', 0, "Latest data day is {$latest}.");
        }

        $currencies = array_values(array_unique(array_filter((array) ($facts['currencies'] ?? []))));
        if (count($currencies) === 0) {
            $d = (float) $cfg['unknown_currency_deduction'];
            $deduct += $d;
            $checks[] = $this->check('currency', 'fail', $d, 'Currency is unknown; amounts cannot be interpreted or compared.');
        } elseif (count($currencies) > 1) {
            $d = (float) $cfg['mixed_currency_deduction'];
            $deduct += $d;
            $checks[] = $this->check('currency', 'fail', $d, 'Multiple currencies inside one channel: ' . implode(', ', $currencies) . '.');
        } else {
            $checks[] = $this->check('currency', 'pass', 0, 'Single currency: ' . $currencies[0] . '.');
        }

        if (!($facts['has_conversion_value'] ?? false)) {
            $d = (float) $cfg['no_conversion_value_deduction'];
            $deduct += $d;
            $checks[] = $this->check('conversion_value', 'fail', $d, 'No conversion value reported: return-based metrics (ROAS, revenue curves) are impossible for this channel.');
        } else {
            $checks[] = $this->check('conversion_value', 'pass', 0, 'Conversion value is reported.');
        }

        $dupes = (int) ($facts['duplicate_rows'] ?? 0);
        if ($dupes > 0) {
            $d = (float) $cfg['duplicate_rows_deduction'];
            $deduct += $d;
            $checks[] = $this->check('duplicates', 'fail', $d, "{$dupes} duplicate rows detected for the same entity/day.");
        } else {
            $checks[] = $this->check('duplicates', 'pass', 0, 'No duplicate entity/day rows.');
        }

        $failedSyncs = (int) ($facts['syncs_failed'] ?? 0);
        if ($failedSyncs > 0) {
            $checks[] = $this->check('sync_health', 'warn', 0, "{$failedSyncs} failed sync run(s) in the window; the last successful sync governs freshness.");
        } else {
            $checks[] = $this->check('sync_health', 'pass', 0, 'No failed sync runs in the window.');
        }

        $score = max(0.0, 100.0 - $deduct);
        return [
            'score' => round($score, 1),
            'level' => $score >= 80 ? 'high' : ($score >= (float) $cfg['optimize_threshold'] ? 'medium' : 'low'),
            'checks' => $checks,
            'rubric_version' => (string) $cfg['rubric_version'],
        ];
    }

    /**
     * Portfolio view + the spec-67 gate.
     *
     * @param array<string,array> $channelScores provider => scoreChannel() result
     * @return array{score:?float,level:string,optimize_allowed:bool,threshold:float,gate_reason:?string}
     */
    public function portfolio(array $channelScores): array
    {
        $cfg = config('analytics.data_quality');
        $threshold = (float) $cfg['optimize_threshold'];
        if ($channelScores === []) {
            return [
                'score' => null,
                'level' => 'none',
                'optimize_allowed' => false,
                'threshold' => $threshold,
                'gate_reason' => 'No channel data at all.',
            ];
        }
        // Portfolio score = mean of channel scores (documented aggregation; a weak
        // channel drags the average rather than hiding behind a strong one).
        $score = round(array_sum(array_map(fn($s) => (float) $s['score'], $channelScores)) / count($channelScores), 1);
        $allowed = $score >= $threshold;
        return [
            'score' => $score,
            'level' => $score >= 80 ? 'high' : ($allowed ? 'medium' : 'low'),
            'optimize_allowed' => $allowed,
            'threshold' => $threshold,
            'gate_reason' => $allowed ? null : "Data quality {$score}/100 is below the optimization threshold {$threshold}/100: the answer is INSUFFICIENT_DATA, not a weaker optimization (spec section 67).",
        ];
    }

    private function check(string $id, string $status, float $deduction, string $detail): array
    {
        return ['id' => $id, 'status' => $status, 'deduction' => $deduction, 'detail' => $detail];
    }
}
