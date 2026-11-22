<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Services\Currency\CurrencyConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function __construct(
        protected CurrencyConversionService $currencyService
    ) {}
    /**
     * Per-platform coverage of what was actually pulled. Read-only, no estimates:
     * a platform with no rows is reported as such, never filled in.
     */
    public function dataCoverage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $start = $validated['start_date'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
        $end = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');

        $data = $this->coverageFor($request->user()->id, $start, $end);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function coverageFor(int $userId, string $start, string $end): array
    {
        $accounts = ConnectedAdAccount::where('user_id', $userId)->get();
        $ids = $accounts->pluck('id');
        $rows = PerformanceMetric::query()
            ->whereIn('connected_ad_account_id', $ids)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->get(['connected_ad_account_id', 'provider', 'date', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'campaign_id']);

        $lastRuns = \App\Models\SyncRun::whereIn('connected_ad_account_id', $ids)
            ->orderByDesc('id')->get()->groupBy('connected_ad_account_id')->map->first();

        $out = [];
        foreach (['meta', 'google', 'tiktok', 'snapchat'] as $provider) {
            $accs = $accounts->where('provider', $provider);
            $pr = $rows->where('provider', $provider);
            $byCurrency = [];
            foreach ($pr->groupBy('currency') as $cur => $g) {
                $byCurrency[$cur] = [
                    'spend' => round((float) $g->sum('spend'), 4),
                    'conversion_value' => round((float) $g->sum('conversion_value'), 4),
                    'impressions' => (int) $g->sum('impressions'),
                    'clicks' => (int) $g->sum('clicks'),
                    'conversions' => round((float) $g->sum('conversions'), 4),
                ];
            }
            $status = $accs->isEmpty() ? 'not_connected' : ($pr->isEmpty() ? 'connected_no_data' : 'data_available');
            $out[] = [
                'provider' => $provider,
                'status' => $status,
                'accounts' => $accs->map(function ($a) use ($lastRuns, $pr) {
                    $run = $lastRuns->get($a->id);
                    return [
                        'id' => $a->id,
                        'name' => $a->account_name,
                        'currency' => $a->currency,
                        'timezone' => $a->timezone,
                        'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                        'last_sync_status' => $run?->status,
                        'last_sync_error' => $run?->status === 'failed' ? $run->error_message : null,
                        'days_with_data' => $pr->where('connected_ad_account_id', $a->id)->pluck('date')->map(fn ($d) => (string) $d)->unique()->count(),
                    ];
                })->values(),
                'days_with_data' => $pr->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->count(),
                'first_date' => $pr->isEmpty() ? null : substr((string) $pr->min('date'), 0, 10),
                'last_date' => $pr->isEmpty() ? null : substr((string) $pr->max('date'), 0, 10),
                'totals_by_currency' => $byCurrency,
                'has_conversion_value' => $pr->where('conversion_value', '>', 0)->isNotEmpty(),
                'has_campaign_level_rows' => $pr->whereNotNull('campaign_id')->isNotEmpty(),
                'gaps' => $this->coverageGaps($provider, $status, $pr),
            ];
        }

        return ['start_date' => $start, 'end_date' => $end, 'platforms' => $out,
            'note' => 'Platform-reported figures, not profit. Nothing here is estimated or converted; totals are per currency.'];
    }

    /**
     * Read-only suggestions grounded only in the pulled data and the OR result. Nothing is executed.
     * Takes the same parameters as the OR endpoint, so both use the same validation.
     */
    public function suggestions(
        Request $request,
        \App\Services\OperationsResearch\AdvertisingOptimizationService $orService,
        \App\Services\Advertising\SuggestionBuilder $builder,
        \App\Services\Advertising\SuggestionNarrator $narrator
    ): JsonResponse {
        $snap = $this->buildSnapshot($request, $orService, $builder);
        $ai = $request->boolean('ai');

        // The AI step may only act on the exact snapshot the user is looking at.
        if ($ai && (string) $request->query('snapshot_id', '') !== $snap['snapshot_id']) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SNAPSHOT_CHANGED',
                    'message' => 'The data or the result changed since the one on screen (for example a sync arrived). Nothing was sent to AI. Solve again to get a fresh result.',
                ],
                'snapshot_id' => $snap['snapshot_id'],
            ], 409);
        }

        $narrated = $narrator->narrate($request->user(), $snap['suggestions'], $ai);
        $narrated['mode']['snapshot_id'] = $snap['snapshot_id'];

        return response()->json([
            'success' => true,
            'data' => [
                'suggestions' => $narrated['suggestions'],
                'mode' => $narrated['mode'],
                'grounding' => ['window' => [$snap['coverage']['start_date'], $snap['coverage']['end_date']], 'window_source' => $snap['window_source'], 'or_status' => $snap['or']['status'] ?? null, 'snapshot_id' => $snap['snapshot_id']],
                'note' => 'Suggestions only. Nothing is applied to any campaign or budget. Budget items are scenarios under an assumed response curve.',
            ],
        ]);
    }

    /**
     * One computation: OR result + coverage on the OR's own window + rules. snapshot_id fingerprints all three, so a changed
     * database (a sync arriving) or a different result gives a different id.
     */
    private function buildSnapshot(Request $request, $orService, $builder): array
    {
        $or = $this->computeOr($request, $orService);
        $w = $or['gates']['window'] ?? null;
        $windowSource = $w ? 'or_window' : 'default_30_days_no_or_window';
        $start = $w['start'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
        $end = $w['end'] ?? Carbon::now()->format('Y-m-d');
        $coverage = $this->coverageFor($request->user()->id, $start, $end);
        $suggestions = $builder->build($coverage, $or);
        $id = substr(hash('sha256', json_encode([$or, $coverage, $suggestions])), 0, 16);

        return ['or' => $or, 'coverage' => $coverage, 'suggestions' => $suggestions, 'snapshot_id' => $id, 'window_source' => $windowSource];
    }

    private function coverageGaps(string $provider, string $status, $rows): array
    {
        if ($status === 'not_connected') return ['No account connected.'];
        if ($status === 'connected_no_data') return ['Connected, but no report rows in this period.'];
        $gaps = [];
        if ($rows->where('conversion_value', '>', 0)->isEmpty()) {
            $gaps[] = 'No purchase value in this period: this platform is left out of the OR model.';
        }
        if ($provider === 'snapchat') {
            $gaps[] = 'Snapchat is account-level only (no campaign breakdown). Swipes are stored as clicks and are not link clicks. Purchase value only for ads tracked with Snap Pixel. Snapchat days become final 48 hours after the end of the day in the account time zone; newer days are left out of the OR model.';
        }
        return $gaps;
    }

    /**
     * Get aggregated overview metrics, trends, and platform breakdowns.
     */
    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'provider' => ['nullable', 'string', 'in:meta,google,tiktok,snapchat'],
            'account_id' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reporting_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $startDate = $validated['start_date'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');
        $targetCurrency = strtoupper($validated['reporting_currency'] ?? ($request->user()->reporting_currency ?: 'USD'));

        // Tenancy / User Scoping (SEC-001)
        $userAccountIds = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');

        $query = PerformanceMetric::query()
            ->whereIn('performance_metrics.connected_ad_account_id', $userAccountIds)
            ->whereBetween('performance_metrics.date', [$startDate, $endDate]);

        if (!empty($validated['provider'])) {
            $query->where('performance_metrics.provider', $validated['provider']);
        }

        if (!empty($validated['account_id'])) {
            $query->where('performance_metrics.connected_ad_account_id', $validated['account_id']);
        }

        if (!empty($validated['currency'])) {
            $query->whereHas('connectedAdAccount', function ($q) use ($validated) {
                $q->where('currency', strtoupper($validated['currency']));
            });
        }

        // 1. Currency Breakdown (DATA-001)
        $currencyBreakdown = (clone $query)
            ->join('connected_ad_accounts', 'performance_metrics.connected_ad_account_id', '=', 'connected_ad_accounts.id')
            ->select([
                'connected_ad_accounts.currency',
                DB::raw('COALESCE(SUM(performance_metrics.spend), 0) as spend'),
                DB::raw('COALESCE(SUM(performance_metrics.conversion_value), 0) as conversion_value'),
            ])
            ->groupBy('connected_ad_accounts.currency')
            ->get()
            ->map(function ($row) {
                return [
                    'currency' => $row->currency ?? 'USD',
                    'spend' => (float) $row->spend,
                    'conversion_value' => (float) $row->conversion_value,
                ];
            });

        // 2. Fetch full rows for accurate date-specific currency conversion
        $metricRows = (clone $query)
            ->join('connected_ad_accounts', 'performance_metrics.connected_ad_account_id', '=', 'connected_ad_accounts.id')
            ->select([
                'performance_metrics.date',
                'performance_metrics.provider',
                'performance_metrics.spend',
                'performance_metrics.impressions',
                'performance_metrics.clicks',
                'performance_metrics.conversions',
                'performance_metrics.conversion_value',
                'connected_ad_accounts.currency',
            ])
            ->get();

        $conversionResult = $this->currencyService->convertMetricsRows($metricRows, $targetCurrency);
        $convertedRows = collect($conversionResult['rows']);

        // 3. Platform Breakdown (Normalized in Target Currency)
        $platformBreakdown = $convertedRows
            ->groupBy('provider')
            ->map(function ($group, $provider) {
                $pSpend = (float) $group->sum('converted_spend');
                $pConvVal = (float) $group->sum('converted_conversion_value');
                return [
                    'provider' => $provider,
                    'spend' => round($pSpend, 2),
                    'impressions' => (int) $group->sum('impressions'),
                    'clicks' => (int) $group->sum('clicks'),
                    'conversions' => (float) $group->sum('conversions'),
                    'roas' => $pSpend > 0 ? round($pConvVal / $pSpend, 2) : 0.0,
                ];
            })
            ->values();

        // 4. Daily Timeline Trend (Normalized in Target Currency)
        $timeline = $convertedRows
            ->groupBy('date')
            ->map(function ($group, $date) {
                $daySpend = (float) $group->sum('converted_spend');
                $dayVal = (float) $group->sum('converted_conversion_value');
                return [
                    'date' => $date,
                    'spend' => round($daySpend, 2),
                    'clicks' => (int) $group->sum('clicks'),
                    'conversions' => (float) $group->sum('conversions'),
                    'roas' => $daySpend > 0 ? round($dayVal / $daySpend, 2) : 0.0,
                ];
            })
            ->sortBy('date')
            ->values();

        $summary = $conversionResult['summary'];

        return response()->json([
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'reporting_currency' => $targetCurrency,
            'summary' => [
                'spend' => $summary['converted_spend'],
                'impressions' => $summary['impressions'],
                'clicks' => $summary['clicks'],
                'conversions' => $summary['conversions'],
                'conversion_value' => $summary['converted_conversion_value'],
                'cpc' => $summary['cpc'],
                'cpm' => $summary['cpm'],
                'ctr' => $summary['ctr'],
                'roas' => $summary['roas'],
                'cpa' => $summary['cpa'],
                'primary_currency' => $targetCurrency,
            ],
            'currency_breakdown' => $currencyBreakdown,
            'conversion_metadata' => $conversionResult['conversion_metadata'],
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
            'provider' => ['nullable', 'string', 'in:meta,google,tiktok,snapchat'],
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'reporting_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $startDate = $validated['start_date'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');
        $perPage = $validated['per_page'] ?? 15;
        $targetCurrency = strtoupper($validated['reporting_currency'] ?? ($request->user()->reporting_currency ?: 'USD'));

        // Tenancy / User Scoping (SEC-001) - using indexed subquery to avoid pulling IDs into PHP memory
        $userId = $request->user()->id;
        $query = Campaign::with('connectedAdAccount:id,account_name,provider,currency')
            ->whereIn('connected_ad_account_id', function ($sub) use ($userId) {
                $sub->select('id')
                    ->from('connected_ad_accounts')
                    ->where('user_id', $userId);
            });

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

        // Fetch metrics rows per campaign in the specified date range for currency-aware aggregation
        $campaignIds = $paginator->pluck('id')->toArray();
        $metricRows = PerformanceMetric::query()
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->groupBy('campaign_id');

        $items = $paginator->getCollection()->map(function ($campaign) use ($metricRows, $targetCurrency) {
            $rows = $metricRows->get($campaign->id, collect());
            
            if ($rows->isNotEmpty()) {
                $converted = $this->currencyService->convertMetricsRows($rows, $targetCurrency);
                $summary = $converted['summary'];
                $spend = (float) $summary['converted_spend'];
                $conversionValue = (float) $summary['converted_conversion_value'];
                $impressions = (int) $summary['impressions'];
                $clicks = (int) $summary['clicks'];
                $conversions = (float) $summary['conversions'];
                $roas = (float) $summary['roas'];
                $cpc = (float) $summary['cpc'];
                $ctr = (float) $summary['ctr'];
                $isPartial = (bool) $summary['is_partial'];
                $nativeSpend = (float) collect($converted['currency_breakdown'])->sum('original_spend');
            } else {
                $spend = 0.0;
                $conversionValue = 0.0;
                $impressions = 0;
                $clicks = 0;
                $conversions = 0.0;
                $roas = 0.0;
                $cpc = 0.0;
                $ctr = 0.0;
                $isPartial = false;
                $nativeSpend = 0.0;
            }

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
                'reporting_currency' => $targetCurrency,
                'account_name' => $campaign->connectedAdAccount?->account_name,
                'metrics' => [
                    'spend' => $spend,
                    'native_spend' => $nativeSpend,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'conversions' => $conversions,
                    'conversion_value' => $conversionValue,
                    'roas' => $roas,
                    'cpc' => $cpc,
                    'ctr' => $ctr,
                    'is_partial' => $isPartial,
                    'currency' => $targetCurrency,
                ],
            ];
        });

        return response()->json([
            'data' => $items,
            'reporting_currency' => $targetCurrency,
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
            'connectedAdAccount:id,account_name,provider,currency,timezone,user_id',
            'adSets.ads',
        ])->findOrFail($id);

        // Tenancy check (SEC-001 / SEC-POST-03)
        if ($campaign->connectedAdAccount &&
            $campaign->connectedAdAccount->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view this campaign.',
                ],
            ], 403);
        }

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

    /**
     * Deterministic Operations Research (OR) Budget & ROAS Optimization Engine.
     * Computes convex KKT equilibrium marginal returns and reallocation frontier.
     */
    public function operationsResearch(
        Request $request,
        \App\Services\OperationsResearch\AdvertisingOptimizationService $orService,
        \App\Services\Advertising\SuggestionBuilder $builder
    ): JsonResponse {
        // The result and its rule-based suggestions come from ONE computation and carry one snapshot_id.
        $snap = $this->buildSnapshot($request, $orService, $builder);

        return response()->json([
            'success' => true,
            'data' => $snap['or'],
            'suggestions' => $snap['suggestions'],
            'snapshot_id' => $snap['snapshot_id'],
        ]);
    }

    private function computeOr(Request $request, $orService): array
    {
        $validated = $request->validate([
            'budget' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'budget_mode' => ['nullable', 'in:exact,at_most'],
            'min_marginal_roas' => ['nullable', 'numeric', 'gt:0'],
            'budget_horizon' => ['nullable', 'in:daily,per_30_days'],
            'saturation_multiplier' => ['nullable', 'numeric', 'gt:1', 'max:20'],
            'channel_limits_horizon' => ['nullable', 'in:daily,per_30_days', 'required_with:channel_limits'],
            'channel_limits' => ['nullable', 'array:meta,google,tiktok,snapchat', 'required_with:channel_limits_horizon'],
            'channel_limits.*.min' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'channel_limits.*.max' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
        ]);

        // Nothing is defaulted here: missing assumptions make the engine abstain.
        $options = array_filter($validated, fn($v) => $v !== null);
        $profile = \App\Models\BusinessProfile::where('user_id', $request->user()->id)->first();
        if ($profile) {
            $options['profile'] = [
                'currency' => $profile->currency,
                'budget_cap' => $profile->budget_cap,
                'budget_cap_horizon' => $profile->budget_cap_horizon,
            ];
        }

        $result = $orService->optimizeForUser($request->user()->id, $options);

        return $result->toArray();
    }
}
