<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,PerformanceMetric,SyncRun};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
class SyncProvenanceTest extends TestCase{
 use DatabaseTransactions;
 public function test_initial_import_failed_refresh_and_success_are_separate_in_both_endpoints():void{
  $u=User::factory()->create();$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>'provenance','account_name'=>'P','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);$this->actingAs($u,'sanctum');$get=fn()=>collect($this->getJson('/api/v1/ad-accounts')->assertOk()->json('data'))->firstWhere('id',$a->id)['sync_provenance'];$p=$get();$this->assertSame('no_stored_rows',$p['row_provenance']);$this->assertNull($p['last_successful_sync_at']);
  PerformanceMetric::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','date'=>now()->subDay(),'spend'=>10,'impressions'=>100,'clicks'=>5,'conversions'=>1,'conversion_value'=>20,'currency'=>'USD']);$p=$get();$this->assertSame('unverified_import_or_seed',$p['row_provenance']);$this->assertNull($p['last_successful_sync_at']);
  SyncRun::create(['connected_ad_account_id'=>$a->id,'sync_type'=>'manual','status'=>'completed','completed_at'=>now()->subDays(2)]);SyncRun::create(['connected_ad_account_id'=>$a->id,'sync_type'=>'manual','status'=>'failed','error_message'=>'fixture']);$p=$get();$this->assertSame('failed',$p['last_attempt_status']);$this->assertNotNull($p['last_successful_sync_at']);$cov=collect($this->getJson('/api/v1/analytics/data-coverage')->assertOk()->json('data.platforms'))->firstWhere('provider','meta');$this->assertSame($p,$cov['accounts'][0]['sync_provenance']);
  SyncRun::create(['connected_ad_account_id'=>$a->id,'sync_type'=>'historical','status'=>'completed','completed_at'=>now()->subDays(10)]);$again=$get();$this->assertSame($p['last_successful_sync_at'],$again['last_successful_sync_at']);$this->assertSame('completed',$again['last_attempt_status']);
 }
}
