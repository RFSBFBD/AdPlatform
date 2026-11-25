<?php
namespace Tests\Feature;
use App\Models\{ConnectedAdAccount,User,PerformanceMetric};
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use App\Services\Advertising\OAuthManager;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;
final class MetaReportPaginationTest extends TestCase {
 use DatabaseTransactions;
 private function account():ConnectedAdAccount{return ConnectedAdAccount::create(['user_id'=>User::factory()->create()->id,'provider'=>'meta','external_account_id'=>'paging-test','account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);}
 private function row():array{return ['campaign_id'=>'c','date_start'=>'2026-11-01','spend'=>'10','impressions'=>'100','clicks'=>'2','actions'=>[['action_type'=>'offsite_conversion.fb_pixel_purchase','value'=>'1']],'action_values'=>[['action_type'=>'offsite_conversion.fb_pixel_purchase','value'=>'20']]];}
 public function test_empty_pages_with_next_continue_until_last_and_keep_all_raw_pages():void{
  $a=$this->account();$count=0;Http::preventStrayRequests();Http::fake(function($r)use(&$count){$count++;return Http::response($count===1?['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/act_paging-test/insights?after=1']]:($count===2?['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/act_paging-test/insights?after=2']]:['data'=>[$this->row()]]));});$result=(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01');$this->assertCount(3,$result['raw']);$this->assertCount(1,$result['metrics']);Http::assertSentCount(3);
 }
 public function test_insight_caps_cycles_duplicates_bad_envelopes_and_destinations_reject():void{
  $a=$this->account();foreach(['cap','cycle','duplicate','malformed','badnext','offhost','wrongedge','redirect','http']as$mode){Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();$count=0;Http::fake(function($r)use(&$count,$mode){$count++;$base='https://graph.facebook.com/v21.0/act_paging-test/insights?after=';if($mode==='redirect')return Http::response('',302,['Location'=>'https://example.invalid/collect']);if($mode==='malformed')return Http::response(['data'=>'bad']);if($mode==='http'&&$count>1)return Http::response([],400);$next=match($mode){'offhost'=>'https://example.invalid/collect','wrongedge'=>'https://graph.facebook.com/v21.0/foreign/insights?after=1','badnext'=>'','cycle'=>$base.'1',default=>$base.$count};return Http::response(['data'=>$mode==='duplicate'?[$this->row()]:[],'paging'=>['next'=>$next]]);});try{(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01');$this->fail('Partial report accepted: '.$mode);}catch(\RuntimeException){$this->assertTrue(true);}$this->assertSame($mode==='cap'?100:(in_array($mode,['cycle','duplicate','http'])?2:1),$count);}
 }
 public function test_discovery_empty_page_then_data_and_unsafe_or_endless_pages_fail_closed():void{
  foreach(['ok','offhost','cycle','cap','duplicate','malformed']as$mode){Http::swap(new \Illuminate\Http\Client\Factory);$count=0;Http::fake(function($r)use(&$count,$mode){$count++;$next='https://graph.facebook.com/v21.0/me/adaccounts?after=';if($mode==='malformed')return Http::response(['data'=>null]);if($mode==='ok'&&$count===2)return Http::response(['data'=>[['id'=>'act_1','account_id'=>'1','currency'=>'USD','timezone_name'=>'UTC']]]);return Http::response(['data'=>$mode==='duplicate'?[['id'=>'act_1']]:[],'paging'=>['next'=>$mode==='offhost'?'https://example.invalid/collect':$next.($mode==='cycle'?'1':$count)]]);});if($mode==='ok'){$this->assertCount(1,(new MetaAdsAdapter)->discoverAccounts('fixture'));$this->assertSame(2,$count);}else{try{(new MetaAdsAdapter)->discoverAccounts('fixture');$this->fail('Partial discovery accepted');}catch(\RuntimeException){$this->assertTrue(true);}$this->assertSame($mode==='cap'?100:(in_array($mode,['cycle','duplicate'])?2:1),$count);}}
 }
 public function test_shared_sync_later_report_page_failure_never_commits_first_page_metrics():void{
  $a=$this->account();Http::preventStrayRequests();Http::fake(['*campaigns*'=>Http::response(['data'=>[['id'=>'c','name'=>'Synthetic']]]),'*insights*'=>Http::sequence()->push(['data'=>[$this->row()],'paging'=>['next'=>'https://graph.facebook.com/v21.0/act_paging-test/insights?after=2']])->push([],400)]);$manager=Mockery::mock(OAuthManager::class);$manager->shouldReceive('getAdapter')->with('meta')->andReturn(new MetaAdsAdapter);$run=(new AdDataSyncEngine($manager))->syncAccount($a,'manual','2026-11-01','2026-11-01');$this->assertSame('failed',$run->status);$this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());$this->assertNull($a->fresh()->last_synced_at);
 }
}
