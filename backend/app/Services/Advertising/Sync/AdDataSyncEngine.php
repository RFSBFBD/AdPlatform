<?php

namespace App\Services\Advertising\Sync;

use App\Exceptions\OAuthTokenExpiredException;
use App\Exceptions\ProviderRateLimitException;
use App\Models\Ad;
use App\Models\AdSet;
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

            // Step 1: Sync Campaigns & Hierarchical Entities
            $campaignData = $adapter->fetchCampaigns($account);
            
            // Persist raw audit payload
            RawProviderPayload::create([
                'connected_ad_account_id' => $account->id,
                'provider' => $account->provider,
                'endpoint' => $campaignData['endpoint'] ?? 'campaigns',
                'payload' => $campaignData['raw'] ?? [],
            ]);

            $campaignMap = [];
            $adSetMap = [];
            $adMap = [];

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

                // Step 1b: Ingest Ad Sets and Ads hierarchy (INT-002 / Phase 4)
                if (!empty($camp['ad_sets']) && is_array($camp['ad_sets'])) {
                    foreach ($camp['ad_sets'] as $adSetData) {
                        $adSet = AdSet::updateOrCreate(
                            [
                                'campaign_id' => $campaign->id,
                                'external_ad_set_id' => $adSetData['external_ad_set_id'],
                            ],
                            [
                                'name' => $adSetData['name'] ?? 'Ad Set ' . $adSetData['external_ad_set_id'],
                                'status' => strtoupper($adSetData['status'] ?? 'ACTIVE'),
                                'daily_budget' => $adSetData['daily_budget'] ?? null,
                                'lifetime_budget' => $adSetData['lifetime_budget'] ?? null,
                                'targeting' => $adSetData['targeting'] ?? null,
                                'metadata' => $adSetData['metadata'] ?? null,
                            ]
                        );

                        $adSetMap[$adSetData['external_ad_set_id']] = $adSet->id;

                        if (!empty($adSetData['ads']) && is_array($adSetData['ads'])) {
                            foreach ($adSetData['ads'] as $adData) {
                                $ad = Ad::updateOrCreate(
                                    [
                                        'ad_set_id' => $adSet->id,
                                        'external_ad_id' => $adData['external_ad_id'],
                                    ],
                                    [
                                        'name' => $adData['name'] ?? 'Ad ' . $adData['external_ad_id'],
                                        'status' => strtoupper($adData['status'] ?? 'ACTIVE'),
                                        'creative_type' => $adData['creative_type'] ?? 'IMAGE',
                                        'headline' => $adData['headline'] ?? null,
                                        'body_text' => $adData['body_text'] ?? null,
                                        'destination_url' => $adData['destination_url'] ?? null,
                                        'metadata' => $adData['metadata'] ?? null,
                                    ]
                                );

                                $adMap[$adData['external_ad_id']] = $ad->id;
                            }
                        }
                    }
                }
            }

            // Step 2: Sync Insights / Performance Metrics at accurate entity level
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
                $adSetId = null;
                $adId = null;

                // Resolve Ad level ID if present
                if (!empty($m['external_ad_id'])) {
                    $adId = $adMap[$m['external_ad_id']] ?? Ad::where('external_ad_id', $m['external_ad_id'])->value('id');
                }

                // Resolve AdSet level ID if present
                if (!empty($m['external_ad_set_id'])) {
                    $adSetId = $adSetMap[$m['external_ad_set_id']] ?? AdSet::where('external_ad_set_id', $m['external_ad_set_id'])->value('id');
                }

                // Resolve Campaign level ID
                if (!empty($m['external_campaign_id']) && isset($campaignMap[$m['external_campaign_id']])) {
                    $campaignId = $campaignMap[$m['external_campaign_id']];
                } elseif (!empty($m['external_campaign_id'])) {
                    $campaignId = Campaign::where('connected_ad_account_id', $account->id)
                        ->where('external_campaign_id', $m['external_campaign_id'])
                        ->value('id');
                }

                // Backfill parent relationships if metric came with child IDs only
                if ($adId && !$adSetId) {
                    $adSetId = Ad::where('id', $adId)->value('ad_set_id');
                }
                if ($adSetId && !$campaignId) {
                    $campaignId = AdSet::where('id', $adSetId)->value('campaign_id');
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

                // Hierarchical matching criteria preventing metric overwrites between different entities (Phase 4)
                PerformanceMetric::updateOrCreate(
                    [
                        'connected_ad_account_id' => $account->id,
                        'campaign_id' => $campaignId,
                        'ad_set_id' => $adSetId,
                        'ad_id' => $adId,
                        'date' => $m['date'],
                        'provider' => $account->provider,
                    ],
                    [
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
                            'entity_level' => $adId ? 'ad' : ($adSetId ? 'ad_set' : 'campaign'),
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
        } catch (OAuthTokenExpiredException $e) {
            Log::warning("AdDataSyncEngine OAuth token expired on account #{$account->id}: {$e->getMessage()}");
            $account->update(['status' => 'token_expired']);

            $syncRun->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => "OAuth token expired for {$account->provider}. Re-authentication required.",
            ]);

            return $syncRun;
        } catch (ProviderRateLimitException $e) {
            Log::warning("AdDataSyncEngine rate limited on account #{$account->id}: {$e->getMessage()}");
            $account->update(['status' => 'rate_limited']);

            $syncRun->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => "Provider rate limit reached for {$account->provider}.",
            ]);

            return $syncRun;
        } catch (Throwable $e) {
            Log::error("AdDataSyncEngine error on account #{$account->id}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

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
