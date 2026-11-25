<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PerformanceMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LookerStudioController extends Controller
{
    /**
     * Looker Studio Connector Endpoint (Schema & Data).
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Schema Request
        if ($request->boolean('schema')) {
            return response()->json([
                'schema' => [
                    // Dimensions
                    ['name' => 'date', 'label' => 'Date', 'dataType' => 'YEAR_MONTH_DAY', 'semantics' => ['conceptType' => 'DIMENSION']],
                    ['name' => 'provider', 'label' => 'Platform', 'dataType' => 'STRING', 'semantics' => ['conceptType' => 'DIMENSION']],
                    ['name' => 'account_name', 'label' => 'Account Name', 'dataType' => 'STRING', 'semantics' => ['conceptType' => 'DIMENSION']],
                    ['name' => 'campaign_name', 'label' => 'Campaign Name', 'dataType' => 'STRING', 'semantics' => ['conceptType' => 'DIMENSION']],
                    ['name' => 'currency', 'label' => 'Currency', 'dataType' => 'STRING', 'semantics' => ['conceptType' => 'DIMENSION']],
                    // Metrics
                    ['name' => 'spend', 'label' => 'Cost / Spend', 'dataType' => 'NUMBER', 'semantics' => ['conceptType' => 'METRIC', 'isReaggregatable' => true]],
                    ['name' => 'impressions', 'label' => 'Impressions', 'dataType' => 'NUMBER', 'semantics' => ['conceptType' => 'METRIC', 'isReaggregatable' => true]],
                    ['name' => 'clicks', 'label' => 'Clicks', 'dataType' => 'NUMBER', 'semantics' => ['conceptType' => 'METRIC', 'isReaggregatable' => true]],
                    ['name' => 'conversions', 'label' => 'Conversions', 'dataType' => 'NUMBER', 'semantics' => ['conceptType' => 'METRIC', 'isReaggregatable' => true]],
                    ['name' => 'conversion_value', 'label' => 'Conversion Value', 'dataType' => 'NUMBER', 'semantics' => ['conceptType' => 'METRIC', 'isReaggregatable' => true]],
                    ['name' => 'cpc', 'label' => 'Average CPC', 'dataType' => 'NUMBER', 'semantics' => ['conceptType' => 'METRIC', 'isReaggregatable' => false]],
                    ['name' => 'roas', 'label' => 'ROAS', 'dataType' => 'NUMBER', 'semantics' => ['conceptType' => 'METRIC', 'isReaggregatable' => false]],
                ],
            ]);
        }

        // 2. Data Request
        $startDate = $request->query('start_date', Carbon::now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->query('end_date', Carbon::now()->format('Y-m-d'));

        // Tenancy / User Scoping (SEC-POST-01) - indexed subquery
        $userId = $request->user()->id;

        if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->userProblem($userId,$startDate,$endDate))return response()->json(['success'=>false,'message'=>$problem],409);
        $query = PerformanceMetric::query()
            ->join('connected_ad_accounts', 'performance_metrics.connected_ad_account_id', '=', 'connected_ad_accounts.id')
            ->leftJoin('campaigns', 'performance_metrics.campaign_id', '=', 'campaigns.id')
            ->whereIn('performance_metrics.connected_ad_account_id', function ($sub) use ($userId) {
                $sub->select('id')->from('connected_ad_accounts')->where('user_id', $userId);
            })
            ->whereBetween('performance_metrics.date', [$startDate, $endDate])
            ->select([
                'performance_metrics.date',
                'performance_metrics.provider',
                'connected_ad_accounts.account_name',
                DB::raw('COALESCE(campaigns.name, \'Uncategorized\') as campaign_name'),
                'performance_metrics.currency',
                DB::raw('SUM(performance_metrics.spend) as spend'),
                DB::raw('SUM(performance_metrics.impressions) as impressions'),
                DB::raw('SUM(performance_metrics.clicks) as clicks'),
                DB::raw('SUM(performance_metrics.conversions) as conversions'),
                DB::raw('SUM(performance_metrics.conversion_value) as conversion_value'),
            ])
            ->groupBy([
                'performance_metrics.date',
                'performance_metrics.provider',
                'connected_ad_accounts.account_name',
                'campaigns.name',
                'performance_metrics.currency',
            ])
            ->orderBy('performance_metrics.date', 'desc');

        $results = $query->limit(5000)->get();

        $rows = $results->map(function ($row) {
            $spend = (float) $row->spend;
            $clicks = (int) $row->clicks;
            $convValue = (float) $row->conversion_value;

            $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0.0;
            $roas = $spend > 0 ? round($convValue / $spend, 2) : 0.0;

            return [
                'values' => [
                    Carbon::parse($row->date)->format('Ymd'), // YYYYMMDD for Looker Studio
                    $row->provider,
                    $row->account_name,
                    $row->campaign_name,
                    $row->currency,
                    $spend,
                    (int) $row->impressions,
                    $clicks,
                    (float) $row->conversions,
                    $convValue,
                    $cpc,
                    $roas,
                ],
            ];
        });

        return response()->json([
            'schema' => [
                ['name' => 'date'],
                ['name' => 'provider'],
                ['name' => 'account_name'],
                ['name' => 'campaign_name'],
                ['name' => 'currency'],
                ['name' => 'spend'],
                ['name' => 'impressions'],
                ['name' => 'clicks'],
                ['name' => 'conversions'],
                ['name' => 'conversion_value'],
                ['name' => 'cpc'],
                ['name' => 'roas'],
            ],
            'rows' => $rows,
        ]);
    }
}
