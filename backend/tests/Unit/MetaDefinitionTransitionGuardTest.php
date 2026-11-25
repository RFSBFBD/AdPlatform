<?php
namespace Tests\Unit;
use App\Services\Analytics\MetaDefinitionTransitionGuard;
use PHPUnit\Framework\TestCase;
final class MetaDefinitionTransitionGuardTest extends TestCase {
 private function r(?array $d,string $p='meta'):object{return (object)['provider'=>$p,'metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>$p,'count_metric'=>'fixture','value_metric'=>'fixture','attribution_basis'=>'fixture','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>$d]];}
 public function test_meta_definition_and_shared_provider_transition_evidence_are_required():void{$g=new MetaDefinitionTransitionGuard;$n=['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase','reporting_timezone'=>'UTC'];$this->assertNotNull($g->problem([$this->r(null)]));$this->assertNull($g->problem([$this->r($n),$this->r(array_reverse($n,true))]));$this->assertNotNull($g->problem([$this->r($n),$this->r(null,'google')]));$this->assertNotNull($g->problem([$this->r($n),$this->r(null)]));$old=$n;unset($old['account_metadata_source']);$this->assertNotNull($g->problem([$this->r($old)]));$old=$n;unset($old['normalization']);$this->assertNotNull($g->problem([$this->r($old)]));$n2=$n;$n2['reporting_timezone']='Africa/Cairo';$this->assertNotNull($g->problem([$this->r($n),$this->r($n2)]));}
 public function test_old_nonmeta_new_with_old_account_and_mixed_histories_are_not_blessed():void{
 $g=new MetaDefinitionTransitionGuard;
 foreach(['google','snapchat','tiktok','pinterest','microsoft']as$p){
  $old=(object)['provider'=>$p,'metadata'=>[]];$this->assertNotNull($g->problem([$old]));
  $new=(object)['provider'=>$p,'metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>$p,'count_metric'=>'fixture','value_metric'=>'fixture','attribution_basis'=>'fixture','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1']];$this->assertSame($p==='snapchat',$g->problem([$new])!==null);$this->assertNotNull($g->problem([$new,$old]));
  if(in_array($p,['google','tiktok','snapchat'],true)){$new->metadata=['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>$p,'count_metric'=>'fixture','value_metric'=>'fixture','attribution_basis'=>'fixture','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1'];$this->assertNotNull($g->problem([$new]));}
 }
 }
 public function test_typed_outcomes_cannot_bypass_unverified_spend_operands():void{
 $g=new MetaDefinitionTransitionGuard;$old=(object)['provider'=>'meta','metadata'=>[]];$this->assertNotNull($g->problem([$old],false));$current=(object)['provider'=>'meta','metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'fixture','value_metric'=>'fixture','attribution_basis'=>'fixture','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','metric_definition'=>['normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1']]];$this->assertNull($g->problem([$current],false));$this->assertNotNull($g->problem([$current],true));
 }
}
