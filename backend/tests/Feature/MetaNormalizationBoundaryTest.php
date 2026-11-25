<?php
namespace Tests\Feature;
use App\Models\{ConnectedAdAccount,User,PerformanceMetric};
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use App\Services\Advertising\{OAuthManager};
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;
final class MetaNormalizationBoundaryTest extends TestCase {
 use DatabaseTransactions;
 private function account():ConnectedAdAccount{return ConnectedAdAccount::create(['user_id'=>User::factory()->create()->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);}
 private function row():array{return ['campaign_id'=>'c','date_start'=>'2026-11-01','spend'=>'0','impressions'=>'0','clicks'=>'0','actions'=>[['action_type'=>'offsite_conversion.fb_pixel_purchase','value'=>'0']],'action_values'=>[['action_type'=>'offsite_conversion.fb_pixel_purchase','value'=>'0']]];}
 public function test_missing_required_operands_remain_unknown_and_explicit_zero_stays_zero():void{
  $a=$this->account();foreach(['spend','impressions','clicks']as$key){$row=$this->row();unset($row[$key]);Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['data'=>[$row]])]);$m=(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01')['metrics'][0];$this->assertNull($m[$key]);}
  Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['data'=>[$this->row()+['reach'=>'0','frequency'=>'0']]])]);$m=(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01')['metrics'][0];$this->assertSame(0.0,$m['spend']);$this->assertSame(0,$m['impressions']);$this->assertSame(0,$m['clicks']);$this->assertSame(0,$m['reach']);$this->assertSame(0.0,$m['frequency']);
 }
 public function test_malformed_negative_fractional_counts_and_overflow_reject_before_coercion():void{
  $a=$this->account();foreach([['spend','bad'],['spend',true],['spend',[]],['spend','-1'],['spend','1e500'],['spend','10000000000'],['impressions','1.5'],['clicks','9007199254740992'],['reach','1.1'],['frequency','bad']]as[$key,$value]){Http::swap(new \Illuminate\Http\Client\Factory);$row=$this->row();$row[$key]=$value;Http::fake(['*'=>Http::response(['data'=>[$row]])]);try{(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01');$this->fail('Invalid operand accepted: '.$key);}catch(\RuntimeException){$this->assertTrue(true);}}
 }
 public function test_discovery_does_not_invent_currency_timezone_or_active_status():void{Http::fake(['*'=>Http::response(['data'=>[['id'=>'act_1'],['id'=>'act_2','currency'=>'BADVALUE','timezone_name'=>'imaginary'],['id'=>'act_3','currency'=>'EGP','timezone_name'=>'Africa/Cairo']]])]);$r=(new MetaAdsAdapter)->discoverAccounts('fixture');foreach([0,1]as$i){$this->assertNull($r[$i]['currency']);$this->assertNull($r[$i]['timezone']);$this->assertNull($r[$i]['raw_status']);}$this->assertSame('EGP',$r[2]['currency']);$this->assertSame('Africa/Cairo',$r[2]['timezone']);}
 public function test_shared_import_missing_operand_rejects_whole_batch_without_synthetic_zero():void{$a=$this->account();$row=$this->row();unset($row['clicks']);Http::fake(['*campaigns*'=>Http::response(['data'=>[['id'=>'c']]]),'*insights*'=>Http::response(['data'=>[$row]])]);$manager=Mockery::mock(OAuthManager::class);$manager->shouldReceive('getAdapter')->with('meta')->andReturn(new MetaAdsAdapter);$run=(new AdDataSyncEngine($manager))->syncAccount($a,'manual','2026-11-01','2026-11-01');$this->assertSame('failed',$run->status);$this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());}
 public function test_conflicting_account_ids_invalid_dates_and_ids_reject():void{
  Http::fake(['*'=>Http::response(['data'=>[['id'=>'act_1','account_id'=>'2']]])]);try{(new MetaAdsAdapter)->discoverAccounts('fixture');$this->fail('Conflicting account IDs accepted');}catch(\RuntimeException){$this->assertTrue(true);}
  $a=$this->account();foreach([['campaign_id'=>''],['campaign_id'=>true],['date_start'=>'2026-11-31'],['date_start'=>'2026-10-31'],['date_stop'=>'2026-11-02']]as$bad){Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['data'=>[array_replace($this->row(),$bad)]])]);try{(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01');$this->fail('Invalid daily report accepted');}catch(\RuntimeException){$this->assertTrue(true);}}
 }

 public function test_new_sync_cannot_bless_prior_defaulted_account_metadata():void{
  $a=$this->account();Http::fake(['*'=>Http::response(['data'=>[$this->row()]])]);$adapter=new MetaAdsAdapter;$m=$adapter->fetchInsights($a,'2026-11-01','2026-11-01')['metrics'][0];$this->assertSame('unverified_legacy_or_missing',$m['metric_definition']['account_metadata_source']);
  $a->metadata=['metadata_source'=>['version'=>'meta-discovery-explicit-1','currency'=>'USD','timezone'=>'UTC']];$a->save();$m=$adapter->fetchInsights($a,'2026-11-01','2026-11-01')['metrics'][0];$this->assertSame('meta-discovery-explicit-1',$m['metric_definition']['account_metadata_source']);$a->timezone='Africa/Cairo';$m=$adapter->fetchInsights($a,'2026-11-01','2026-11-01')['metrics'][0];$this->assertSame('unverified_legacy_or_missing',$m['metric_definition']['account_metadata_source']);
 }

 public function test_missing_discovery_metadata_cannot_connect_and_explicit_source_is_retained():void{
  $u=User::factory()->create();foreach([false,true]as$explicit){$meta=['provider'=>'meta','external_account_id'=>'source-test','account_name'=>'Synthetic','currency'=>$explicit?'EGP':null,'timezone'=>$explicit?'Africa/Cairo':null,'metadata_source'=>['version'=>'meta-discovery-explicit-1','currency'=>$explicit?'EGP':null,'timezone'=>$explicit?'Africa/Cairo':null]];\Illuminate\Support\Facades\Cache::put('discovered_accounts:meta:'.$u->id,['token_data'=>['access_token'=>'fixture'],'accounts'=>[$meta]],600);$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/integrations/meta/connect',['selected_account_ids'=>['source-test']]);if(!$explicit){$r->assertStatus(422)->assertJsonPath('error.code','ACCOUNT_METADATA_MISSING');$this->assertSame(0,ConnectedAdAccount::where('user_id',$u->id)->count());}else{$r->assertOk();$a=ConnectedAdAccount::where('user_id',$u->id)->sole();$this->assertSame('meta-discovery-explicit-1',$a->metadata['metadata_source']['version']);$this->assertSame('EGP',$a->currency);$this->assertSame('Africa/Cairo',$a->timezone);}}
 }

}
