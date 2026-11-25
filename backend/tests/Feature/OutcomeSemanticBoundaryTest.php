<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,PerformanceMetric};
use App\Services\Analytics\{OutcomeSemanticGuard,MetaDefinitionTransitionGuard};
use App\Services\Advertising\OutcomeContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
final class OutcomeSemanticBoundaryTest extends TestCase {
 use DatabaseTransactions;
 private function contract(string $p):array{return ['version'=>'source-outcomes-1','provider'=>$p,'count_metric'=>'same_words','value_metric'=>'same_words','attribution_basis'=>'same_words','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false];}
 private function row(string $p,array $extra=[]):object{return (object)['provider'=>$p,'metadata'=>['outcome_contract'=>array_replace($this->contract($p),$extra)]];}
 public function test_matching_declarations_never_make_cross_provider_outcomes_equivalent():void{
  $g=new OutcomeSemanticGuard;$this->assertNull($g->problem([$this->row('google'),$this->row('google')]));$this->assertStringContainsString('Cross-provider',$g->problem([$this->row('google'),$this->row('meta')]));$this->assertNotNull($g->problem([$this->row('google',['cross_source_equivalence'=>true])]));$this->assertNotNull($g->problem([$this->row('google',['value_truth'=>'verified_revenue'])]));$this->assertNotNull($g->problem([$this->row('google',['count_metric'=>'purchase']),$this->row('google',['count_metric'=>'lead'])]));$this->assertNotNull($g->problem([(object)['provider'=>'google','metadata'=>['normalization_version'=>'strict-operands-1']]]));
 }
 public function test_import_contract_preserves_different_metric_families_without_sales_claim():void{
  $a=new ConnectedAdAccount(['provider'=>'tiktok']);$d=OutcomeContract::forRow($a,[]);$this->assertSame('conversion',$d['count_metric']);$this->assertSame('total_purchase_value',$d['value_metric']);$this->assertFalse($d['cross_source_equivalence']);$this->assertSame('provider_reported_not_verified_sales',$d['value_truth']);$a->provider='apple';$this->assertNull(OutcomeContract::forRow($a,[]));
 }
 public function test_source_filtered_kpi_remains_available_while_mixed_scope_refuses_without_history_changes():void{
  $u=User::factory()->create();$before=[];foreach(['google','tiktok']as$p){$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>$p,'external_account_id'=>uniqid(),'account_name'=>'Fixture','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);$m=PerformanceMetric::create(['connected_ad_account_id'=>$a->id,'provider'=>$p,'date'=>'2026-11-01','currency'=>'USD','spend'=>10,'impressions'=>100,'clicks'=>2,'conversions'=>1,'conversion_value'=>20,'metadata'=>['normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','outcome_contract'=>$this->contract($p)]]);$m->refresh();$before[$m->id]=$m->getAttributes();}
  $this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/overview?start_date=2026-11-01&end_date=2026-11-01')->assertOk()->assertJsonPath('summary.spend',20)->assertJsonPath('summary.conversion_value',null)->assertJsonPath('summary.conversions',null);$this->getJson('/api/v1/analytics/overview?provider=google&start_date=2026-11-01&end_date=2026-11-01')->assertOk()->assertJsonPath('summary.conversion_value',20);foreach($before as$id=>$row)$this->assertSame($row,PerformanceMetric::findOrFail($id)->getAttributes());
 }
 public function test_additive_same_provider_attributed_reports_do_not_claim_unique_people_and_different_definitions_refuse():void{
  $g=new OutcomeSemanticGuard;$a=$this->row('google');$a->connected_ad_account_id=1;$b=$this->row('google');$b->connected_ad_account_id=2;$this->assertNull($g->problem([$a,$b]));$this->assertNotNull($g->problem([$this->row('google'),$this->row('google',['attribution_basis'=>'different-window'])]));
 }
 public function test_parsing_markers_and_legacy_meta_definition_alone_do_not_authorize_outcomes():void{
  $r=(object)['provider'=>'meta','metadata'=>['normalization_version'=>'strict-operands-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']]];$this->assertNotNull((new MetaDefinitionTransitionGuard)->problem([$r]));$this->assertNull((new MetaDefinitionTransitionGuard)->problem([$r],false));
 }
 public function test_unknown_account_configured_conversion_sets_do_not_become_identical_by_field_name():void{
  $a=new ConnectedAdAccount(['provider'=>'google','external_account_id'=>'a']);$b=new ConnectedAdAccount(['provider'=>'google','external_account_id'=>'b']);$ca=OutcomeContract::forRow($a,[]);$cb=OutcomeContract::forRow($b,[]);$this->assertNotSame($ca,$cb);$g=new OutcomeSemanticGuard;$this->assertNotNull($g->problem([(object)['provider'=>'google','metadata'=>['outcome_contract'=>$ca]],(object)['provider'=>'google','metadata'=>['outcome_contract'=>$cb]]]));
 }
 public function test_grouped_kpi_keeps_provider_groups_and_delivery_without_a_pooled_outcome():void{
  $u=User::factory()->create();foreach(['google','tiktok']as$p){$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>$p,'external_account_id'=>uniqid(),'account_name'=>'Fixture','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);PerformanceMetric::create(['connected_ad_account_id'=>$a->id,'provider'=>$p,'date'=>'2026-11-01','currency'=>'USD','spend'=>10,'impressions'=>100,'clicks'=>2,'conversions'=>1,'conversion_value'=>20,'metadata'=>['normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','outcome_contract'=>$this->contract($p)]]);}
  $r=$this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/kpis?start_date=2026-11-01&end_date=2026-11-01')->assertOk();$this->assertSame(10,$r->json('data.groups.google/USD.metrics.spend.value'));$this->assertSame(20,$r->json('data.groups.tiktok/USD.metrics.conversion_value.value'));$r->assertJsonMissingPath('data.total.conversions');
 }
}
