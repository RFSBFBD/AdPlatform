<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,Campaign,ConversionDefinition,FirstPartyConversionEvent,FirstPartyEventJourney};
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
final class StoredFirstPartyAttributionTest extends TestCase {
 use DatabaseTransactions;
 private function fixture():array {
  (new RoleAndPermissionSeeder)->run();$u=User::factory()->create();$u->assignRole('Owner');$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);$c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>uniqid(),'name'=>'Synthetic','currency'=>'USD']);$d=ConversionDefinition::create(['connected_ad_account_id'=>$a->id,'external_key'=>'declared','name'=>'Synthetic','kind'=>'purchase','counting_method'=>'events','attribution_model'=>'declared_last_click','click_window_days'=>7,'view_window_days'=>0,'source_system'=>'manual']);$this->actingAs($u,'sanctum');$base='/api/v1/ad-accounts/'.$a->id.'/conversion-definitions/'.$d->id;
  $this->postJson($base.'/events',['events'=>[['event_key'=>'random-opaque','entity_level'=>'campaign','entity_id'=>$c->id,'occurred_at'=>'2026-01-10T10:00:00Z','value'=>'100.00','currency'=>'USD']]])->assertCreated();$e=FirstPartyConversionEvent::where('conversion_definition_id',$d->id)->first();return [$u,$a,$c,$d,$e,$base];
 }
 private function touches():array {return [['event_id'=>'click-one','channel'=>'meta','at'=>'2026-01-05T10:00:00Z'],['event_id'=>'click-two','channel'=>'google','at'=>'2026-01-09T10:00:00Z']];}
 public function test_stored_source_attribution_conserves_and_reproduces():void {
  [$u,$a,$c,$d,$e,$base]=$this->fixture();$url=$base.'/events/'.$e->id.'/journey';$t=$this->touches();$this->postJson($url,['touches'=>$t])->assertCreated()->assertJsonPath('data.provider_verified',false);$t[0]['at']='2026-01-05T12:00:00+02:00';$this->postJson($url,['touches'=>$t])->assertOk()->assertJsonPath('data.replayed',true);
  $body=['event_ids'=>[$e->id],'model'=>'linear','compare_models'=>true];$r=$this->postJson($base.'/stored-attribution',$body)->assertOk()->assertJsonPath('data.status','ok')->assertJsonPath('data.conversion_count',1)->assertJsonPath('data.input_value',100)->assertJsonPath('data.models.linear.allocations.meta.value_credit',50)->assertJsonPath('data.models.last_click.allocations.google.value_credit',100)->assertJsonPath('data.definition.attribution_model','declared_last_click')->assertJsonCount(1,'data.source_evidence');
  $hash=$r->json('data.input_sha256');$this->postJson($base.'/stored-attribution',$body)->assertJsonPath('data.input_sha256',$hash);
  $t[0]['channel']='pinterest';$this->postJson($url,['touches'=>$t])->assertConflict();$this->assertSame(1,FirstPartyEventJourney::where('first_party_conversion_event_id',$e->id)->count());
  $this->postJson($base.'/stored-attribution',['event_ids'=>[$e->id],'model'=>'data_driven'])->assertJsonPath('data.status','not_computable');
 }
 public function test_missing_evidence_and_scope_never_silently_drop_events():void {
  [$u,$a,$c,$d,$e,$base]=$this->fixture();$body=['event_ids'=>[$e->id],'model'=>'linear'];$this->postJson($base.'/stored-attribution',$body)->assertJsonPath('data.status','incomplete_journey_evidence');$this->postJson($base.'/stored-attribution',['event_ids'=>[$e->id,$e->id+10000],'model'=>'linear'])->assertNotFound();$this->postJson($base.'/stored-attribution',['event_ids'=>[$e->id,$e->id],'model'=>'linear'])->assertUnprocessable();
  $other=User::factory()->create();$other->assignRole('Owner');$this->actingAs($other,'sanctum');$this->postJson($base.'/stored-attribution',$body)->assertNotFound();$this->postJson($base.'/events/'.$e->id.'/journey',['touches'=>$this->touches()])->assertNotFound();
  $u->removeRole('Owner');$u->assignRole('Viewer');$this->actingAs($u,'sanctum');$this->postJson($base.'/events/'.$e->id.'/journey',['touches'=>$this->touches()])->assertForbidden();$this->postJson($base.'/stored-attribution',$body)->assertOk();
 }
 public function test_integrity_mismatch_refuses_attribution_not_a_new_source_truth():void {
  [$u,$a,$c,$d,$e,$base]=$this->fixture();$this->postJson($base.'/events/'.$e->id.'/journey',['touches'=>$this->touches()])->assertCreated();
  $body=['event_ids'=>[$e->id],'model'=>'linear'];$j=FirstPartyEventJourney::where('first_party_conversion_event_id',$e->id)->first();$original=$j->touches;$changed=$original;$changed[0]['channel']='pinterest';$j->update(['touches'=>$changed]);$this->postJson($base.'/stored-attribution',$body)->assertConflict();$j->update(['touches'=>$original]);$e->update(['value'=>'999.0000']);$this->postJson($base.'/stored-attribution',$body)->assertConflict();
 }
 public function test_click_window_strict_time_order_and_schema():void {
  [$u,$a,$c,$d,$e,$base]=$this->fixture();$url=$base.'/events/'.$e->id.'/journey';
  foreach([
   [['event_id'=>'click','channel'=>'meta','at'=>'2026-01-01T10:00:00Z']],
   [['event_id'=>'click','channel'=>'meta','at'=>'2026-01-11T10:00:00Z']],
   [['event_id'=>'click','channel'=>'meta','at'=>'2026-01-09T10:00:00+25:00']],
   [['event_id'=>'click','channel'=>'meta','at'=>'2026-01-09T10:00:00Z','customer_id'=>'bad']],
   array_reverse($this->touches()),
   [$this->touches()[0],$this->touches()[0]],
  ]as$t)$this->postJson($url,['touches'=>$t])->assertUnprocessable();
  $this->postJson($url,['touches'=>$this->touches(),'customer_payload'=>[]])->assertUnprocessable();
  $this->assertSame(0,FirstPartyEventJourney::where('first_party_conversion_event_id',$e->id)->count());
  $t=[['event_id'=>'click','channel'=>'meta','at'=>'2026-01-03T10:00:00Z']];$this->postJson($url,['touches'=>$t])->assertCreated();
 }
}
