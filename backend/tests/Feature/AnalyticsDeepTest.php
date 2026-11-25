<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsDeepTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ConnectedAdAccount $account;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'deep_analytics_tester@example.com'],
            ['name' => 'Deep Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );
        $this->account = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'meta',
            'external_account_id' => 'act_deep_1',
            'account_name' => 'Deep Meta',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'access_token' => 'tok',
            'status' => 'active',
        ]);
        $this->campaign = Campaign::create([
            'connected_ad_account_id' => $this->account->id,
            'provider' => 'meta',
            'external_campaign_id' => 'deep_c1',
            'name' => 'Deep Campaign',
            'status' => 'ACTIVE',
            'daily_budget' => 100.00,
            'currency' => 'USD',
        ]);
        // 30 days of recent history ending yesterday
        for ($i = 30; $i >= 1; $i--) {
            $spend = 100 + ($i % 5) * 10;
            PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],
                'connected_ad_account_id' => $this->account->id,
                'campaign_id' => $this->campaign->id,
                'provider' => 'meta',
                'date' => Carbon::now()->subDays($i)->format('Y-m-d'),
                'spend' => $spend,
                'impressions' => $spend * 100,
                'clicks' => $spend * 5,
                'conversions' => $spend / 5,
                'conversion_value' => $spend * 6,
            ]);
        }
    }

    public function test_observed_series_is_daily_aggregated_actual_and_tenant_scoped():void{
        $r=$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/observed-series?provider=meta&days=30');$r->assertOk()->assertJsonPath('data.status','ok')->assertJsonPath('data.type','ACTUAL');$points=$r->json('data.points');$this->assertCount(30,$points);$this->assertSame('exact_over_persisted_operands',$points[0]['recorded_arithmetic']['arithmetic']);$this->assertSame('legacy_unverified_may_have_been_rounded_before_storage',$points[0]['recorded_arithmetic']['source_precision']);$this->assertEqualsWithDelta($points[0]['metrics']['spend']/$points[0]['metrics']['conversions'],$points[0]['metrics']['cpa'],1e-8);
        $other=User::factory()->create();$this->actingAs($other,'sanctum')->getJson('/api/v1/analytics/observed-series?provider=meta&days=30')->assertJsonPath('data.status','not_computable');
    }
    public function test_observed_series_refuses_gaps_mixed_timezones_and_retains_undefined_ratios():void{
        PerformanceMetric::where('connected_ad_account_id',$this->account->id)->whereDate('date',now()->subDay())->update(['spend'=>0,'conversions'=>0]);
        $r=$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/observed-series?provider=meta&days=30')->assertJsonPath('data.status','ok');$p=$r->json('data.points');$this->assertNull($p[29]['metrics']['cpa']);$this->assertNull($p[29]['metrics']['roas']);
        PerformanceMetric::where('connected_ad_account_id',$this->account->id)->whereDate('date',now()->subDay())->delete();$this->getJson('/api/v1/analytics/observed-series?provider=meta&days=30')->assertJsonPath('data.status','not_computable');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        foreach (['/api/v1/analytics/data-quality', '/api/v1/analytics/kpis', '/api/v1/analytics/forecast?provider=meta&metric=spend', '/api/v1/analytics/anomalies?provider=meta&metric=spend', '/api/v1/analytics/diagnostics'] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
    }

    public function test_data_quality_returns_gate(): void
    {
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/data-quality');
        $r->assertStatus(200)->assertJsonPath('success', true);
        $data = $r->json('data');
        $this->assertArrayHasKey('optimize_allowed', $data['portfolio']);
        $this->assertArrayHasKey('channels', $data);
        $this->assertTrue($data['portfolio']['optimize_allowed']); // clean 30-day USD channel
    }

    public function test_kpis_are_actual_typed_with_lineage(): void
    {
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/kpis?provider=meta');
        $r->assertStatus(200);
        $groups = $r->json('data.groups');
        $this->assertNotEmpty($groups);
        $group = $groups['meta/USD'];
        $this->assertSame(30, $group['days_with_data']);
        foreach ($group['metrics'] as $m) {
            $this->assertSame('ACTUAL', $m['type']);
        }
        $this->assertArrayHasKey('roas', $group['metrics']);
    }

    public function test_forecast_returns_intervals_or_abstains(): void
    {
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/forecast?provider=meta&metric=spend&horizon=7');
        $r->assertStatus(200);
        $data = $r->json('data');
        $this->assertSame('EXPECTED', $data['type']);
        $this->assertContains($data['status'], ['ok', 'insufficient_data', 'abstain']);
        if ($data['status'] === 'ok') {
            $this->assertSame('prediction', $data['interval_kind']);
            $this->assertCount(7, $data['points']);
            foreach ($data['points'] as $pt) {
                $this->assertArrayHasKey('expected', $pt);
                $this->assertArrayHasKey('lower', $pt);
                $this->assertArrayHasKey('upper', $pt);
            }
        }
    }

    public function test_anomalies_returns_flags(): void
    {
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/anomalies?provider=meta&metric=spend');
        $r->assertStatus(200);
        $data = $r->json('data');
        foreach (['iqr', 'robust_z', 'ewma', 'change_point'] as $m) {
            $this->assertArrayHasKey($m, $data);
        }
        $this->assertArrayHasKey('dates', $data);
    }

    public function test_diagnostics_returns_decision(): void
    {
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/diagnostics?provider=meta');
        $r->assertStatus(200);
        $data = $r->json('data');
        $this->assertArrayHasKey('decision_state', $data);
        $this->assertArrayHasKey('signals', $data);
        $this->assertArrayHasKey('classification', $data);
        $this->assertArrayHasKey('windows', $data);
    }

    public function test_forecast_rejects_invalid_metric(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/analytics/forecast?provider=meta&metric=hacked')
            ->assertStatus(422);
    }
    public function test_forecast_abstains_instead_of_compressing_missing_days(): void
    {
        PerformanceMetric::where('connected_ad_account_id', $this->account->id)->orderBy('id')->first()->delete();
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/forecast?provider=meta&metric=spend');
        $r->assertOk()->assertJsonPath('data.status', 'insufficient_data')->assertJsonPath('data.points', []);
    }

    public function test_mixed_currency_history_is_not_summed_for_forecast(): void
    {
        PerformanceMetric::where('connected_ad_account_id', $this->account->id)->first()->update(['currency' => 'EUR']);
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/forecast?provider=meta&metric=spend');
        $r->assertOk()->assertJsonPath('data.status', 'insufficient_data');
    }

    public function test_audience_preserves_entity_day_rows_without_summing_reach(): void
    {
        PerformanceMetric::where('connected_ad_account_id', $this->account->id)->update(['reach' => 500, 'frequency' => 2]);
        $r = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/analytics/audience');
        $r->assertOk()->assertJsonCount(30, 'data.rows')->assertJsonPath('data.truncated', false);
        $this->assertSame(500, $r->json('data.rows.0.reach'));
        $this->assertStringContainsString('must not be summed', $r->json('data.note'));
        $this->assertArrayNotHasKey('total_reach', $r->json('data'));
    }

    public function test_shared_daily_analysis_uses_account_local_boundary_and_refuses_unlike_timezones():void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23T01:00:00Z'));
        try{
            PerformanceMetric::where('connected_ad_account_id',$this->account->id)->delete();$this->account->update(['timezone'=>'America/Los_Angeles']);
            for($i=30;$i>=1;$i--)PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],'connected_ad_account_id'=>$this->account->id,'campaign_id'=>$this->campaign->id,'provider'=>'meta','currency'=>'USD','date'=>now()->setTimezone('America/Los_Angeles')->subDays($i)->toDateString(),'spend'=>100+$i,'impressions'=>1000,'clicks'=>100,'conversions'=>10,'conversion_value'=>200]);
            $this->actingAs($this->user,'sanctum');
            $this->getJson('/api/v1/analytics/kpis?provider=meta&completed_days=30')->assertOk()->assertJsonPath('data.end_date','2026-11-21')->assertJsonPath('data.timezone','America/Los_Angeles');
            $this->getJson('/api/v1/analytics/kpis?provider=meta&completed_days=30&start_date=2026-11-01')->assertUnprocessable();
            $this->getJson('/api/v1/analytics/forecast?provider=meta&metric=spend')->assertOk()->assertJsonPath('data.status','ok')->assertJsonPath('data.window.end','2026-11-21');
            $this->getJson('/api/v1/analytics/anomalies?provider=meta&metric=spend')->assertOk()->assertJsonPath('data.window.end','2026-11-21');
            $this->getJson('/api/v1/analytics/observed-series?provider=meta')->assertOk()->assertJsonPath('data.status','ok')->assertJsonPath('data.end','2026-11-21');
            $this->getJson('/api/v1/analytics/diagnostics?provider=meta')->assertOk()->assertJsonPath('data.windows.current.1','2026-11-21');
            ConnectedAdAccount::create(['user_id'=>$this->user->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Other timezone','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);
            foreach(['/kpis?provider=meta&completed_days=30','/forecast?provider=meta&metric=spend','/anomalies?provider=meta&metric=spend','/observed-series?provider=meta','/diagnostics?provider=meta']as$url)$this->getJson('/api/v1/analytics'.$url)->assertJsonPath('data.status','incomparable_scope');
        }finally{Carbon::setTestNow();}
    }

    public function test_existing_kpi_surface_exposes_exact_persisted_arithmetic_without_source_precision_upgrade(): void
    {
        $r=$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/kpis?provider=meta')->assertOk();
        $groups=$r->json('data.groups');$group=array_values($groups)[0];
        self::assertSame('exact_over_persisted_operands',$group['recorded_arithmetic']['arithmetic']);
        self::assertSame('legacy_unverified_may_have_been_rounded_before_storage',$group['recorded_arithmetic']['source_precision']);
        self::assertIsString($group['recorded_arithmetic']['metrics']['spend']['decimal']);
        self::assertIsString($group['recorded_arithmetic']['metrics']['ctr']['fraction']['numerator']);
        self::assertIsString($group['recorded_arithmetic']['metrics']['ctr']['fraction']['denominator']);
        self::assertSame('ACTUAL',$group['metrics']['spend']['type']);
    }

}
