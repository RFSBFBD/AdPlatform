<?php
namespace Tests\Feature;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\LinkedInAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class LinkedInAdapterTest extends TestCase
{
    protected function tearDown(): void { \Carbon\Carbon::setTestNow();parent::tearDown(); }
    private function adapter(bool $verified=false): LinkedInAdsAdapter { return new LinkedInAdsAdapter(new ProviderDeveloperConfig(['app_id'=>'fixture-id','app_secret'=>'fixture-secret','is_enabled'=>true,'api_version'=>'202611','extra_settings'=>['conversion_values_verified_as_revenue'=>$verified]])); }
    private function account(): ConnectedAdAccount { return new ConnectedAdAccount(['external_account_id'=>'123','access_token'=>'fixture','currency'=>'EUR','timezone'=>'UTC']); }
    public function test_missing_programmatic_refresh_is_not_fabricated(): void
    {
        Http::preventStrayRequests();Http::fake(['*/accessToken'=>Http::response(['access_token'=>'a','expires_in'=>3600])]);$a=$this->adapter();$this->assertNull($a->handleOAuthCallback('c','https://example.test/cb')['refresh_token']);$this->expectException(\RuntimeException::class);$a->refreshToken('');
    }
    public function test_versioned_cursor_discovery(): void
    {
        Http::preventStrayRequests();Http::fake(['*'=>Http::sequence()->push(['elements'=>[['id'=>123,'currency'=>'EUR','name'=>'Fixture']],'metadata'=>['nextPageToken'=>'next']])->push(['elements'=>[]])]);$r=$this->adapter()->discoverAccounts('a');$this->assertSame('123',$r[0]['external_account_id']);$this->assertSame('UTC',$r[0]['timezone']);Http::assertSent(fn($r)=>$r->hasHeader('Linkedin-Version','202611') && ($r['pageToken']??null)==='next');
    }
    public function test_conversion_values_not_automatically_called_sales(): void
    {
        Http::preventStrayRequests();$this->expectException(\RuntimeException::class);$this->expectExceptionMessage('advertiser-defined');$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
    }
    public function test_explicitly_verified_mapping_uses_regular_account_pivot_and_local_currency(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');$date=['year'=>2026,'month'=>11,'day'=>1];Http::preventStrayRequests();Http::fake(['*'=>Http::response(['elements'=>[['dateRange'=>['start'=>$date,'end'=>$date],'pivotValues'=>['urn:li:sponsoredAccount:123'],'impressions'=>1000,'clicks'=>20,'costInLocalCurrency'=>'12.30','externalWebsiteConversions'=>3.5,'conversionValueInLocalCurrency'=>'50.25']]])]);
        $r=$this->adapter(true)->fetchInsights($this->account(),'2026-11-01','2026-11-01');$this->assertSame(50.25,$r['metrics'][0]['conversion_value']);$this->assertSame(3.5,$r['metrics'][0]['conversions']);Http::assertSent(fn($r)=>$r['q']==='analytics' && $r['pivot']==='ACCOUNT' && str_contains($r['fields'],'conversionValueInLocalCurrency'));
    }
}
