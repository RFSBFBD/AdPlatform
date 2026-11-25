<?php
// Synthetic E2E seed. Local disposable DB only. Http is faked in-process: no network call is made.
use App\Models\{User,ConnectedAdAccount,Campaign,PerformanceMetric,ProviderDeveloperConfig};
use App\Services\Advertising\OAuthManager;
use App\Services\Advertising\Adapters\GoogleAdsAdapter;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Support\Facades\{Http,DB};
chdir('/tmp/w/adplatform/backend');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with(DB::connection()->getDatabaseName(), '_e2e') || DB::table('users')->count() > 0) { fwrite(STDERR, "refusing: not an empty _e2e database\n"); exit(2); }
(new Database\Seeders\RoleAndPermissionSeeder)->run();
$owner = User::factory()->create(['name' => 'Synthetic Owner', 'email' => 'owner@e2e.invalid']); $owner->assignRole('Owner');
$other = User::factory()->create(['name' => 'Synthetic Other', 'email' => 'other@e2e.invalid']); $other->assignRole('Owner');
// 1. google account with a recorded original snapshot (same in-process fake as OriginalReportingCaptureTest)
$g = ConnectedAdAccount::create(['user_id'=>$owner->id,'provider'=>'google','external_account_id'=>'1234567890','account_name'=>'Synthetic Google','currency'=>'EGP','timezone'=>'Africa/Cairo','access_token'=>'fixture','metadata'=>['metadata_source'=>['version'=>'provider-discovery-explicit-1','currency'=>'EGP','timezone'=>'Africa/Cairo']]]);
$config = new ProviderDeveloperConfig(['provider'=>'google','app_id'=>'fixture','app_secret'=>'fixture','developer_token'=>'fixture','api_version'=>'v26']);
$original = '{"results":[{"campaign":{"id":"111"},"segments":{"date":"2026-11-01"},"metrics":{"costMicros":"1000001","impressions":"100","clicks":"2","conversions":1.000000123456789,"conversionsValue":123.123456789012345}}]}';
Http::preventStrayRequests();
Http::fake(function ($r) use ($original) { $q = $r['query']; if (str_contains($q,'FROM ad_group_ad')) return Http::response(['results'=>[]]); if (str_contains($q,'metrics.cost_micros')) return Http::response($original,200,['Content-Type'=>'application/json','request-id'=>'synthetic-request']); return Http::response(['results'=>[['campaign'=>['id'=>'111','name'=>'Synthetic','status'=>'ENABLED','advertisingChannelType'=>'SEARCH']]]]); });
$m = Mockery::mock(OAuthManager::class); $m->shouldReceive('getAdapter')->andReturn(new GoogleAdsAdapter($config));
$run = (new AdDataSyncEngine($m))->syncAccount($g,'manual','2026-11-01','2026-11-14');
echo "sync:", $run->status, "\n";
// 2. meta accounts with metric series for saturation and comparison
$meta = function (User $u, string $cur, string $name) {
  $a = ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>$name,'currency'=>$cur,'timezone'=>'UTC','access_token'=>'fixture']);
  $c = Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>uniqid(),'name'=>$name.' campaign','currency'=>$cur]);
  $md = ['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']];
  $n = 40;
  for ($i=0;$i<$n;$i++) { $f=1.0+($i*7%$n)/$n*3.0; $imp=10000+($i*37%11)*100; $ctr=($f<2.5?0.02:0.02-0.008*($f-2.5))+((($i*13%7)-3)*0.0001);
    PerformanceMetric::create(['metadata'=>$md,'connected_ad_account_id'=>$a->id,'campaign_id'=>$c->id,'provider'=>'meta','currency'=>$cur,'date'=>now()->subDays($n-$i)->toDateString(),'frequency'=>$f,'impressions'=>$imp,'clicks'=>max(1,(int)round($imp*$ctr)),'spend'=>100+$i,'conversions'=>5+$i%3,'reach'=>5000]); }
  return [$a,$c];
};
[$ma,$mc] = $meta($owner,'USD','Synthetic Meta USD');
[$mb,$mbc] = $meta($owner,'EGP','Synthetic Meta EGP');
[$oa,$oc] = $meta($other,'USD','OTHER USER Meta');
echo json_encode(['owner'=>$owner->id,'other'=>$other->id,'google'=>$g->id,'metaA'=>$ma->id,'metaA_campaign'=>$mc->id,'metaB'=>$mb->id,'otherAccount'=>$oa->id,'otherCampaign'=>$oc->id]), "\n";
