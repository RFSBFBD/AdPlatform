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

    public function __construct(?OptimizationStrategyInterface $optimizer = null)
    {
        $this->optimizer = $optimizer ?? new ConvexMarginalReturnOptimizer();
    }

    /**
     * Run full Operations Research analysis and budget optimization for a given user/tenant.
     */
    public function optimizeForUser(int $userId, ?float $customBudget = null): OptimizationResultDto
    {
        // 1. Fetch channel aggregated performance over the last 30 days
        $channelMetrics = $this->fetchHistoricalMetrics($userId);

        // 2. Build mathematical channel parameters
        $channelParams = $this->buildChannelParameters($channelMetrics);

        // 3. Determine total budget (custom or sum of current channel spend)
        $totalCurrentSpend = array_sum(array_map(fn(ChannelParameterDto $p) => $p->currentSpend, $channelParams));
        $effectiveBudget = $customBudget ?? ($totalCurrentSpend > 0 ? $totalCurrentSpend : 12000.0);

        // 4. Run convex optimization
        return $this->optimizer->optimize($effectiveBudget, $channelParams);
    }

    private function fetchHistoricalMetrics(int $userId): array
    {
        $accounts = ConnectedAdAccount::where('user_id', $userId)->pluck('id');

        if ($accounts->isEmpty()) {
            return [];
        }

        return PerformanceMetric::whereIn('connected_ad_account_id', $accounts)
            ->where('date', '>=', now()->subDays(30))
            ->select(
                'platform',
                DB::raw('COALESCE(SUM(spend), 0) as total_spend'),
                DB::raw('COALESCE(SUM(impressions), 0) as total_impressions'),
                DB::raw('COALESCE(SUM(clicks), 0) as total_clicks'),
                DB::raw('COALESCE(SUM(conversions), 0) as total_conversions'),
                DB::raw('COALESCE(SUM(conversion_value), 0) as total_revenue')
            )
            ->groupBy('platform')
            ->get()
            ->keyBy('platform')
            ->toArray();
    }

    private function buildChannelParameters(array $metrics): array
    {
        $platforms = [
            'meta' => [
                'display' => 'Meta Ads (Facebook & Instagram)',
                'default_spend' => 5200.0,
                'default_revenue' => 17160.0, // 3.3x ROAS
                'alpha' => 45000.0,
                'beta' => 0.000105,
            ],
            'google' => [
                'display' => 'Google Ads (Search & Performance Max)',
                'default_spend' => 4800.0,
                'default_revenue' => 19680.0, // 4.1x ROAS
                'alpha' => 60000.0,
                'beta' => 0.000088,
            ],
            'tiktok' => [
                'display' => 'TikTok Ads (Spark & In-Feed)',
                'default_spend' => 2000.0,
                'default_revenue' => 7400.0, // 3.7x ROAS
                'alpha' => 38000.0,
                'beta' => 0.000125,
            ],
        ];

        $params = [];

        foreach ($platforms as $platform => $config) {
            $m = $metrics[$platform] ?? null;

            $spend = $m && $m['total_spend'] > 0 ? (float) $m['total_spend'] : $config['default_spend'];
            $revenue = $m && $m['total_revenue'] > 0 ? (float) $m['total_revenue'] : $config['default_revenue'];
            $roas = $spend > 0 ? ($revenue / $spend) : 3.0;

            // Compute calibrated alpha and beta dynamically if historical spend exists:
            // R = alpha * (1 - exp(-beta * S))
            $alpha = max($revenue * 2.5, $config['alpha']);
            $fraction = min(0.92, $revenue / $alpha);
            $beta = $spend > 0 && $fraction > 0 ? (-log(1.0 - $fraction) / $spend) : $config['beta'];

            $params[] = new ChannelParameterDto(
                channel: $platform,
                displayName: $config['display'],
                currentSpend: $spend,
                currentRevenue: $revenue,
                currentRoas: $roas,
                alpha: $alpha,
                beta: $beta,
                minSpend: 250.0,
                maxSpend: 50000.0
            );
        }

        return $params;
    }
}
