<?php
namespace Tests\Feature;
use App\Models\{ConnectedAdAccount,User};
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
final class MetaHierarchyPaginationTest extends TestCase {
 use DatabaseTransactions;
 private function account():ConnectedAdAccount{return ConnectedAdAccount::create(['user_id'=>User::factory()->create()->id,'provider'=>'meta','external_account_id'=>'test','account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);}
 public function test_nested_empty_pages_continue_to_ads_and_audience_objects_and_retain_raw_pages():void{
  Http::preventStrayRequests();Http::fake([
   'graph.facebook.com/v21.0/act_test/campaigns*'=>Http::response(['data'=>[['id'=>'c','adsets'=>['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/c/adsets?after=one']]]]]),
   'graph.facebook.com/v21.0/c/adsets?after=one'=>Http::response(['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/c/adsets?after=two']]),
   'graph.facebook.com/v21.0/c/adsets?after=two'=>Http::response(['data'=>[['id'=>'s','targeting'=>['custom_audiences'=>[['id'=>'a']]],'ads'=>['data'=>[['id'=>'first']],'paging'=>['next'=>'https://graph.facebook.com/v21.0/s/ads?after=x']]]]]),
   'graph.facebook.com/v21.0/s/ads?after=x'=>Http::response(['data'=>[['id'=>'second','creative'=>['id'=>'creative']]]]),
  ]);$r=(new MetaAdsAdapter)->fetchCampaigns($this->account());$this->assertCount(4,$r['raw']);$sets=$r['campaigns'][0]['ad_sets'];$this->assertCount(1,$sets);$this->assertCount(2,$sets[0]['ads']);$this->assertSame('creative',$sets[0]['ads'][1]['canonical_creative']['external_creative_id']);$this->assertSame('a',$sets[0]['canonical_audience_references']['included'][0]['external_audience_id']);Http::assertSentCount(4);
 }
 public function test_untrusted_next_destinations_and_wrong_parent_are_rejected_without_following():void{
  $account=$this->account();foreach(['https://example.invalid/v21.0/c/adsets?after=x','http://graph.facebook.com/v21.0/c/adsets?after=x','https://graph.facebook.com/v21.0/foreign/adsets?after=x','https://graph.facebook.com:443/v21.0/c/adsets?after=x'] as $next){Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['data'=>[['id'=>'c','adsets'=>['data'=>[],'paging'=>['next'=>$next]]]]])]);try{(new MetaAdsAdapter)->fetchCampaigns($account);$this->fail('Unexpected edge accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('expected provider edge',$e->getMessage());}Http::assertSentCount(1);}
 }
 public function test_repeated_page_and_duplicate_nested_ids_fail_instead_of_returning_partial_inventory():void{
  $account=$this->account();foreach([true,false]as$cycle){Http::swap(new \Illuminate\Http\Client\Factory);$next='https://graph.facebook.com/v21.0/c/adsets?after=x';Http::fake(['*campaigns*'=>Http::response(['data'=>[['id'=>'c','adsets'=>['data'=>[['id'=>'s']],'paging'=>['next'=>$next]]]]]),'*adsets*'=>Http::response($cycle?['data'=>[],'paging'=>['next'=>$next]]:['data'=>[['id'=>'s']]])]);try{(new MetaAdsAdapter)->fetchCampaigns($account);$this->fail('Incomplete hierarchy accepted');}catch(\RuntimeException $e){$this->assertMatchesRegularExpression('/cycle|duplicate/',$e->getMessage());}Http::assertSentCount(2);}
 }
 public function test_top_level_and_nested_caps_and_http_failures_never_return_partial_success():void{
  $account=$this->account();
  foreach(['cap','nestedcap','http','malformed']as$mode){Http::swap(new \Illuminate\Http\Client\Factory);$count=0;Http::fake(function($request)use($mode,&$count){++$count;if($mode==='malformed')return Http::response(['error'=>'invalid envelope']);if($mode==='nestedcap')return $count===1?Http::response(['data'=>[['id'=>'c','adsets'=>['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/c/adsets?after=1']]]]]):Http::response(['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/c/adsets?after='.$count]]);if($mode==='http')return $count===1?Http::response(['data'=>[['id'=>'c','adsets'=>['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/c/adsets?after=x']]]]]):Http::response([],400);return Http::response(['data'=>[],'paging'=>['next'=>'https://graph.facebook.com/v21.0/act_test/campaigns?after='.$count]]);});try{(new MetaAdsAdapter)->fetchCampaigns($account);$this->fail('Truncated hierarchy accepted');}catch(\RuntimeException $e){$this->assertMatchesRegularExpression('/limit|failed|malformed/',$e->getMessage());}$this->assertSame($mode==='cap'?100:($mode==='nestedcap'?101:($mode==='http'?2:1)),$count);}
 }

}
