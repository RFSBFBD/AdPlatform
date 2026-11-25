<?php
namespace Tests\Feature;
use App\Models\{ConnectedAdAccount,User,PerformanceMetric};
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use App\Services\Advertising\{OAuthManager};
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;
final class MetaPurchaseDefinitionTest extends TestCase {
 use DatabaseTransactions;
 private function account():ConnectedAdAccount{return ConnectedAdAccount::create(['user_id'=>User::factory()->create()->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);}
 private function row(array $extra=[]):array{return $extra+['campaign_id'=>'c','date_start'=>'2026-11-01','spend'=>'10','impressions'=>'100','clicks'=>'5'];}
 private function action(mixed $n):array{return ['action_type'=>'offsite_conversion.fb_pixel_purchase','value'=>$n];}
 public function test_one_explicit_action_does_not_sum_grouped_aliases_or_leads():void{Http::preventStrayRequests();Http::fake(['*'=>Http::response(['data'=>[$this->row(['actions'=>[$this->action('2'),['action_type'=>'omni_purchase','value'=>'7'],['action_type'=>'purchase','value'=>'7'],['action_type'=>'lead','value'=>'10']],'action_values'=>[$this->action('40'),['action_type'=>'omni_purchase','value'=>'100']]])]])]);$m=(new MetaAdsAdapter)->fetchInsights($this->account(),'2026-11-01','2026-11-01')['metrics'][0];$this->assertSame(2.0,$m['conversions']);$this->assertSame(40.0,$m['conversion_value']);$this->assertFalse($m['metric_definition']['aliases_combined']);$this->assertSame('meta-pixel-purchase-1',$m['metric_definition']['version']);}
 public function test_missing_is_unknown_but_explicit_zero_is_measured_zero():void{$a=$this->account();foreach([[],['actions'=>[],'action_values'=>[]],['actions'=>[$this->action('0')],'action_values'=>[$this->action('0')]]]as$i=>$fields){Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['data'=>[$this->row($fields)]])]);$m=(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01')['metrics'][0];$this->assertSame($i===2?0.0:null,$m['conversions']);$this->assertSame($i===2?0.0:null,$m['conversion_value']);}}
 public function test_duplicate_or_invalid_selected_action_rejects_instead_of_adding_or_coercing():void{$a=$this->account();foreach([[$this->action('1'),$this->action('2')],[$this->action(-1)],[$this->action(true)],[$this->action('bad')],['invalid']]as$actions){Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['data'=>[$this->row(['actions'=>$actions])]])]);try{(new MetaAdsAdapter)->fetchInsights($a,'2026-11-01','2026-11-01');$this->fail('Malformed purchase accepted');}catch(\RuntimeException){$this->assertTrue(true);}}}
 public function test_shared_sync_retains_definition_and_missing_required_outcomes_do_not_write_metrics():void{$a=$this->account();$adapter=new MetaAdsAdapter;$manager=Mockery::mock(OAuthManager::class);$manager->shouldReceive('getAdapter')->with('meta')->andReturn($adapter);$engine=new AdDataSyncEngine($manager);foreach([true,false]as$complete){Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*campaigns*'=>Http::response(['data'=>[['id'=>'c','name'=>'Synthetic']]]),'*insights*'=>Http::response(['data'=>[$this->row($complete?['actions'=>[$this->action('2')],'action_values'=>[$this->action('40')]]:[])]])]);$run=$engine->syncAccount($a,'manual','2026-11-01','2026-11-01');$this->assertSame($complete?'completed':'failed',$run->status);$m=PerformanceMetric::where('connected_ad_account_id',$a->id)->sole();$this->assertSame('meta-pixel-purchase-1',$m->metadata['metric_definition']['version']);$this->assertSame(2.0,(float)$m->conversions);}}
}
