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
use Illuminate\Support\Facades\DB;
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
            $hierarchyPayload = RawProviderPayload::create([
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

                        if(isset($adSetData['canonical_audience_references'])){
                            if(!is_array($adSetData['canonical_audience_references']))throw new \RuntimeException('Audience references must be an explicit object.');
                            (new CanonicalAudienceRecorder)->record($account,$adSet,$adSetData['canonical_audience_references'],$syncRun,$hierarchyPayload);
                        }
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

                                if(isset($adData['canonical_creative'])){
                                    if(!is_array($adData['canonical_creative']))throw new \RuntimeException('Creative identity payload must be an explicit object.');
                                    (new CanonicalCreativeRecorder)->record($account,$ad,$adData['canonical_creative'],$syncRun,$hierarchyPayload);
                                }
                                $adMap[$adData['external_ad_id']] = $ad->id;
                            }
                        }
                    }
                }
            }

            // Step 2: Sync Insights / Performance Metrics at accurate entity level
            $insightsData = $adapter->fetchInsights($account, $startDate, $endDate);

            // New observations only. Original numeric tokens survive independently of legacy decimal4 writes.
            // A capture row is NOT a qualified exact observation or permission to optimize.
            if(array_key_exists('recorded_response_pages',$insightsData)){
                if(!is_array($insightsData['recorded_response_pages'])){throw new \RuntimeException('Original reporting pages must be a list.');}
                (new RecordedReportingCapture)->persist($account,$syncRun,$insightsData['recorded_response_pages']);
            }

            $recordedDelivery = null;
            if ($account->provider === 'google' && isset($insightsData['recorded_response_pages'])) {
                $recordedDelivery = (new RecordedDeliveryRepository)->append($account, $syncRun, $campaignData);
            }

            // Persist raw audit payload for insights
            $insightPayload = RawProviderPayload::create([
                'connected_ad_account_id' => $account->id,
                'provider' => $account->provider,
                'endpoint' => $insightsData['endpoint'] ?? 'insights',
                'reporting_date' => $startDate,
                'payload' => $insightsData['raw'] ?? [],
            ]);

            if ($account->provider === 'snapchat' || ($insightsData['canonical_import_status'] ?? null) === 'unsupported_source_scope') {
                throw new \RuntimeException('Snapchat bare account stats is spend-inspection only; canonical delivery/outcome import is unsupported. No metric rows or success timestamp changed.');
            }

            $processedCount = count($campaignData['campaigns'] ?? []);

            // Validate the complete metric batch before any metric writes. Missing
            // provider values are unknown, never measured zeros. Keep malformed rows
            // out of both KPI totals and fitted response curves.
            if (!$account->currency) { throw new \RuntimeException('Account currency is unknown; no metrics imported.'); }
            $batchKeys = [];
            foreach ($insightsData['metrics'] ?? [] as $m) {
                if(!is_array($m))throw new \RuntimeException('Metric row must be an explicit object.');
                \App\Services\Advertising\MetricOperand::validate($m);
                foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'] as $key) {
                    if (!array_key_exists($key, $m) || $m[$key] === null || !is_numeric($m[$key]) || !is_finite((float)$m[$key]) || $m[$key] < 0) {
                        throw new \RuntimeException('Provider metric missing, nonfinite or negative: '.$key.'. No metric batch imported.');
                    }
                }
                $date = $m['date'] ?? '';
                $parsed = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
                if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < $startDate || $date > $endDate) {
                    throw new \RuntimeException('Provider metric date is invalid or outside requested range.');
                }
                $key = json_encode([$date, $m['external_campaign_id'] ?? null, $m['external_ad_set_id'] ?? null, $m['external_ad_id'] ?? null]);
                if (isset($batchKeys[$key])) { throw new \RuntimeException('Duplicate provider entity/day in metric batch.'); }
                $batchKeys[$key] = true;
            }

            $resolvedMetrics=[];$canonicalKeys=[];$resolver=new CanonicalMetricEntityResolver;
            foreach($insightsData['metrics']??[]as$index=>$m){
                $resolved=$resolver->resolve($account,$m);
                $key=json_encode([$m['date'],$resolved['campaign_id'],$resolved['ad_set_id'],$resolved['ad_id']]);
                if(isset($canonicalKeys[$key]))throw new \RuntimeException('Distinct external metric rows resolve to the same canonical entity/day. No metric batch imported.');
                $canonicalKeys[$key]=true;$resolvedMetrics[$index]=$resolved;
            }

            DB::transaction(function() use($insightsData,$resolvedMetrics,$account,$syncType,$insightPayload,$syncRun,&$processedCount){
            foreach ($insightsData['metrics'] ?? [] as $index=>$m) {
                $resolved=$resolvedMetrics[$index];
                $campaignId=$resolved['campaign_id'];$adSetId=$resolved['ad_set_id'];$adId=$resolved['ad_id'];

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
                        // Non-additive estimates at this exact entity/day scope only.
                        'reach' => $m['reach'] ?? null,
                        'frequency' => $m['frequency'] ?? null,
                        'clicks' => $clicks,
                        'conversions' => $conversions,
                        'conversion_value' => $conversionValue,
                        'cpc' => round($cpc, 4),
                        'cpm' => round($cpm, 4),
                        'ctr' => round($ctr, 4),
                        'roas' => round($roas, 4),
                        'cpa' => round($cpa, 4),
                        'currency' => $account->currency,
                        'metadata' => [
                            'synced_at' => now()->toIso8601String(),
                            'outcome_contract'=>\App\Services\Advertising\OutcomeContract::forRow($account,$m),
                            'normalization_version'=>\App\Services\Advertising\MetricOperand::VERSION,
                            'account_metadata_source'=>\App\Services\Advertising\AccountMetadataEvidence::marker($account),
                            'sync_type' => $syncType,
                            'entity_level' => $resolved['entity_level'],
                            'source_external_entity_ids'=>array_intersect_key($m,array_flip(['external_campaign_id','external_ad_set_id','external_ad_id'])),
                            'canonical_resolution_version'=>'strict-parent-1',
                            'sync_run_id'=>$syncRun->id,
                            'raw_provider_payload_id'=>$insightPayload->id,
                            'reporting_timezone' => $account->timezone,
                            'provider_endpoint' => $insightsData['endpoint'] ?? 'insights',
                            'metric_definition' => $m['metric_definition'] ?? null,
                        ],
                    ]
                );

                $processedCount++;
            }

            });

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
                    'recorded_delivery' => $recordedDelivery,
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
