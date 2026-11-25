<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CampaignHealthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_matrix_owns_campaigns_and_does_not_substitute_missing_days(): void
    {
        $u = User::factory()->create();
        $a = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'meta', 'external_account_id' => uniqid(), 'account_name' => 'Fixture', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 't']);
        $c = Campaign::create(['connected_ad_account_id' => $a->id, 'provider' => 'meta', 'external_campaign_id' => uniqid(), 'name' => 'Fixture', 'currency' => 'USD']);
        for ($i = 28; $i >= 1; $i--) {
            PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],'connected_ad_account_id' => $a->id, 'campaign_id' => $c->id, 'provider' => 'meta', 'currency' => 'USD', 'date' => now()->subDays($i)->toDateString(), 'spend' => 100, 'impressions' => 10000, 'clicks' => 100, 'conversions' => 10, 'conversion_value' => 200]);
        }
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/campaign-health?provider=meta')->assertOk()->assertJsonPath('data.rows.0.diagnostic.health', 'healthy');
        PerformanceMetric::where('campaign_id', $c->id)->whereDate('date', now()->subDay())->delete();
        $this->getJson('/api/v1/analytics/campaign-health?provider=meta')->assertJsonPath('data.rows.0.diagnostic.health', 'insufficient_data');
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/analytics/campaign-health?provider=meta')->assertJsonCount(0, 'data.rows');
    }
    public function test_account_local_windows_can_differ_within_one_provider():void{
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-23T01:00:00Z'));
        try{$u=User::factory()->create();foreach(['UTC','America/Los_Angeles']as$tz){$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Fixture','currency'=>'USD','timezone'=>$tz,'access_token'=>'fixture']);$c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>uniqid(),'name'=>'Fixture','currency'=>'USD']);$today=now()->setTimezone($tz);for($i=28;$i>=1;$i--)PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],'connected_ad_account_id'=>$a->id,'campaign_id'=>$c->id,'provider'=>'meta','currency'=>'USD','date'=>$today->copy()->subDays($i)->toDateString(),'spend'=>100,'impressions'=>10000,'clicks'=>100,'conversions'=>10,'conversion_value'=>200]);}
        $this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/campaign-health?provider=meta')->assertOk()->assertJsonPath('data.rows.0.diagnostic.health','healthy')->assertJsonPath('data.rows.1.diagnostic.health','healthy')->assertJsonPath('data.rows.0.window.1','2026-11-22')->assertJsonPath('data.rows.1.window.1','2026-11-21')->assertJsonPath('data.window',null);
        }finally{\Illuminate\Support\Carbon::setTestNow();}
    }

}
