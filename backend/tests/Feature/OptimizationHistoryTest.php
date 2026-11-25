<?php
namespace Tests\Feature;
use App\Models\{User,OptimizationRun};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
final class OptimizationHistoryTest extends TestCase{
 use DatabaseTransactions;
 private function record(User $u,string $engine='entity'):OptimizationRun{return OptimizationRun::create(['user_id'=>$u->id,'engine'=>$engine,'engine_version'=>'fixture','status'=>'ok','input_hash'=>str_repeat('2',64),'dataset_fingerprint'=>[],'inputs'=>['private'=>'not_listed'],'result'=>['objective'=>'MAXIMIZE_CONVERSIONS','entity_level'=>'campaign','currency'=>'EGP','scenario_total_spend'=>50,'scenario_response'=>10,'allocations'=>[['entity_key'=>'not_listed']]],'source_snapshot'=>['private'=>'not_listed'],'snapshot_sha256'=>str_repeat('1',64)]);}
 public function test_owner_history_cursor_is_stable_and_does_not_expose_source_payloads():void{$u=User::factory()->create();$other=User::factory()->create();$first=$this->record($u);$second=$this->record($u);$this->record($u,'portfolio');$this->record($other);$r=$this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/optimization-runs?limit=1')->assertOk()->assertJsonCount(1,'data.rows')->assertJsonPath('data.rows.0.id',$second->id)->assertJsonPath('data.next_before_id',$second->id)->assertDontSee('not_listed');$this->getJson('/api/v1/analytics/optimization-runs?limit=1&before_id='.$r->json('data.next_before_id'))->assertOk()->assertJsonPath('data.rows.0.id',$first->id)->assertJsonPath('data.next_before_id',null);$this->getJson('/api/v1/analytics/optimization-runs?limit=26')->assertStatus(422);}
 public function test_empty_owner_history_does_not_return_another_owners_rows():void{$u=User::factory()->create();$this->record(User::factory()->create());$this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/optimization-runs')->assertOk()->assertJsonCount(0,'data.rows');}
}
