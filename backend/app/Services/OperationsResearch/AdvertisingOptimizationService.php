<?php

namespace App\Services\OperationsResearch;

use App\Models\PerformanceMetric;
use App\Models\ConnectedAdAccount;
use App\Services\OperationsResearch\Contracts\OptimizationStrategyInterface;
use App\Services\OperationsResearch\DTOs\ChannelParameterDto;
use App\Services\OperationsResearch\DTOs\OptimizationResultDto;
use App\Services\OperationsResearch\Strategies\ConvexMarginalReturnOptimizer;
use Illuminate\Support\Facades\DB;

class AdvertisingOptimizationService
{
    private OptimizationStrategyInterface $optimizer;

    /** ChannelParameterDto[] of the most recent optimize call (for downstream scenario/sensitivity tooling). */
    public array $lastChannelParams = [];

    public function __construct(?OptimizationStrategyInterface $optimizer = null)
    {
        $this->optimizer = $optimizer ?? new ConvexMarginalReturnOptimizer();
    }

    private const PLATFORMS = [
        'meta' => 'Meta Ads (Facebook & Instagram)',
        'google' => 'Google Ads (Search & Performance Max)',
        'tiktok' => 'TikTok Ads (Spark & In-Feed)',
        'snapchat' => 'Snapchat Ads',
        'taboola' => 'Taboola Ads',
        'pinterest' => 'Pinterest Ads',
        'reddit' => 'Reddit Ads',
        'spotify' => 'Spotify Ads',
        'linkedin' => 'LinkedIn Ads',
        'microsoft' => 'Microsoft Advertising',
    ];

    /**
     * Data-quality gates (engineering defaults, NOT business policy; documented in docs/OR_ENGINE.md).
     * A channel below these gates is excluded and reported, never filled with substitute numbers.
     */
    public const WINDOW_DAYS = 30;
    public const MIN_DAYS_WITH_DATA = 14;
    public const MAX_STALENESS_DAYS = 3;

    /** Gates in effect: config/or.php (env-tunable), falling back to the constants. */
    public function gates(): array
    {
        return [
            'min_days_with_data' => (int) config('or.min_days_with_data', self::MIN_DAYS_WITH_DATA),
            'max_staleness_days' => (int) config('or.max_staleness_days', self::MAX_STALENESS_DAYS),
            'maintain_band_percent' => (float) config('or.maintain_band_percent', 5),
            'calibrated' => false,
        ];
    }

