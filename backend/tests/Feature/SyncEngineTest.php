<?php

namespace Tests\Feature;

use App\Jobs\SyncAccountJob;
use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\ProviderDeveloperConfig;
use App\Models\RawProviderPayload;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use App\Services\Advertising\OAuthManager;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SyncEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ConnectedAdAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'sync_tester@example.com'],
            ['name' => 'Sync Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );

        $this->account = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'meta',
            'external_account_id' => '1234567890',
            'account_name' => 'Test Meta Ads Account',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'access_token' => 'mock_access_token_123',
            'status' => 'active',
        ]);
    }

    public function test_manual_sync_trigger_queues_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/ad-accounts/{$this->account->id}/sync", [
                'sync_type' => 'manual',
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'queued',
                'account_id' => $this->account->id,
            ]);

        Queue::assertPushed(SyncAccountJob::class, function ($job) {
            return $job->account->id === $this->account->id && $job->syncType === 'manual';
        });
    }

    public function test_sync_engine_normalizes_data_calculates_metrics_and_audits(): void
    {
        // Mock provider adapter
        $mockAdapter = Mockery::mock(AdvertisingProviderInterface::class);
        $mockAdapter->shouldReceive('fetchCampaigns')
            ->once()
            ->andReturn([
                'endpoint' => '/act_1234567890/campaigns',
                'raw' => ['data' => [['id' => 'camp_999', 'name' => 'Summer Sale Promo']]],
                'campaigns' => [
                    [
                        'external_campaign_id' => 'camp_999',
                        'name' => 'Summer Sale Promo',
                        'status' => 'ACTIVE',
                        'objective' => 'OUTCOME_SALES',
                        'daily_budget' => 50.00,
                        'lifetime_budget' => null,
                        'currency' => 'USD',
                    ],
                ],
            ]);

        $mockAdapter->shouldReceive('fetchInsights')
            ->once()
            ->andReturn([
                'endpoint' => '/act_1234567890/insights',
                'raw' => ['data' => [['spend' => '100.00', 'impressions' => '5000', 'clicks' => '200']]],
                'metrics' => [
                    [
                        'external_campaign_id' => 'camp_999',
                        'date' => '2026-11-17',
                        'spend' => 100.00,
                        'impressions' => 5000,
                        'clicks' => 200,
                        'conversions' => 10.0,
                        'conversion_value' => 500.0,
                    ],
                ],
            ]);

        $mockOAuth = Mockery::mock(OAuthManager::class);
        $mockOAuth->shouldReceive('getAdapter')->with('meta')->andReturn($mockAdapter);

        $engine = new AdDataSyncEngine($mockOAuth);
        $syncRun = $engine->syncAccount($this->account, 'initial', '2026-11-17', '2026-11-17');

        $this->assertEquals('completed', $syncRun->status);
        $this->assertEquals(2, $syncRun->records_processed);

        // Verify Campaign normalized in DB
        $campaign = Campaign::where('connected_ad_account_id', $this->account->id)
            ->where('external_campaign_id', 'camp_999')
            ->first();
        $this->assertNotNull($campaign);
        $this->assertEquals('Summer Sale Promo', $campaign->name);
        $this->assertEquals(50.00, (float) $campaign->daily_budget);

        // Verify Performance Metric with derived rate calculations
        $metric = PerformanceMetric::where('connected_ad_account_id', $this->account->id)
            ->where('campaign_id', $campaign->id)
            ->where('date', '2026-11-17')
            ->first();

        $this->assertNotNull($metric);
        $this->assertEquals(100.00, (float) $metric->spend);
        $this->assertEquals(5000, $metric->impressions);
        $this->assertEquals(200, $metric->clicks);
        $this->assertEquals(10.0, (float) $metric->conversions);
        $this->assertEquals(500.0, (float) $metric->conversion_value);

        // CPC = 100 / 200 = 0.50
        $this->assertEquals(0.50, (float) $metric->cpc);
        // CPM = (100 / 5000) * 1000 = 20.00
        $this->assertEquals(20.00, (float) $metric->cpm);
        // CTR = (200 / 5000) * 100 = 4.00%
        $this->assertEquals(4.00, (float) $metric->ctr);
        // ROAS = 500 / 100 = 5.00
        $this->assertEquals(5.00, (float) $metric->roas);
        // CPA = 100 / 10 = 10.00
        $this->assertEquals(10.00, (float) $metric->cpa);

        // Verify Raw Audit Payload was stored
        $rawCount = RawProviderPayload::where('connected_ad_account_id', $this->account->id)->count();
        $this->assertGreaterThanOrEqual(2, $rawCount);
    }

    public function test_sync_engine_prevents_concurrent_runs_with_redis_lock(): void
    {
        $lockKey = "sync:account:{$this->account->id}";
        $lock = Cache::lock($lockKey, 60);
        $this->assertTrue($lock->get());

        $mockOAuth = Mockery::mock(OAuthManager::class);
        $engine = new AdDataSyncEngine($mockOAuth);

        $syncRun = $engine->syncAccount($this->account, 'manual');

        $this->assertEquals('failed', $syncRun->status);
        $this->assertStringContainsString('Synchronization already in progress', $syncRun->error_message);

        $lock->release();
    }

    public function test_sync_engine_is_idempotent(): void
    {
        $mockAdapter = Mockery::mock(AdvertisingProviderInterface::class);
        $mockAdapter->shouldReceive('fetchCampaigns')->twice()->andReturn([
            'endpoint' => '/campaigns',
            'raw' => [],
            'campaigns' => [
                [
                    'external_campaign_id' => 'camp_idempotent',
                    'name' => 'Brand Campaign',
                    'status' => 'ACTIVE',
                ],
            ],
        ]);

        $mockAdapter->shouldReceive('fetchInsights')->twice()->andReturn([
            'endpoint' => '/insights',
            'raw' => [],
            'metrics' => [
                [
                    'external_campaign_id' => 'camp_idempotent',
                    'date' => '2026-11-18',
                    'spend' => 75.00,
                    'impressions' => 3000,
                    'clicks' => 150,
                    'conversions' => 5,
                    'conversion_value' => 200,
                ],
            ],
        ]);

        $mockOAuth = Mockery::mock(OAuthManager::class);
        $mockOAuth->shouldReceive('getAdapter')->andReturn($mockAdapter);

        $engine = new AdDataSyncEngine($mockOAuth);

        // Run once
        $engine->syncAccount($this->account, 'incremental', '2026-11-18', '2026-11-18');
        // Run twice
        $engine->syncAccount($this->account, 'incremental', '2026-11-18', '2026-11-18');

        // Verify count remains 1
        $campCount = Campaign::where('connected_ad_account_id', $this->account->id)
            ->where('external_campaign_id', 'camp_idempotent')
            ->count();
        $this->assertEquals(1, $campCount);

        $metricCount = PerformanceMetric::where('connected_ad_account_id', $this->account->id)
            ->where('date', '2026-11-18')
            ->count();
        $this->assertEquals(1, $metricCount);
    }

    public function test_get_sync_status_and_history_endpoints(): void
    {
        SyncRun::create([
            'connected_ad_account_id' => $this->account->id,
            'sync_type' => 'incremental',
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(8),
            'records_processed' => 125,
        ]);

        // Status check
        $statusRes = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/ad-accounts/{$this->account->id}/sync-status");

        $statusRes->assertStatus(200)
            ->assertJson([
                'account_id' => $this->account->id,
                'latest_sync' => [
                    'status' => 'completed',
                    'records_processed' => 125,
                ],
            ]);

        // History check
        $historyRes = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/ad-accounts/{$this->account->id}/sync-history");

        $historyRes->assertStatus(200)
            ->assertJsonStructure([
                'account_id',
                'runs' => [
                    '*' => ['id', 'sync_type', 'status', 'records_processed'],
                ],
            ]);
    }
}
