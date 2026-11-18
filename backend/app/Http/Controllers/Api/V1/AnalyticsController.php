<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\PerformanceMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Get aggregated overview metrics, trends, and platform breakdowns.
     */
    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'provider' => ['nullable', 'string', 'in:meta,google,tiktok'],
            'account_id' => ['nullable', 'integer'],
        ]);

        $startDate = $validated['start_date'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');

        $query = PerformanceMetric::query()
            ->whereBetween('date', [$startDate, $endDate]);

        if (!empty($validated['provider'])) {
            $query->where('provider', $validated['provider']);
        }

        if (!empty($validated['account_id'])) {
            $query->where('connected_ad_account_id', $validated['account_id']);
        }

        // 1. Overall Aggregates
        $totals = (clone $query)->select([
            DB::raw('COALESCE(SUM(spend), 0) as total_spend'),
            DB::raw('COALESCE(SUM(impressions), 0) as total_impressions'),
            DB::raw('COALESCE(SUM(clicks), 0) as total_clicks'),
            DB::raw('COALESCE(SUM(conversions), 0) as total_conversions'),
            DB::raw('COALESCE(SUM(conversion_value), 0) as total_conversion_value'),
        ])->first();

        $spend = (float) $totals->total_spend;
        $impressions = (int) $totals->total_impressions;
        $clicks = (int) $totals->total_clicks;
        $conversions = (float) $totals->total_conversions;
        $conversionValue = (float) $totals->total_conversion_value;

        $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0.0;
        $cpm = $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0.0;
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;
        $roas = $spend > 0 ? round($conversionValue / $spend, 2) : 0.0;
        $cpa = $conversions > 0 ? round($spend / $conversions, 2) : 0.0;

        // 2. Platform Breakdown
        $platformBreakdown = (clone $query)
            ->select([
                'provider',
                DB::raw('COALESCE(SUM(spend), 0) as spend'),
                DB::raw('COALESCE(SUM(impressions), 0) as impressions'),
                DB::raw('COALESCE(SUM(clicks), 0) as clicks'),
                DB::raw('COALESCE(SUM(conversions), 0) as conversions'),
                DB::raw('COALESCE(SUM(conversion_value), 0) as conversion_value'),
            ])
            ->groupBy('provider')
            ->get()
            ->map(function ($row) {
                $pSpend = (float) $row->spend;
                $pConvVal = (float) $row->conversion_value;
                return [
                    'provider' => $row->provider,
                    'spend' => $pSpend,
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                    'conversions' => (float) $row->conversions,
                    'roas' => $pSpend > 0 ? round($pConvVal / $pSpend, 2) : 0.0,
                ];
            });

        // 3. Daily Timeline Trend
        $timeline = (clone $query)
            ->select([
                'date',
                DB::raw('COALESCE(SUM(spend), 0) as spend'),
                DB::raw('COALESCE(SUM(clicks), 0) as clicks'),
                DB::raw('COALESCE(SUM(conversions), 0) as conversions'),
                DB::raw('COALESCE(SUM(conversion_value), 0) as conversion_value'),
            ])
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                $daySpend = (float) $item->spend;
                $dayVal = (float) $item->conversion_value;
                return [
                    'date' => $item->date->format('Y-m-d'),
                    'spend' => $daySpend,
                    'clicks' => (int) $item->clicks,
                    'conversions' => (float) $item->conversions,
                    'roas' => $daySpend > 0 ? round($dayVal / $daySpend, 2) : 0.0,
                ];
            });

        return response()->json([
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'spend' => $spend,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'conversions' => $conversions,
                'conversion_value' => $conversionValue,
                'cpc' => $cpc,
                'cpm' => $cpm,
                'ctr' => $ctr,
                'roas' => $roas,
                'cpa' => $cpa,
            ],
            'platforms' => $platformBreakdown,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Get paginated campaign list with aggregated performance metrics.
     */
    public function campaigns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'provider' => ['nullable', 'string', 'in:meta,google,tiktok'],
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $startDate = $validated['start_date'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');
        $perPage = $validated['per_page'] ?? 15;

        $query = Campaign::with('connectedAdAccount:id,account_name,provider,currency');

        if (!empty($validated['provider'])) {
            $query->where('provider', $validated['provider']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('external_campaign_id', 'ilike', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        // Fetch metrics aggregated per campaign in the specified date range
        $campaignIds = $paginator->pluck('id')->toArray();
        $metricsMap = PerformanceMetric::query()
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->select([
                'campaign_id',
                DB::raw('COALESCE(SUM(spend), 0) as spend'),
                DB::raw('COALESCE(SUM(impressions), 0) as impressions'),
                DB::raw('COALESCE(SUM(clicks), 0) as clicks'),
                DB::raw('COALESCE(SUM(conversions), 0) as conversions'),
                DB::raw('COALESCE(SUM(conversion_value), 0) as conversion_value'),
            ])
            ->groupBy('campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $items = $paginator->getCollection()->map(function ($campaign) use ($metricsMap) {
            $m = $metricsMap->get($campaign->id);
            $spend = $m ? (float) $m->spend : 0.0;
            $impressions = $m ? (int) $m->impressions : 0;
            $clicks = $m ? (int) $m->clicks : 0;
            $conversions = $m ? (float) $m->conversions : 0.0;
            $conversionValue = $m ? (float) $m->conversion_value : 0.0;

            $roas = $spend > 0 ? round($conversionValue / $spend, 2) : 0.0;
            $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0.0;
            $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;

            return [
                'id' => $campaign->id,
                'external_campaign_id' => $campaign->external_campaign_id,
                'name' => $campaign->name,
                'provider' => $campaign->provider,
                'status' => $campaign->status,
                'objective' => $campaign->objective,
                'daily_budget' => $campaign->daily_budget,
                'lifetime_budget' => $campaign->lifetime_budget,
                'currency' => $campaign->currency,
                'account_name' => $campaign->connectedAdAccount?->account_name,
                'metrics' => [
                    'spend' => $spend,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'conversions' => $conversions,
                    'conversion_value' => $conversionValue,
                    'roas' => $roas,
                    'cpc' => $cpc,
                    'ctr' => $ctr,
                ],
            ];
        });

        return response()->json([
            'data' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Get drill-down details for a specific campaign.
     */
    public function campaignDetails(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::with([
            'connectedAdAccount:id,account_name,provider,currency,timezone',
            'adSets.ads',
        ])->findOrFail($id);

        $startDate = $request->query('start_date', Carbon::now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->query('end_date', Carbon::now()->format('Y-m-d'));

        $timeline = PerformanceMetric::query()
            ->where('campaign_id', $campaign->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'campaign' => $campaign,
            'timeline' => $timeline,
        ]);
    }
}
