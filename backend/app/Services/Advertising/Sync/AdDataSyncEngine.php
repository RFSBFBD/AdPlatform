<?php

namespace App\Services\Advertising\Sync;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\RawProviderPayload;
use App\Models\SyncRun;
use App\Services\Advertising\OAuthManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdDataSyncEngine
{
    public function __construct(
        protected OAuthManager $oauthManager
    ) {}

    /**
     * Synchronize campaigns and insights for a given ad account.
     *
     * @param ConnectedAdAccount $account
     * @param string $syncType 'initial', 'incremental', 'historical', or 'manual'
     * @param string|null $startDate
     * @param string|null $endDate
     * @return SyncRun
     */
    public function syncAccount(
        ConnectedAdAccount $account,
        string $syncType = 'incremental',
        ?string $startDate = null,
        ?string $endDate = null
    ): SyncRun {
        $lockKey = "sync:account:{$account->id}";
        $lock = Cache::lock($lockKey, 300);

        $syncRun = SyncRun::create([
            'connected_ad_account_id' => $account->id,
            'sync_type' => $syncType,
            'status' => 'pending',
            'started_at' => now(),
        ]);

        if (!$lock->get()) {
            $syncRun->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => "Synchronization already in progress for account #{$account->id}.",
            ]);
            return $syncRun;
        }

        try {
            $syncRun->update(['status' => 'running']);

            // Calculate date windows if not explicitly provided
            if (!$startDate || !$endDate) {
                $endDate = Carbon::now()->format('Y-m-d');
                $startDate = match ($syncType) {
                    'initial' => Carbon::now()->subDays(30)->format('Y-m-d'),
                    'historical' => Carbon::now()->subDays(90)->format('Y-m-d'),
                    default => Carbon::now()->subDays(3)->format('Y-m-d'), // Incremental covers 3-day attribution window
                };
            }

            $adapter = $this->oauthManager->getAdapter($account->provider);

            // Step 1: Sync Campaigns
            $campaignData = $adapter->fetchCampaigns($account);
            
            // Persist raw audit payload
            RawProviderPayload::create([
                'connected_ad_account_id' => $account->id,
                'provider' => $account->provider,
                'endpoint' => $campaignData['endpoint'] ?? 'campaigns',
                'payload' => $campaignData['raw'] ?? [],
            ]);

            $campaignMap = [];
            foreach ($campaignData['campaigns'] ?? [] as $camp) {
                $campaign = Campaign::updateOrCreate(
                    [
                        'connected_ad_account_id' => $account->id,
                        'external_campaign_id' => $camp['external_campaign_id'],
                    ],
                    [
                        'provider' => $account->provider,
                        'name' => $camp['name'],
                        'status' => $camp['status'],
                        'objective' => $camp['objective'] ?? null,
                        'daily_budget' => $camp['daily_budget'] ?? null,
                        'lifetime_budget' => $camp['lifetime_budget'] ?? null,
                        'currency' => $camp['currency'] ?? $account->currency,
                        'start_date' => $camp['start_date'] ?? null,
                        'end_date' => $camp['end_date'] ?? null,
                        'metadata' => $camp['metadata'] ?? null,
                    ]
                );

                $campaignMap[$camp['external_campaign_id']] = $campaign->id;
            }

            // Step 2: Sync Insights / Performance Metrics
            $insightsData = $adapter->fetchInsights($account, $startDate, $endDate);

            // Persist raw audit payload for insights
            RawProviderPayload::create([
                'connected_ad_account_id' => $account->id,
                'provider' => $account->provider,
                'endpoint' => $insightsData['endpoint'] ?? 'insights',
                'reporting_date' => $startDate,
                'payload' => $insightsData['raw'] ?? [],
            ]);

            $processedCount = count($campaignData['campaigns'] ?? []);

            foreach ($insightsData['metrics'] ?? [] as $m) {
                $campaignId = null;
                if (!empty($m['external_campaign_id']) && isset($campaignMap[$m['external_campaign_id']])) {
                    $campaignId = $campaignMap[$m['external_campaign_id']];
                } elseif (!empty($m['external_campaign_id'])) {
                    // Try to find campaign in DB
                    $c = Campaign::where('connected_ad_account_id', $account->id)
                        ->where('external_campaign_id', $m['external_campaign_id'])
                        ->first();
                    $campaignId = $c?->id;
                }

                $spend = (float) ($m['spend'] ?? 0);
                $impressions = (int) ($m['impressions'] ?? 0);
                $clicks = (int) ($m['clicks'] ?? 0);
                $conversions = (float) ($m['conversions'] ?? 0);
                $conversionValue = (float) ($m['conversion_value'] ?? 0);

                // Derived metric calculations
                $cpc = $clicks > 0 ? ($spend / $clicks) : 0.0;
                $cpm = $impressions > 0 ? (($spend / $impressions) * 1000.0) : 0.0;
                $ctr = $impressions > 0 ? (($clicks / $impressions) * 100.0) : 0.0;
                $roas = $spend > 0 ? ($conversionValue / $spend) : 0.0;
                $cpa = $conversions > 0 ? ($spend / $conversions) : 0.0;

                PerformanceMetric::updateOrCreate(
                    [
                        'connected_ad_account_id' => $account->id,
                        'campaign_id' => $campaignId,
                        'date' => $m['date'],
                        'provider' => $account->provider,
                    ],
                    [
                        'ad_set_id' => null,
                        'ad_id' => null,
                        'spend' => $spend,
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'conversions' => $conversions,
                        'conversion_value' => $conversionValue,
                        'cpc' => round($cpc, 4),
                        'cpm' => round($cpm, 4),
                        'ctr' => round($ctr, 4),
                        'roas' => round($roas, 4),
                        'cpa' => round($cpa, 4),
                        'currency' => $account->currency ?? 'USD',
                        'metadata' => [
                            'synced_at' => now()->toIso8601String(),
                            'sync_type' => $syncType,
                        ],
                    ]
                );

                $processedCount++;
            }

            // Update account sync timestamp & status
            $account->update([
                'last_synced_at' => now(),
                'status' => 'active',
            ]);

            $syncRun->update([
                'status' => 'completed',
                'completed_at' => now(),
                'records_processed' => $processedCount,
                'metadata' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ]);

            return $syncRun;
        } catch (Throwable $e) {
            Log::error("AdDataSyncEngine error on account #{$account->id}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            // Handle provider rate limit indicators
            if (str_contains(strtolower($e->getMessage()), 'rate limit') || str_contains($e->getMessage(), '429')) {
                $account->update(['status' => 'rate_limited']);
            }

            $syncRun->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            return $syncRun;
        } finally {
            $lock->release();
        }
    }
}
