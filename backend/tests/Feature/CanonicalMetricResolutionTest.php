<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,Campaign,AdSet,Ad,PerformanceMetric};
use App\Services\Advertising\{OAuthManager,Contracts\AdvertisingProviderInterface};
use App\Services\Advertising\Sync\{AdDataSyncEngine,CanonicalMetricEntityResolver};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;
final class CanonicalMetricResolutionTest extends TestCase {
 use DatabaseTransactions;
 private function fixture():array {
  $u=User::factory()->create();$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);
  $campaigns=[];$sets=[];$ads=[];foreach(['one','two']as$key){$c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>$key,'name'=>$key,'currency'=>'USD']);$s=AdSet::create(['campaign_id'=>$c->id,'external_ad_set_id'=>'reused-set','name'=>'Synthetic']);$ad=Ad::create(['ad_set_id'=>$s->id,'external_ad_id'=>'reused-ad','name'=>'Synthetic']);$campaigns[]=$c;$sets[]=$s;$ads[]=$ad;}return [$a,$campaigns,$sets,$ads];
 }
 private function row(array $ids=[]):array{return $ids+['date'=>'2026-11-01','spend'=>10,'impressions'=>100,'clicks'=>2,'conversions'=>1,'conversion_value'=>20];}
 private function sync($account,array $rows){$adapter=Mockery::mock(AdvertisingProviderInterface::class);$adapter->shouldReceive('fetchCampaigns')->once()->andReturn(['campaigns'=>[],'raw'=>[]]);$adapter->shouldReceive('fetchInsights')->once()->andReturn(['metrics'=>$rows,'raw'=>[]]);$manager=Mockery::mock(OAuthManager::class);$manager->shouldReceive('getAdapter')->with('meta')->andReturn($adapter);return(new AdDataSyncEngine($manager))->syncAccount($account,'manual','2026-11-01','2026-11-01');}
 public function test_explicit_parent_disambiguates_reused_child_ids_and_retains_lineage():void {
  [$a,$c,$s,$ad]=$this->fixture();$r=$this->sync($a,[$this->row(['external_campaign_id'=>'two','external_ad_set_id'=>'reused-set','external_ad_id'=>'reused-ad'])]);$this->assertSame('completed',$r->status);$m=PerformanceMetric::where('connected_ad_account_id',$a->id)->firstOrFail();$this->assertSame($c[1]->id,$m->campaign_id);$this->assertSame($s[1]->id,$m->ad_set_id);$this->assertSame($ad[1]->id,$m->ad_id);$this->assertSame('strict-parent-1',$m->metadata['canonical_resolution_version']);$this->assertSame('two',$m->metadata['source_external_entity_ids']['external_campaign_id']);$this->assertSame($r->id,$m->metadata['sync_run_id']);$this->assertNotNull(\App\Models\RawProviderPayload::find($m->metadata['raw_provider_payload_id']));
 }
 public function test_unknown_supplied_ids_never_become_account_metrics_and_batch_is_not_partial():void {
  [$a]=$this->fixture();foreach(['campaign','ad_set','ad']as$level){$r=$this->sync($a,[$this->row(),$this->row(['external_'.$level.'_id'=>'missing'])]);$this->assertSame('failed',$r->status);$this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());}
 }
 public function test_ambiguous_children_conflicting_parents_and_foreign_ids_fail_closed():void {
  [$a,$c,$s,$ad]=$this->fixture();$s[1]->update(['external_ad_set_id'=>'other-set']);
  foreach([
   ['external_ad_id'=>'reused-ad'],
   ['external_campaign_id'=>'one','external_ad_set_id'=>'other-set'],
   ['external_campaign_id'=>'one','external_ad_set_id'=>'other-set','external_ad_id'=>'reused-ad'],
   ['external_campaign_id'=>'one','entity_level'=>'account'],
   ['external_campaign_id'=>''],
   ['currency'=>'EUR'],
   ['provider'=>'google'],
   ['external_account_id'=>'foreign-account'],
  ]as$ids){$r=$this->sync($a,[$this->row($ids)]);$this->assertSame('failed',$r->status);$this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());}
  [$foreign,$fc]=$this->fixture();$fc[0]->update(['external_campaign_id'=>'foreign-only']);$r=$this->sync($a,[$this->row(['external_campaign_id'=>'foreign-only'])]);$this->assertSame('failed',$r->status);
 }
 public function test_unambiguous_child_infers_its_actual_parents_but_alias_rows_cannot_overwrite():void {
  [$a,$c,$s,$ad]=$this->fixture();$ad[1]->update(['external_ad_id'=>'unique-ad']);$resolver=new CanonicalMetricEntityResolver;$resolved=$resolver->resolve($a,['external_ad_id'=>'unique-ad']);$this->assertSame(['campaign_id'=>$c[1]->id,'ad_set_id'=>$s[1]->id,'ad_id'=>$ad[1]->id,'entity_level'=>'ad'],$resolved);
  $r=$this->sync($a,[$this->row(['external_ad_id'=>'unique-ad']),$this->row(['external_campaign_id'=>'two','external_ad_set_id'=>'reused-set','external_ad_id'=>'unique-ad'])]);$this->assertSame('failed',$r->status);$this->assertStringContainsString('same canonical entity/day',$r->error_message);$this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());
 }
 public function test_genuine_account_rows_remain_account_level_and_explicit_level_must_match():void {
  [$a]=$this->fixture();$r=$this->sync($a,[$this->row(['entity_level'=>'account'])]);$this->assertSame('completed',$r->status);$m=PerformanceMetric::where('connected_ad_account_id',$a->id)->firstOrFail();$this->assertNull($m->campaign_id);$this->assertNull($m->ad_set_id);$this->assertNull($m->ad_id);$this->assertSame('account',$m->metadata['entity_level']);
 }
}
