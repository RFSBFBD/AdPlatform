<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\OptimizationRun;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperationsResearchFittedTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        // Two providers (the fitted optimizer needs >=2 validated curves), each with
        // 28 days of wide spend variation and concave revenue.
        foreach ([
            ['meta', 'act_fit_1', 'fit_c1', 60.0, 22.0, 1500.0, 0.0035],
            ['google', 'act_fit_2', 'fit_c2', 40.0, 15.0, 1100.0, 0.0050],
        ] as [$provider, $extAcc, $extCamp, $base, $step, $scale, $beta]) {
            $account = ConnectedAdAccount::create([
                'user_id' => $this->user->id,
                'provider' => $provider,
                'external_account_id' => $extAcc,
                'account_name' => 'Fitted ' . $provider,
                'currency' => 'USD',
                'timezone' => 'UTC',
                'access_token' => 'tok',
                'status' => 'active',
            ]);
            $campaign = Campaign::create([
                'connected_ad_account_id' => $account->id,
                'provider' => $provider,
                'external_campaign_id' => $extCamp,
                'name' => 'Fit ' . $provider,
                'status' => 'ACTIVE',
                'daily_budget' => 200.00,
                'currency' => 'USD',
            ]);
            for ($i = 27; $i >= 0; $i--) {
                $spend = $base + $step * (27 - $i);
                $revenue = $scale * (1 - exp(-$beta * $spend));
                PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>$provider,'count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],
                    'connected_ad_account_id' => $account->id,
                    'campaign_id' => $campaign->id,
                    'provider' => $provider,
                    'date' => Carbon::now()->subDays($i + 1)->format('Y-m-d'),
                    'spend' => $spend,
                    'impressions' => $spend * 120,
                    'clicks' => $spend * 6,
                    'conversions' => max(1.0, round($revenue / 40)),
                    'conversion_value' => $revenue,
                ]);
            }
        }
    }

    public function test_cross_provider_fitted_and_assumed_portfolios_abstain_even_with_matching_declarations():void
    {
        foreach(['fitted','assumed']as$mode){$r=$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode='.$mode);$r->assertOk()->assertJsonPath('data.status','incomparable_metric_definitions')->assertJsonPath('data.channel_allocations',[]);}
    }
    public function test_unverified_semantics_are_not_overruled_by_quality_or_custom_allocation():void
    {
        config(['analytics.data_quality.optimize_threshold'=>0]);$r=$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted&custom_allocation[meta]=0&custom_allocation[google]=3000');$r->assertOk()->assertJsonPath('data.status','incomparable_metric_definitions')->assertJsonPath('data.channel_allocations',[])->assertJsonPath('data.custom_scenario.status','not_computable');
    }
    public function test_unbuilt_objective_is_rejected_not_silently_changed():void
    {
        $this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_LEADS&outcome_basis=platform_reported_credit')->assertOk()->assertJsonPath('data.status','unsupported_objective_basis');
    }
    public function test_refusal_is_deterministic_and_contains_no_model_allocations():void{
      $url='/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted';$a=$this->actingAs($this->user,'sanctum')->getJson($url)->assertOk();$b=$this->getJson($url)->assertOk();$this->assertSame($a->json('data.status'),$b->json('data.status'));$this->assertSame([],$a->json('data.channel_allocations'));$this->assertSame([],$b->json('data.channel_allocations'));
    }
    public function test_daily_and_period_requests_both_refuse_semantic_pooling():void{
      foreach(['budget=100&budget_horizon=daily','budget=3000&budget_horizon=per_30_days']as$q)$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&curve_mode=fitted&'.$q)->assertOk()->assertJsonPath('data.status','incomparable_metric_definitions')->assertJsonPath('data.channel_allocations',[]);
    }
    public function test_quality_threshold_does_not_fabricate_semantic_equivalence():void{
      config(['analytics.data_quality.optimize_threshold'=>101]);$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted')->assertOk()->assertJsonPath('data.channel_allocations',[]);
    }
    public function test_mixed_currency_does_not_create_equivalence():void{
      PerformanceMetric::where('provider','google')->whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$this->user->id)->pluck('id'))->update(['currency'=>'EUR']);$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted')->assertOk()->assertJsonPath('data.channel_allocations',[]);
    }
    public function test_raw_outcomes_unchanged_after_refused_scenario():void{
      $before=PerformanceMetric::whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$this->user->id)->pluck('id'))->orderBy('id')->get()->map->getAttributes()->all();$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted')->assertOk();$after=PerformanceMetric::whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$this->user->id)->pluck('id'))->orderBy('id')->get()->map->getAttributes()->all();$this->assertSame($before,$after);
    }
    public function test_refused_run_is_recorded_as_refused_not_success():void{
      $r=$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted')->assertOk();$run=OptimizationRun::findOrFail($r->json('data.run_id'));$this->assertSame('incomparable_metric_definitions',$run->status);$this->assertNotSame('ok',$run->result['status']);
    }
    public function test_empty_connected_account_does_not_bypass_semantic_gate():void{
      ConnectedAdAccount::create(['user_id'=>$this->user->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Empty','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted')->assertOk()->assertJsonPath('data.channel_allocations',[]);
    }
    public function test_overlapping_hierarchy_cannot_produce_portfolio_allocation():void{
      $r=PerformanceMetric::whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$this->user->id)->pluck('id'))->first();$copy=$r->replicate();$copy->campaign_id=null;$copy->save();$this->actingAs($this->user,'sanctum')->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted')->assertOk()->assertJsonPath('data.channel_allocations',[]);
    }
    public function test_source_redistribution_changes_refusal_fingerprint_without_creating_prediction():void{
      $url='/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode=fitted';$a=$this->actingAs($this->user,'sanctum')->getJson($url)->json('data.reproducibility.input_hash');$rows=PerformanceMetric::whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$this->user->id)->pluck('id'))->orderBy('id')->limit(2)->get();$rows[0]->increment('conversion_value',10);$rows[1]->decrement('conversion_value',10);$b=$this->getJson($url)->assertOk()->assertJsonPath('data.channel_allocations',[])->json('data.reproducibility.input_hash');$this->assertNotSame($a,$b);
    }
}
