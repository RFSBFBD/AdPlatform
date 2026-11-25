<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,PerformanceMetric,OptimizationRun,RawProviderPayload};
use App\Services\Advertising\{Adapters\SnapchatAdsAdapter,OAuthManager,OutcomeContract,Contracts\AdvertisingProviderInterface};
use App\Services\Advertising\Sync\AdDataSyncEngine;
use App\Services\Analytics\{MetaDefinitionTransitionGuard,OutcomeSemanticGuard};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;
final class SnapchatSourceScopeCorrectionTest extends TestCase {
 use DatabaseTransactions;
 private function account():ConnectedAdAccount{return ConnectedAdAccount::create(['user_id'=>User::factory()->create()->id,'provider'=>'snapchat','external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture','last_synced_at'=>'2026-10-01']);}
 public function test_documented_spend_only_reply_is_retained_raw_but_never_imported_or_marked_success():void{
  $a=$this->account();$before=$a->last_synced_at->toIso8601String();Http::fake(['*'=>Http::response(['request_status'=>'SUCCESS','timeseries_stats'=>[['sub_request_status'=>'SUCCESS','timeseries_stat'=>['id'=>$a->external_account_id,'type'=>'AD_ACCOUNT','granularity'=>'DAY','timeseries'=>[['start_time'=>'2026-11-01T00:00:00Z','end_time'=>'2026-11-02T00:00:00Z','stats'=>['spend'=>12500000]]]]]]])]);
  $manager=Mockery::mock(OAuthManager::class);$manager->shouldReceive('getAdapter')->andReturn(new SnapchatAdsAdapter);
  $run=(new AdDataSyncEngine($manager))->syncAccount($a,'manual','2026-11-01','2026-11-01');$this->assertSame('failed',$run->status);$this->assertStringContainsString('spend-inspection only',$run->error_message);$this->assertSame($before,$a->fresh()->last_synced_at->toIso8601String());$this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());$this->assertTrue(RawProviderPayload::where('connected_ad_account_id',$a->id)->where('endpoint','like','%stats%')->exists());
 }
 public function test_even_explicit_unsupported_fields_and_claimed_normalization_cannot_bypass_import():void{
  $a=$this->account();$adapter=Mockery::mock(AdvertisingProviderInterface::class);$adapter->shouldReceive('fetchCampaigns')->andReturn(['campaigns'=>[]]);$adapter->shouldReceive('fetchInsights')->andReturn(['metrics'=>[['date'=>'2026-11-01','spend'=>1,'impressions'=>100,'clicks'=>2,'conversions'=>3,'conversion_value'=>99]],'fetch_status'=>'complete_local_traversal_not_inventory_verified']);$manager=Mockery::mock(OAuthManager::class);$manager->shouldReceive('getAdapter')->andReturn($adapter);$run=(new AdDataSyncEngine($manager))->syncAccount($a,'manual','2026-11-01','2026-11-01');$this->assertSame('failed',$run->status);$this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());$this->assertNull(OutcomeContract::forRow($a,[]));
 }
 public function test_old_normalized_rows_archives_and_typed_sources_are_withheld_without_repair():void{
  $a=$this->account();$row=PerformanceMetric::create(['connected_ad_account_id'=>$a->id,'provider'=>'snapchat','date'=>'2026-11-01','currency'=>'USD','spend'=>12,'impressions'=>100,'clicks'=>2,'conversions'=>3,'conversion_value'=>99,'metadata'=>['normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'snapchat','count_metric'=>'conversion_purchases','value_metric'=>'conversion_purchases_value','attribution_basis'=>'swipe_28d_view_1d','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false]]]);$row->refresh();$before=$row->getAttributes();$guard=new MetaDefinitionTransitionGuard;$this->assertNotNull($guard->problem([$row]));$this->assertNotNull($guard->problem([$row],false));$this->assertNotNull((new OutcomeSemanticGuard)->problem([$row]));
  $archive=new OptimizationRun(['inputs'=>['objective'=>'MAXIMIZE_LEADS'],'source_snapshot'=>['source_rows'=>[$before]]]);$this->assertNotNull($guard->archiveProblem($archive));$this->actingAs($a->user,'sanctum')->getJson('/api/v1/analytics/overview?provider=snapchat&start_date=2026-11-01&end_date=2026-11-01')->assertStatus(409);$this->actingAs($a->user,'sanctum')->getJson('/api/v1/analytics/overview?start_date=2026-11-01&end_date=2026-11-01')->assertOk()->assertJsonPath('excluded_providers.0.provider','snapchat');$this->assertSame($before,$row->fresh()->getAttributes());
 }
}
