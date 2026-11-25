<?php
namespace Tests\Feature;

use App\Models\{User,ConnectedAdAccount,PerformanceMetric,OptimizationRun,BusinessProfile};
use App\Services\OperationsResearch\ObjectiveEstimandPreflight;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ObjectiveEstimandPreflightTest extends TestCase
{
    use DatabaseTransactions;
    private function seedSources(): User
    {
        $user = User::factory()->create();
        foreach (['google','tiktok'] as $provider) {
            $account = ConnectedAdAccount::create(['user_id'=>$user->id,'provider'=>$provider,'external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);
            PerformanceMetric::create(['connected_ad_account_id'=>$account->id,'provider'=>$provider,'date'=>now()->subDay()->toDateString(),'currency'=>'USD','spend'=>10,'conversions'=>2,'conversion_value'=>30,'metadata'=>['normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','outcome_contract'=>\App\Services\Advertising\OutcomeContract::forRow($account,[])]]);
        }
        return $user;
    }
    private function request(User $u, string $query='')
    {
        return $this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/operations-research?budget=100&budget_horizon=per_30_days&curve_mode=fitted'.$query)->assertOk();
    }
    public function test_missing_selection_is_not_defaulted_from_profile_or_columns():void
    {
        $u=$this->seedSources();BusinessProfile::create(['user_id'=>$u->id,'primary_goal'=>'revenue','conversion_definition'=>'purchase']);
        $r=$this->request($u)->assertJsonPath('data.status','objective_basis_required')->assertJsonPath('data.objective',null)->assertJsonPath('data.channel_allocations',[]);
        $this->assertSame(['objective','outcome_basis'],$r->json('data.objective_preflight.missing_inputs'));
    }
    public function test_partial_selection_retains_exact_missing_field():void
    {
        $u=$this->seedSources();$r=$this->request($u,'&objective=MAXIMIZE_REPORTED_VALUE')->assertJsonPath('data.status','objective_basis_required');
        $this->assertSame(['outcome_basis'],$r->json('data.objective_preflight.missing_inputs'));
    }
    public function test_reported_intent_does_not_override_semantic_gate_and_keeps_source_arithmetic():void
    {
        $u=$this->seedSources();$r=$this->request($u,'&objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit')->assertJsonPath('data.status','incomparable_metric_definitions')->assertJsonPath('data.objective_preflight.status','ready_for_source_validation')->assertJsonPath('data.channel_allocations',[]);
        $groups=$r->json('data.descriptive_reported_sources.groups');$this->assertCount(2,$groups);
        foreach($groups as $g){$this->assertSame('reported',$g['status']);$this->assertEquals(2,$g['platform_reported_conversions']);$this->assertEquals(30,$g['platform_reported_conversion_value']);}
        $r->assertJsonPath('data.descriptive_reported_sources.pooled_business_outcomes',null);
        $this->assertFalse($r->json('data.objective_preflight.claims.verified_sales_or_profit'));
    }
    public function test_business_source_gap_is_specific_not_a_request_for_ad_account_access():void
    {
        $u=$this->seedSources();$r=$this->request($u,'&objective=MAXIMIZE_REVENUE&outcome_basis=common_business_events')->assertJsonPath('data.status','business_outcome_source_required')->assertJsonPath('data.objective','MAXIMIZE_REVENUE');
        $this->assertContains('business_event_source_and_population',$r->json('data.objective_preflight.missing_inputs'));
        $this->assertContains('portfolio_business_source_adapter',$r->json('data.objective_preflight.missing_inputs'));
    }
    public function test_business_revenue_is_not_silently_replaced_with_reported_value():void
    {
        $u=$this->seedSources();$this->request($u,'&objective=MAXIMIZE_REVENUE&outcome_basis=platform_reported_credit')->assertJsonPath('data.status','unsupported_objective_basis')->assertJsonPath('data.objective','MAXIMIZE_REVENUE')->assertJsonPath('data.channel_allocations',[]);
    }
    public function test_unimplemented_count_target_and_ratio_objectives_are_not_run_through_value_solver():void
    {
        $u=$this->seedSources();foreach(['MAXIMIZE_REPORTED_CONVERSIONS','MINIMIZE_CPA','MAXIMIZE_LEADS','MAXIMIZE_ROAS','TARGET_CPA','TARGET_ROAS','TARGET_REVENUE']as$o)$this->request($u,'&objective='.$o.'&outcome_basis=platform_reported_credit')->assertJsonPath('data.status','unsupported_objective_basis')->assertJsonPath('data.objective',$o)->assertJsonPath('data.channel_allocations',[]);
    }
    public function test_archive_binds_selection_source_definitions_and_refusal_without_relabeling():void
    {
        $u=$this->seedSources();$r=$this->request($u,'&objective=MAXIMIZE_REVENUE&outcome_basis=common_business_events');$id=$r->json('data.run_id');$run=OptimizationRun::findOrFail($id);
        $this->assertSame('matches',$run->integrityStatus());$this->assertSame($run->result['objective_preflight'],$run->source_snapshot['objective_preflight']);
        $this->getJson('/api/v1/analytics/optimization-runs/'.$id)->assertOk()->assertJsonPath('data.result.status','business_outcome_source_required');
        $this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay')->assertOk()->assertJsonPath('data.status','not_computable');
        $inputs=$run->inputs;$inputs['objective']='MAXIMIZE_REPORTED_VALUE';$run->inputs=$inputs;$run->save();
        $this->getJson('/api/v1/analytics/optimization-runs/'.$id)->assertOk()->assertJsonPath('data.result.stored_output_withheld',true);
    }
    public function test_evidence_hash_changes_with_source_contract_without_modifying_source_rows():void
    {
        $u=$this->seedSources();$a=$this->request($u);$row=PerformanceMetric::whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$u->id)->select('id'))->first();$meta=$row->metadata;$meta['outcome_contract']['attribution_basis']='different';$row->metadata=$meta;$row->save();$before=$row->fresh()->getAttributes();$b=$this->request($u);
        $this->assertNotSame($a->json('data.objective_preflight.source_evidence_sha256'),$b->json('data.objective_preflight.source_evidence_sha256'));$this->assertSame($before,$row->fresh()->getAttributes());
    }
    public function test_duplicate_account_day_is_not_added_as_independent_descriptive_evidence():void
    {
        $u=$this->seedSources();$row=PerformanceMetric::whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$u->id)->select('id'))->first();$campaign=\App\Models\Campaign::create(['connected_ad_account_id'=>$row->connected_ad_account_id,'provider'=>$row->provider,'external_campaign_id'=>uniqid(),'name'=>'Synthetic campaign','status'=>'ACTIVE']);$copy=$row->replicate();$copy->campaign_id=$campaign->id;$copy->save();
        $r=$this->request($u);$groups=$r->json('data.descriptive_reported_sources.groups');$withheld=array_values(array_filter($groups,fn($g)=>$g['status']==='withheld'));$this->assertCount(1,$withheld);$this->assertNull($withheld[0]['platform_reported_conversions']);
    }
    public function test_currency_groups_and_unverified_dimensions_are_not_hidden():void
    {
        $u=$this->seedSources();PerformanceMetric::where('provider','tiktok')->whereIn('connected_ad_account_id',ConnectedAdAccount::where('user_id',$u->id)->select('id'))->update(['currency'=>'EUR']);$r=$this->request($u);
        $this->assertSame(['USD','EUR'],array_column(array_values($r->json('data.descriptive_reported_sources.groups')),'currency'));
        $this->assertContains('value_rules',$r->json('data.objective_preflight.source_limitations.0.unverified_dimensions'));
    }
}
