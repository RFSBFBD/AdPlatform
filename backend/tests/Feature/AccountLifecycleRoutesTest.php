<?php
namespace Tests\Feature;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
class AccountLifecycleRoutesTest extends TestCase{
 use DatabaseTransactions;
 public function test_logout_revokes_only_the_current_bearer_token():void{
  $u=User::factory()->create();$one=$u->createToken('one');$two=$u->createToken('two');$this->withToken($one->plainTextToken)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('success',true);$this->assertDatabaseMissing('personal_access_tokens',['id'=>$one->accessToken->id]);$this->assertDatabaseHas('personal_access_tokens',['id'=>$two->accessToken->id]);$this->assertDatabaseHas('audit_logs',['action'=>'auth.logout','user_id'=>$u->id]);
 }
 public function test_role_update_requires_permission_and_blocks_self_demotion():void{
  (new RoleAndPermissionSeeder)->run();$owner=User::factory()->create();$owner->assignRole('Owner');$viewer=User::factory()->create();$viewer->assignRole('Viewer');$target=User::factory()->create();$target->assignRole('Analyst');$this->actingAs($viewer,'sanctum')->putJson('/api/v1/users/'.$target->id.'/role',['role'=>'Admin'])->assertForbidden();$this->actingAs($owner,'sanctum')->putJson('/api/v1/users/'.$owner->id.'/role',['role'=>'Viewer'])->assertForbidden();$this->putJson('/api/v1/users/'.$target->id.'/role',['role'=>'Viewer'])->assertOk()->assertJsonPath('data.role','Viewer');$this->assertTrue($target->fresh()->hasRole('Viewer'));$this->assertDatabaseHas('audit_logs',['action'=>'user.role_updated','entity_id'=>(string)$target->id]);$this->putJson('/api/v1/users/'.$target->id.'/role',['role'=>'not-a-role'])->assertUnprocessable();
 }
 public function test_user_delete_is_permission_gated_soft_delete_and_refuses_self_delete():void{
  (new RoleAndPermissionSeeder)->run();$owner=User::factory()->create();$owner->assignRole('Owner');$viewer=User::factory()->create();$viewer->assignRole('Viewer');$target=User::factory()->create();$this->actingAs($viewer,'sanctum')->deleteJson('/api/v1/users/'.$target->id)->assertForbidden();$this->actingAs($owner,'sanctum')->deleteJson('/api/v1/users/'.$owner->id)->assertForbidden();$this->deleteJson('/api/v1/users/'.$target->id)->assertOk();$this->assertSoftDeleted('users',['id'=>$target->id]);$this->assertDatabaseHas('audit_logs',['action'=>'user.deleted','entity_id'=>(string)$target->id]);$this->deleteJson('/api/v1/users/'.$target->id)->assertNotFound();
 }
}