    /**
     * Optimize from the user's own synced data. Experimental scenario output only.
     *
     * @param array $options budget (required), budget_mode (exact|at_most), min_marginal_roas,
     *                       saturation_multiplier (required explicit assumption), channel_limits[provider]=[min,max]
     */
    public function optimizeForUser(int $userId, array $options = []): OptimizationResultDto
    {
        $window = $this->resolveWindow($userId);
        $this->lastChannelParams=[];
        if($window){$sourceRows=PerformanceMetric::whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$userId)->select('id'))->whereBetween('date',[$window['start'],$window['end']])->limit(30001)->get(['provider','metadata','connected_ad_account_id']);
            if($sourceRows->count()>30000)return OptimizationResultDto::abstain('local_limit','Provider definition transition check exceeds the local30000-row cap.',(float)($options['budget']??0));
            if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->problem($sourceRows))return OptimizationResultDto::abstain('incomparable_metric_definitions',$problem,(float)($options['budget']??0));}
        $metrics = $window ? $this->fetchHistoricalMetrics($userId, $window) : [];
        if ($window) {
            $options['window'] = $window;
        }
        return $this->optimizeFromMetrics($metrics, $options);
    }

    /**
     * Legacy engineering age cutoff only, NOT source or conversion finality evidence. Snap canonical rows are now independently blocked. Nonconversion metrics have a separate returned watermark; conversion-time metrics require their processing watermark. The older 48h heuristic was based on the AD ACCOUNT timezone
     * (https://developers.snap.com/marketing-api/Ads-API/measurement). Day D ends at 00:00 of D+1 and is final at 00:00 of D+3,
     * so the newest usable day is (today in that timezone) minus 3 days. An account without a valid timezone has no usable day.
     *
     * @return array<int,string> account id => newest final date
     */
    public static function snapchatFinalCaps($accounts): array
    {
        $caps = [];
        foreach ($accounts as $a) {
            if ($a->provider !== 'snapchat') {
                continue;
            }
            try {
                $caps[$a->id] = now($a->timezone ?: null)->subDays(3)->toDateString();
                if (!$a->timezone) {
                    unset($caps[$a->id]);
                }
            } catch (\Throwable $e) {
                // invalid timezone: excluded
            }
        }
        return $caps;
    }

    private function applySnapchatFinality($query, array $caps)
    {
        return $query->where(function ($q) use ($caps) {
            $q->where('provider', '!=', 'snapchat');
            foreach ($caps as $id => $cap) {
                $q->orWhere(fn($w) => $w->where('connected_ad_account_id', $id)->whereDate('date', '<=', $cap));
            }
        });
    }

    /**
     * One common window of WINDOW_DAYS complete days for every channel.
     * It ends at the last synced day of the oldest channel that is still fresh enough (staleness gate),
     * never at today (today is incomplete). Newer days of fresher channels are not used, so all channels cover the same dates.
     *
     * @return array{start:string,end:string,days:int}|null
     */
    public function resolveWindow(int $userId): ?array
    {
        $accountModels = ConnectedAdAccount::where('user_id', $userId)->get(['id', 'provider', 'timezone']);
        $accounts = $accountModels->pluck('id');
        if ($accounts->isEmpty()) {
            return null;
        }
        $yesterday = now()->subDay()->toDateString();
        $rows = $this->applySnapchatFinality(PerformanceMetric::whereIn('connected_ad_account_id', $accounts), self::snapchatFinalCaps($accountModels))
            ->whereDate('date', '<=', $yesterday)
            ->select('provider', DB::raw('MAX(date) as latest_date'))
            ->groupBy('provider')
            ->get();
        $maxStale = (int) config('or.max_staleness_days', self::MAX_STALENESS_DAYS);
        $ends = [];
        foreach ($rows as $r) {
            $latest = substr((string) $r->latest_date, 0, 10);
            if ((strtotime(now()->toDateString()) - strtotime($latest)) / 86400 <= $maxStale) {
                $ends[] = $latest;
            }
        }
        if (!$ends) {
            return null;
        }
        $end = min($ends);
        return ['start' => date('Y-m-d', strtotime($end . ' -' . (self::WINDOW_DAYS - 1) . ' days')), 'end' => $end, 'days' => self::WINDOW_DAYS];
    }

    /**
     * Pure entry point (no database). $metrics keyed by provider:
     * total_spend, total_revenue, days_with_data, latest_date (Y-m-d), currencies (string[]).
     */
    public function optimizeFromMetrics(array $metrics, array $options = [], ?string $today = null): OptimizationResultDto
    {
        $gates = $this->gates();
        if (!empty($options['window'])) {
            $gates['window'] = $options['window'];
        }
        return $this->innerOptimize($metrics, $options, $today)->withGates($gates);
    }

    private function innerOptimize(array $metrics, array $options, ?string $today): OptimizationResultDto
    {
        $budget = isset($options['budget']) ? (float) $options['budget'] : null;
        $bad = fn($v) => $v !== null && (!is_numeric($v) || !is_finite((float) $v));
        if ($bad($budget) || $bad($options['saturation_multiplier'] ?? null) || $bad($options['min_marginal_roas'] ?? null)) {
            return OptimizationResultDto::abstain('constraints_required', 'A numeric input is not a finite number (NaN or infinite). Nothing was computed.', 0.0);
        }
        if (isset($options['min_marginal_roas']) && ($options['budget_mode'] ?? 'exact') !== 'at_most') {
            return OptimizationResultDto::abstain('constraints_required', 'min_marginal_roas applies only with budget_mode=at_most. It was sent with another mode, so nothing was computed and the value was not ignored.', max(0.0, (float) $budget));
        }
        foreach (($options['channel_limits'] ?? []) as $lim) {
            foreach (['min', 'max'] as $k) {
                if (isset($lim[$k]) && (!is_numeric($lim[$k]) || !is_finite((float) $lim[$k]) || (float) $lim[$k] < 0)) {
                    return OptimizationResultDto::abstain('constraints_required', 'A channel limit is negative or not a finite number. Nothing was computed.', max(0.0, (float) $budget));
                }
            }
        }
        $horizon = $options['budget_horizon'] ?? null;
        if ($budget !== null && !in_array($horizon, ['daily', 'per_30_days'], true)) {
            return OptimizationResultDto::abstain(
                'constraints_required',
                'budget_horizon is required (daily or per_30_days). The data window is ' . self::WINDOW_DAYS . ' days and a bare "monthly" budget is not assumed equal to it.',
                max(0.0, $budget)
            );
        }
        // Normalise to the data window with a stated rule: daily x window days.
        if ($budget !== null && $horizon === 'daily') {
            $budget *= self::WINDOW_DAYS;
        }
        $profile = $options['profile'] ?? [];

        // Channel limits carry their own explicit period; it is never assumed from the budget's.
        $limits = $options['channel_limits'] ?? [];
        if (!empty($limits)) {
            $lh = $options['channel_limits_horizon'] ?? null;
            if (!in_array($lh, ['daily', 'per_30_days'], true)) {
                return OptimizationResultDto::abstain(
                    'constraints_required',
                    'channel_limits_horizon (daily or per_30_days) is required whenever channel_limits are given. It is not taken from the budget horizon.',
                    max(0.0, (float) $budget)
                );
            }
            if ($lh === 'daily') {
                foreach ($limits as $ch => $lim) {
                    foreach (['min', 'max'] as $k) {
                        if (isset($lim[$k])) {
                            $limits[$ch][$k] = (float) $lim[$k] * self::WINDOW_DAYS;
                        }
                    }
                }
                $options['channel_limits'] = $limits;
            }
        }

        // A stored cap without a valid period is not silently ignored.
        if (!empty($profile['budget_cap']) && !in_array($profile['budget_cap_horizon'] ?? null, ['daily', 'per_30_days'], true)) {
            return OptimizationResultDto::abstain(
                'constraints_required',
                'Your profile has a budget cap but no valid period (daily or per_30_days). Fix the profile before running a scenario; the cap is not ignored.',
                max(0.0, (float) $budget)
            );
        }
        $multiplier = isset($options['saturation_multiplier']) ? (float) $options['saturation_multiplier'] : null;

        if ($budget === null || $budget <= 0.0 || $multiplier === null || $multiplier <= 1.0) {
            return OptimizationResultDto::abstain(
                'constraints_required',
                'A positive budget and an explicit saturation assumption (saturation_multiplier > 1, the revenue ceiling as a multiple of observed revenue) are required. Neither is defaulted.',
                max(0.0, (float) $budget)
            );
        }

        [$params, $excluded, $currencies] = $this->buildChannelParameters($metrics, $multiplier, $options['channel_limits'] ?? [], $budget, $today, $this->gates());
        $this->lastChannelParams = $params;
        // An explicit limit on a channel that is not in the model cannot be honoured: refuse, never ignore.
        $modelled = array_map(fn(ChannelParameterDto $p) => $p->channel, $params);
        $unmet = array_values(array_diff(array_keys($options['channel_limits'] ?? []), $modelled));
        if ($unmet) {
            return OptimizationResultDto::abstain(
                'excluded_channel_has_limits',
                'You set limits on ' . implode(', ', $unmet) . ', but that channel is excluded from the model (see warnings). The result would not satisfy your constraints, so nothing was computed. Remove the limit or fix the channel data.',
                $budget, $excluded
            );
        }
        $sources = array_map(fn(ChannelParameterDto $p) => $p->channel, $params);

        // Explicit user cap is never exceeded; the same horizon rule applies to the cap.
        if (!empty($profile['budget_cap']) && in_array($profile['budget_cap_horizon'] ?? null, ['daily', 'per_30_days'], true)) {
            $cap = (float) $profile['budget_cap'] * ($profile['budget_cap_horizon'] === 'daily' ? self::WINDOW_DAYS : 1);
            if ($budget > $cap + 0.005) {
                return OptimizationResultDto::abstain(
                    'exceeds_user_cap',
                    'The requested budget over the ' . self::WINDOW_DAYS . '-day window (' . number_format($budget, 2, '.', '') . ') exceeds your stated budget cap (' . number_format($cap, 2, '.', '') . '). Nothing was computed.',
                    $budget, $excluded
                );
            }
        }

        // Business currency (if stated) must match the channels. No conversion is ever applied.
        if (!empty($profile['currency']) && count($currencies) >= 1 && $currencies !== [strtoupper((string) $profile['currency'])]) {
            return OptimizationResultDto::abstain(
                'currency_mismatch',
                'Your business currency (' . strtoupper((string) $profile['currency']) . ') differs from the channel currency (' . implode(', ', $currencies) . '). The model needs a single currency and does not convert silently; the original platform figures are unchanged.',
                $budget, $excluded
            );
        }

        if (count($currencies) > 1) {
            return OptimizationResultDto::abstain(
                'mixed_currency',
                'Channels report different currencies (' . implode(', ', $currencies) . '). No conversion is applied here, so no numbers were combined.',
                $budget, $excluded
            );
        }

        if (count($params) < 2) {
            return OptimizationResultDto::abstain(
                'insufficient_data',
                'Fewer than two channels pass the data-quality gates (spend and revenue present, data on every one of the ' . self::WINDOW_DAYS . ' days of the window unless partial-window scaling is enabled, synced within ' . $this->gates()['max_staleness_days'] . ' days, single currency). Nothing was filled in.',
                $budget, $excluded, $sources
            );
        }

        $constraints = [
            'budget_mode' => $options['budget_mode'] ?? 'exact',
            'min_marginal_roas' => $options['min_marginal_roas'] ?? null,
        ];
        $result = $this->optimizer->optimize($budget, $params, $constraints);

        if ($result->status !== 'ok') {
            return OptimizationResultDto::abstain($result->status, (string) $result->statusMessage, $budget, $excluded, $sources);
        }

        if (($options['budget_mode'] ?? 'exact') === 'at_most') {
            $spendSum = 0.0;
            foreach ($result->channelAllocations as $al) {
                $spendSum += (float) (is_array($al) ? ($al['optimal_spend'] ?? 0) : ($al->optimalSpend ?? 0));
            }
            if ($spendSum <= 1e-9) {
                return OptimizationResultDto::abstain('no_allocation', 'With budget_mode=at_most, no channel clears the minimum marginal ROAS you set, so the scenario spends nothing. No allocation is shown.', $budget, $excluded, $sources);
            }
        }

        $warnings = array_merge($excluded, [
            'EXPERIMENTAL SCENARIO, not an operational recommendation: the response curve is an assumption (revenue ceiling = ' . $multiplier . 'x observed revenue), not fitted or validated.',
            'Spend and revenue are platform-reported and attributed per platform. Revenue here is reported conversion value: not profit, not unique sales; conversions across platforms can overlap.',
            'Nothing is applied automatically. Read-only.',
        ], !empty($options['window']) ? ['Data window: ' . $options['window']['start'] . ' to ' . $options['window']['end'] . ' (' . self::WINDOW_DAYS . ' complete days, the same dates for every channel; it ends at the oldest fresh channel\'s last synced day).'] : []);

        return new OptimizationResultDto(
            totalBudget: $result->totalBudget,
            currentTotalRevenue: $result->currentTotalRevenue,
            currentBlendedRoas: $result->currentBlendedRoas,
            optimalTotalRevenue: $result->optimalTotalRevenue,
            optimalBlendedRoas: $result->optimalBlendedRoas,
            revenueGainDelta: $result->revenueGainDelta,
            roasGainDelta: $result->roasGainDelta,
            equilibriumLambda: $result->equilibriumLambda,
            channelAllocations: $result->channelAllocations,
            recommendations: $result->recommendations,
            frontierCurve: $result->frontierCurve,
            sensitivityPoints: $result->sensitivityPoints,
            mathematicalModel: $result->mathematicalModel,
            status: 'ok',
            statusMessage: 'Experimental scenario under stated assumptions. Not an operational recommendation.',
            warnings: $warnings,
            dataSources: $sources,
            certificate: $result->certificate,
            comparison: $result->comparison
        );
    }

    private function fetchHistoricalMetrics(int $userId, array $window): array
    {
        $accountModels = ConnectedAdAccount::where('user_id', $userId)->get(['id', 'provider', 'timezone']);
        $accounts = $accountModels->pluck('id');

        if ($accounts->isEmpty()) {
            return [];
        }

        // performance_metrics stores the channel in the `provider` column.
        $rows = $this->applySnapchatFinality(PerformanceMetric::whereIn('connected_ad_account_id', $accounts), self::snapchatFinalCaps($accountModels))
            ->whereDate('date', '>=', $window['start'])
            ->whereDate('date', '<=', $window['end'])
            ->select(
                'provider',
                'currency',
                DB::raw('COALESCE(SUM(spend), 0) as total_spend'),
                DB::raw('COALESCE(SUM(conversion_value), 0) as total_revenue'),
                DB::raw('COUNT(DISTINCT date) as days_with_data'),
                DB::raw('MAX(date) as latest_date')
            )
            ->groupBy('provider', 'currency')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $k = $r->provider;
            $out[$k] ??= ['total_spend' => 0.0, 'total_revenue' => 0.0, 'days_with_data' => 0, 'latest_date' => null, 'currencies' => []];
            $out[$k]['total_spend'] += (float) $r->total_spend;
            $out[$k]['total_revenue'] += (float) $r->total_revenue;
            $out[$k]['days_with_data'] = max($out[$k]['days_with_data'], (int) $r->days_with_data);
            $out[$k]['latest_date'] = max((string) $out[$k]['latest_date'], (string) $r->latest_date);
            $out[$k]['currencies'][] = (string) $r->currency;
        }

        return $out;
    }

    /**
     * Only channels that pass the quality gates are modelled. No default or substitute values.
     *
     * @return array{0: ChannelParameterDto[], 1: string[], 2: string[]} params, exclusion notes, currencies seen
     */
    private function buildChannelParameters(array $metrics, float $multiplier, array $limits, float $budget, ?string $today, array $gates): array
    {
        $params = [];
        $excluded = [];
        $currencies = [];
        $todayTs = strtotime($today ?? date('Y-m-d'));

        foreach (self::PLATFORMS as $platform => $display) {
            $m = $metrics[$platform] ?? null;
            if (!$m) {
                continue;
            }

            $spend = (float) ($m['total_spend'] ?? 0);
            $revenue = (float) ($m['total_revenue'] ?? 0);
            $days = min(self::WINDOW_DAYS, (int) ($m['days_with_data'] ?? 0));
            $latest = $m['latest_date'] ?? null;
            $cur = array_values(array_unique(array_filter((array) ($m['currencies'] ?? []))));

            if ($spend <= 0 || $revenue <= 0) {
                $excluded[] = "$platform excluded: zero spend or zero reported revenue in the window.";
                continue;
            }
            if (count($cur) === 0) {
                $excluded[] = "$platform excluded: currency is unknown, so it cannot be matched with the budget. Nothing was assumed.";
                continue;
            }
            if (count($cur) > 1) {
                $excluded[] = "$platform excluded: multiple currencies inside one channel (" . implode(', ', $cur) . ').';
                $currencies = array_merge($currencies, $cur);
                continue;
            }
            if ($days < self::WINDOW_DAYS && !config('or.allow_partial_window_scaling', false)) {
                $excluded[] = "$platform excluded: only $days of " . self::WINDOW_DAYS . " days have data, so its totals are not comparable with a " . self::WINDOW_DAYS . "-day budget. Nothing was extrapolated.";
                continue;
            }
            if ($days < $gates['min_days_with_data']) {
                $excluded[] = "$platform excluded: only $days days of data (needs " . $gates['min_days_with_data'] . ').';
                continue;
            }
            if (!$latest || ($todayTs - strtotime((string) $latest)) / 86400 > $gates['max_staleness_days']) {
                $excluded[] = "$platform excluded: data is delayed (latest day " . ($latest ?: 'unknown') . ').';
                continue;
            }

            $currencies = array_merge($currencies, $cur);

            // Observed totals cover $days days; the budget is per WINDOW_DAYS days. Scale the observed totals to the
            // window by their daily average. This is a stated linear assumption and is reported when it applies.
            if ($days < self::WINDOW_DAYS) {
                $f = self::WINDOW_DAYS / $days;
                $spend *= $f;
                $revenue *= $f;
                $excluded[] = "$platform: only $days of " . self::WINDOW_DAYS . " days have data; its totals were scaled linearly to " . self::WINDOW_DAYS . " days (assumption).";
            }

            // Assumed curve R = alpha * (1 - exp(-beta * S)) through the observed point,
            // with alpha = multiplier * observed revenue (explicit user assumption).
            $alpha = $revenue * $multiplier;
            $beta = -log1p(-($revenue / $alpha)) / $spend;

            $lim = $limits[$platform] ?? [];
            $min = isset($lim['min']) ? max(0.0, (float) $lim['min']) : 0.0;
            // Without an explicit cap a channel can at most take the whole budget (arithmetic bound, not policy).
            $max = isset($lim['max']) ? (float) $lim['max'] : $budget;

            $params[] = new ChannelParameterDto(
                channel: $platform,
                displayName: $display,
                currentSpend: $spend,
                currentRevenue: $revenue,
                currentRoas: $revenue / $spend,
                alpha: $alpha,
                beta: $beta,
                minSpend: $min,
                maxSpend: $max
            );
        }

        return [$params, $excluded, array_values(array_unique($currencies))];
    }
}
