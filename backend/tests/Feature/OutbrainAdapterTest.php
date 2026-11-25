<?php
namespace Tests\Feature;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\OutbrainAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class OutbrainAdapterTest extends TestCase
{
    private function adapter(): OutbrainAdsAdapter { return new OutbrainAdsAdapter(new ProviderDeveloperConfig()); }
    public function test_currency_from_consistent_budgets_not_sample_usd_and_timezone_unresolved(): void
    {
        Http::preventStrayRequests();Http::fake(['*/marketers'=>Http::response(['marketers'=>[['id'=>'m','name'=>'Fixture']],'count'=>1]),'*/marketers/m/budgets'=>Http::response(['budgets'=>[['currency'=>'EUR'],['currency'=>'EUR']],'count'=>2])]);$r=$this->adapter()->discoverAccounts('token');$this->assertSame('EUR',$r[0]['currency']);$this->assertNull($r[0]['timezone']);$this->assertTrue($r[0]['unresolved_offset']);Http::assertSent(fn($r)=>$r->hasHeader('OB-TOKEN-V1','token') && !$r->hasHeader('Authorization'));
    }
    public function test_periodic_mapper_keeps_conversion_date_and_native_timezone(): void
    {
        Http::preventStrayRequests();Http::fake(['*'=>Http::response(['results'=>[['metadata'=>['fromDate'=>'2026-11-01','toDate'=>'2026-11-01'],'metrics'=>['spend'=>12.3,'impressions'=>1000,'clicks'=>20,'totalConversions'=>4,'totalSumValue'=>50]]],'totalResults'=>1])]);$a=new ConnectedAdAccount(['external_account_id'=>'m','currency'=>'EUR','access_token'=>'t']);$r=$this->adapter()->periodicReport($a,'2026-11-01','2026-11-01');$this->assertSame(12.3,$r['rows'][0]['spend']);$this->assertSame('conversion_date',$r['rows'][0]['attribution_date']);$this->assertFalse($r['revenue_optimizer_eligible']);
    }
    public function test_unverified_et_prevents_unified_or_import(): void
    {
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('ET daily');$this->adapter()->fetchInsights(new ConnectedAdAccount(),'2026-11-01','2026-11-01');
    }
}
