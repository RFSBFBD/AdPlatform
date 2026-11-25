<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
class AttributionApiTest extends TestCase
{
    use DatabaseTransactions;
    public function test_authenticated_first_party_journey_api_and_no_fake_data_driven_model(): void
    {
        $u=User::factory()->create();$body=['model'=>'linear','journeys'=>[['conversion_id'=>'c','value'=>25,'currency'=>'EGP','converted_at'=>'2026-11-02T00:00:00Z','touches'=>[['event_id'=>'e','channel'=>'meta','at'=>'2026-11-01T00:00:00Z']]]]];
        $this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/attribution',$body)->assertOk()->assertJsonPath('data.allocations.meta.value_credit',25)->assertJsonPath('data.type','ATTRIBUTED_RULE_BASED');
        $body['model']='data_driven';$this->postJson('/api/v1/analytics/attribution',$body)->assertOk()->assertJsonPath('data.status','not_computable');
    }
    public function test_same_journey_comparison_endpoint_and_validation():void{
        $u=User::factory()->create();$j=['conversion_id'=>'fixture','value'=>100,'currency'=>'USD','converted_at'=>'2026-11-03T00:00:00Z','touches'=>[['event_id'=>'a','channel'=>'meta','at'=>'2026-11-01T00:00:00Z'],['event_id'=>'b','channel'=>'google','at'=>'2026-11-02T00:00:00Z']]];$b=['model'=>'linear','compare_models'=>true,'journeys'=>[$j]];$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/attribution',$b)->assertOk()->assertJsonPath('data.type','ATTRIBUTION_MODEL_COMPARISON')->assertJsonPath('data.models.first_click.allocations.meta.value_credit',100)->assertJsonPath('data.models.last_click.allocations.google.value_credit',100)->assertJsonCount(5,'data.models');$b['journeys'][]=$j;$this->postJson('/api/v1/analytics/attribution',$b)->assertStatus(422);$b['journeys'][1]['conversion_id']='other';$b['journeys'][1]['currency']='EGP';$this->postJson('/api/v1/analytics/attribution',$b)->assertStatus(422);
    }
}
