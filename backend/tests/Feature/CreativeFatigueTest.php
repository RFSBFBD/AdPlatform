<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use App\Services\Analytics\CreativeFatigueEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CreativeFatigueTest extends TestCase
{
    use DatabaseTransactions;

    private function days(): array
    {
        $out = [];
        for ($i = 0; $i < 21; $i++) {
            $w = intdiv($i, 7);
            $out[] = ['date' => (new \DateTimeImmutable('2026-10-01'))->modify('+'.$i.' days')->format('Y-m-d'), 'spend' => 100, 'impressions' => 10000, 'clicks' => 100 * (1 - .2 * $w), 'conversions' => 10 * (1 - .2 * $w), 'frequency' => 1 + $w * .3];
        }

return $out;
    }

    public function test_sustained_pattern_is_a_signal_not_cause_or_threshold(): void
    {
        $r = (new CreativeFatigueEngine)->analyze($this->days());
        $this->assertSame('creative_fatigue_pattern', $r['signal']);
        $this->assertNull($r['confidence_level']);
        $this->assertNull($r['saturation_threshold']);
        $this->assertCount(3, $r['windows']);
        $this->assertEqualsWithDelta(1.6, $r['windows'][2]['mean_daily_frequency'], 1e-8);
    }

    public function test_one_week_spike_does_not_make_sustained_fatigue(): void
    {
        $days = $this->days();
        for ($i = 14; $i < 21; $i++) {
            $days[$i]['frequency'] = 1.3;
            $days[$i]['clicks'] = 80;
            $days[$i]['conversions'] = 8;
        }$this->assertSame('no_sustained_pattern', (new CreativeFatigueEngine)->analyze($days)['signal']);
    }

    public function test_missing_frequency_duplicate_days_zero_denominators_abstain(): void
    {
        foreach (['missing', 'duplicate', 'zero', 'gap'] as $type) {
            $d = $this->days();
            if ($type === 'missing') {
                $d[4]['frequency'] = null;
            }if ($type === 'duplicate') {
                $d[4]['date'] = $d[3]['date'];
            }if ($type === 'zero') {
                for ($i = 0; $i < 7; $i++) {
                    $d[$i]['conversions'] = 0;
                }
            }if ($type === 'gap') {
                array_pop($d);
            }$r = (new CreativeFatigueEngine)->analyze($d);
            $this->assertSame('insufficient_data', $r['status']);
            $this->assertNull($r['signal']);
        }
    }

    public function test_api_owns_full_ad_chain_and_does_not_invent_frequency(): void
    {
        $u = User::factory()->create();
        $a = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'meta', 'external_account_id' => uniqid(), 'account_name' => 'Fixture', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'fixture']);
        $c = Campaign::create(['connected_ad_account_id' => $a->id, 'provider' => 'meta', 'external_campaign_id' => uniqid(), 'name' => 'Fixture', 'currency' => 'USD']);
        $set = AdSet::create(['campaign_id' => $c->id, 'external_ad_set_id' => 's', 'name' => 'Fixture']);
        $ad = Ad::create(['ad_set_id' => $set->id, 'external_ad_id' => 'ad', 'name' => 'Fixture Ad']);
        foreach ($this->days() as $i => $day) {
            $day['date'] = now()->subDays(21 - $i)->toDateString();
            PerformanceMetric::create($day + ['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],'connected_ad_account_id' => $a->id, 'campaign_id' => $c->id, 'ad_set_id' => $set->id, 'ad_id' => $ad->id, 'provider' => 'meta', 'currency' => 'USD', 'conversion_value' => 200]);
        }
        // This endpoint also uses completed account-local days, not the server boundary.
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/creative-fatigue?ad_id='.$ad->id)->assertOk()->assertJsonPath('data.signal', 'creative_fatigue_pattern');
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/analytics/creative-fatigue?ad_id='.$ad->id)->assertNotFound();
        PerformanceMetric::where('ad_id', $ad->id)->update(['frequency' => null]);
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/creative-fatigue?ad_id='.$ad->id)->assertJsonPath('data.status', 'insufficient_data')->assertJsonPath('data.signal',null);
    }
    public function test_invalid_account_timezone_abstains_before_frequency_analysis():void{
        $u=User::factory()->create();$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Fixture','currency'=>'USD','timezone'=>'Not/AZone','access_token'=>'fixture']);$c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>uniqid(),'name'=>'Fixture','currency'=>'USD']);$set=AdSet::create(['campaign_id'=>$c->id,'external_ad_set_id'=>'s','name'=>'Fixture']);$ad=Ad::create(['ad_set_id'=>$set->id,'external_ad_id'=>'a','name'=>'Fixture']);$this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/creative-fatigue?ad_id='.$ad->id)->assertOk()->assertJsonPath('data.status','invalid_scope')->assertJsonPath('data.signal',null);
    }

}
