<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,Campaign,PerformanceMetric};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
class MetricScopeGuardTest extends TestCase
{
    use DatabaseTransactions;
    private function fixture(bool $overlap): User
    {
        $u=User::factory()->create();$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Scope fixture','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);
        $c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>uniqid(),'name'=>'Fixture','currency'=>'USD','status'=>'ACTIVE']);
        for ($i=30;$i>=1;$i--) {PerformanceMetric::create(['connected_ad_account_id'=>$a->id,'campaign_id'=>$c->id,'provider'=>'meta','currency'=>'USD','date'=>now()->subDays($i)->toDateString(),'spend'=>10,'impressions'=>100,'clicks'=>5,'conversions'=>1,'conversion_value'=>20]);}
        if ($overlap) {PerformanceMetric::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','currency'=>'USD','date'=>now()->subDay()->toDateString(),'spend'=>10,'impressions'=>100,'clicks'=>5,'conversions'=>1,'conversion_value'=>20]);}
        else {ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Missing history','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);}
        return $u;
    }
    public function test_overlap_abstains_in_kpis_forecast_and_anomalies(): void
    {
        $u=$this->fixture(true);$this->actingAs($u,'sanctum');
        $this->getJson('/api/v1/analytics/kpis')->assertOk()->assertJsonPath('data.status','not_computable');
        foreach (['forecast','anomalies'] as $p) {$this->getJson('/api/v1/analytics/'.$p.'?provider=meta&metric=spend')->assertOk()->assertJsonPath('data.status','insufficient_data')->assertJsonPath('data.reason','Overlapping hierarchy levels for the same account/day cannot be added without double-counting.');}
    }
    public function test_missing_connected_account_history_prevents_comparative_claim(): void
    {
        $u=$this->fixture(false);$this->actingAs($u,'sanctum');
        $this->getJson('/api/v1/analytics/forecast?provider=meta&metric=spend')->assertOk()->assertJsonPath('data.status','insufficient_data');
        $this->getJson('/api/v1/analytics/diagnostics?provider=meta')->assertOk()->assertJsonPath('data.decision_state','INSUFFICIENT_DATA');
    }
    public function test_foreign_parent_metric_is_rejected_across_all_daily_analytics_surfaces():void{
        $u=$this->fixture(true);$a=ConnectedAdAccount::where('user_id',$u->id)->first();PerformanceMetric::where('connected_ad_account_id',$a->id)->whereNull('campaign_id')->delete();
        $other=$this->fixture(true);$foreign=Campaign::whereHas('connectedAdAccount',fn($q)=>$q->where('user_id',$other->id))->first();PerformanceMetric::where('connected_ad_account_id',$a->id)->update(['campaign_id'=>$foreign->id]);$this->actingAs($u,'sanctum');
        foreach(['/kpis','/forecast?provider=meta&metric=spend','/anomalies?provider=meta&metric=spend','/diagnostics?provider=meta','/observed-series?provider=meta']as$p)$this->getJson('/api/v1/analytics'.$p)->assertOk()->assertJsonPath('data.reason','Metric campaign/account/provider chain is inconsistent.');
    }
    public function test_negative_operands_and_currency_mismatch_are_not_summed():void{
        $u=$this->fixture(true);$a=ConnectedAdAccount::where('user_id',$u->id)->first();PerformanceMetric::where('connected_ad_account_id',$a->id)->whereNull('campaign_id')->delete();$r=PerformanceMetric::where('connected_ad_account_id',$a->id)->first();$this->actingAs($u,'sanctum');$r->update(['spend'=>-1]);$this->getJson('/api/v1/analytics/kpis')->assertJsonPath('data.reason','Missing, negative or nonfinite metric operands cannot be aggregated.');$r->update(['spend'=>10,'currency'=>'EUR']);$this->getJson('/api/v1/analytics/kpis')->assertJsonPath('data.reason','Metric provider/currency disagrees with its owned account.');
    }

    public function test_meta_definition_transition_blocks_mixed_history_without_rewriting_it():void{
        $u=$this->fixture(true);$a=ConnectedAdAccount::where('user_id',$u->id)->first();PerformanceMetric::where('connected_ad_account_id',$a->id)->whereNull('campaign_id')->delete();$r=PerformanceMetric::where('connected_ad_account_id',$a->id)->first();$r->update(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1']]]);$this->actingAs($u,'sanctum');$reason='Legacy, missing or differing Meta outcome definitions cannot support this analysis. Narrow the history or verify and reimport it; no rows were repaired.';
        foreach(['/kpis','/forecast?provider=meta&metric=spend','/anomalies?provider=meta&metric=spend','/diagnostics?provider=meta','/observed-series?provider=meta']as$p){$response=$this->getJson('/api/v1/analytics'.$p)->assertOk();$this->assertSame($reason,$response->json('data.reason'),$p.': '.$response->getContent());}
        $this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertOk()->assertJsonPath('data.status','incomparable_metric_definitions');
        $out=app(\App\Services\OperationsResearch\AdvertisingOptimizationService::class)->optimizeForUser($u->id,['budget'=>3000])->toArray();$this->assertSame('incomparable_metric_definitions',$out['status']);$this->assertSame(1,PerformanceMetric::where('connected_ad_account_id',$a->id)->get()->filter(fn($m)=>($m->metadata['metric_definition']['version']??null)==='meta-pixel-purchase-1')->count());
    }

}
