<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,Campaign,ConversionDefinition,FirstPartyConversionEvent,TypedConversionDaily,PerformanceMetric};
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
final class FirstPartyEventLedgerTest extends TestCase
{
 use DatabaseTransactions;
 private function fixture():array {
  (new RoleAndPermissionSeeder)->run();$u=User::factory()->create();$u->assignRole('Owner');
  $a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);
  $c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>uniqid(),'name'=>'Synthetic','currency'=>'USD']);
  $d=ConversionDefinition::create(['connected_ad_account_id'=>$a->id,'external_key'=>'source','name'=>'Synthetic','kind'=>'purchase','counting_method'=>'events','attribution_model'=>'declared','click_window_days'=>7,'view_window_days'=>0,'source_system'=>'manual']);
  return [$u,$a,$c,$d];
 }
 private function url($a,$d):string{return '/api/v1/ad-accounts/'.$a->id.'/conversion-definitions/'.$d->id.'/events';}
 private function event($c,string $key='opaque-1'):array{return ['event_key'=>$key,'entity_level'=>'campaign','entity_id'=>$c->id,'occurred_at'=>'2026-01-01T10:00:00Z','value'=>'12.3400','currency'=>'USD'];}
 public function test_import_replay_normalization_and_immutable_conflict_are_atomic():void {
  [$u,$a,$c,$d]=$this->fixture();$this->actingAs($u,'sanctum');$url=$this->url($a,$d);$e=$this->event($c);
  $this->postJson($url,['events'=>[$e]])->assertCreated()->assertJsonPath('data.inserted',1)->assertJsonPath('data.provider_verified',false);
  $e['occurred_at']='2026-01-01T12:00:00+02:00';$e['value']=12.34;
  $this->postJson($url,['events'=>[$e]])->assertOk()->assertJsonPath('data.replayed',1)->assertJsonPath('data.inserted',0);
  $e['value']='12.3401';$this->postJson($url,['events'=>[$this->event($c,'new-key'),$e]])->assertConflict();
  $this->assertSame(1,FirstPartyConversionEvent::where('conversion_definition_id',$d->id)->count());
  $stored=FirstPartyConversionEvent::where('conversion_definition_id',$d->id)->first();
  $this->assertSame('12.3400',$stored->value);
  $this->assertSame('2026-01-01T10:00:00Z',$stored->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'));
  $this->assertLessThan(2,abs($stored->accepted_at->timestamp-time()));
  $this->assertSame(0,TypedConversionDaily::where('conversion_definition_id',$d->id)->count());
  $this->assertSame(0,PerformanceMetric::where('connected_ad_account_id',$a->id)->count());
 }
 public function test_owned_scope_and_write_permission():void {
  [$u,$a,$c,$d]=$this->fixture();$url=$this->url($a,$d);$body=['events'=>[$this->event($c)]];
  $this->postJson($url,$body)->assertUnauthorized();$other=User::factory()->create();$other->assignRole('Owner');
  $this->actingAs($other,'sanctum')->postJson($url,$body)->assertNotFound();$this->getJson($url)->assertNotFound();
  $u->removeRole('Owner');$u->assignRole('Viewer');$this->actingAs($u,'sanctum')->postJson($url,$body)->assertForbidden();$this->getJson($url)->assertOk();
  [$ou,$oa,$oc,$od]=$this->fixture();$this->getJson($this->url($a,$od))->assertNotFound();
  $u->assignRole('Owner');$this->postJson($url,['events'=>[$this->event($oc)]])->assertUnprocessable();
 }
 public function test_strict_schema_semantics_and_time_guards():void {
  [$u,$a,$c,$d]=$this->fixture();$this->actingAs($u,'sanctum');$url=$this->url($a,$d);$e=$this->event($c);
  foreach([
   ['currency'=>'EUR'],['value'=>'1.23456'],['value'=>-1],['occurred_at'=>'2026-02-30T10:00:00Z'],['occurred_at'=>'2026-01-01'],['occurred_at'=>'2026-01-01T10:00:00+25:00'],['occurred_at'=>now()->addDay()->format('Y-m-d\TH:i:sP')],['event_key'=>'contact@example.com'],['customer_email'=>'not allowed'],['entity_level'=>'account']
  ]as$patch){$this->postJson($url,['events'=>[array_merge($e,$patch)]])->assertUnprocessable();}
  $this->postJson($url,['events'=>[$e,$e]])->assertUnprocessable();
  $this->postJson($url,['events'=>[$e],'customer_payload'=>[]])->assertUnprocessable();
  $d->update(['counting_method'=>'fractional_attributed']);$this->postJson($url,['events'=>[$e]])->assertUnprocessable();
  $this->assertSame(0,FirstPartyConversionEvent::where('conversion_definition_id',$d->id)->count());
 }
 public function test_cursor_and_exact_definition_scope():void {
  [$u,$a,$c,$d]=$this->fixture();$this->actingAs($u,'sanctum');$url=$this->url($a,$d);
  $this->postJson($url,['events'=>[$this->event($c,'one'),$this->event($c,'two'),$this->event($c,'three')]])->assertCreated();
  $r=$this->getJson($url.'?limit=2')->assertOk()->assertJsonCount(2,'data.events');$cursor=$r->json('data.next_before_id');
  $this->getJson($url.'?limit=2&before_id='.$cursor)->assertOk()->assertJsonCount(1,'data.events')->assertJsonPath('data.next_before_id',null);
  $d2=ConversionDefinition::create(array_merge($d->only(['connected_ad_account_id','name','kind','counting_method','attribution_model','click_window_days','view_window_days','source_system']),['external_key'=>'different-source']));
  $this->postJson($this->url($a,$d2),['events'=>[$this->event($c,'one')]])->assertCreated()->assertJsonPath('data.inserted',1);
  $this->getJson($this->url($a,$d2))->assertOk()->assertJsonCount(1,'data.events');
 }
}
