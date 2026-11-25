<?php
namespace Tests\Feature;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\AppleAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class AppleAdapterTest extends TestCase
{
    protected function tearDown(): void { \Carbon\Carbon::setTestNow();parent::tearDown(); }
    private function adapter(): AppleAdsAdapter { return new AppleAdsAdapter(new ProviderDeveloperConfig(['app_id'=>'fixture-client','app_secret'=>'fixture-presigned-jwt','is_enabled'=>true])); }
    private function account(): ConnectedAdAccount { return new ConnectedAdAccount(['external_account_id'=>'123','access_token'=>'fixture','currency'=>'USD','timezone'=>'America/New_York']); }
    public function test_client_grant_not_browser_oauth(): void
    {
        Http::preventStrayRequests();Http::fake(['*/auth/oauth2/token'=>Http::response(['access_token'=>'a','expires_in'=>3600])]);$this->assertNull($this->adapter()->issueToken()['refresh_token']);Http::assertSent(fn($r)=>$r['grant_type']==='client_credentials' && $r['scope']==='searchadsorg');$this->expectException(\RuntimeException::class);$this->adapter()->getAuthorizationUrl('s','https://example.test/cb');
    }
    public function test_acl_discovery_uses_new_account_context(): void
    {
        Http::preventStrayRequests();Http::fake(['*/acls'=>Http::response(['result'=>['acls'=>[['adAccount'=>['id'=>123,'name'=>'Fixture']]]]]),'*/ad-accounts/123'=>Http::response(['result'=>['currency'=>'USD','timezone'=>'America/New_York']])]);$r=$this->adapter()->discoverAccounts('a');$this->assertSame('USD',$r[0]['currency']);Http::assertSent(fn($r)=>str_ends_with($r->url(),'/ad-accounts/123') && $r->hasHeader('X-AP-Context','adAccountId=123'));
    }
    public function test_single_day_omits_granularity_and_preserves_installs_not_revenue(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');Http::preventStrayRequests();Http::fake(['*/reports/apps/campaigns/query'=>Http::response(['result'=>['rows'=>[['metadata'=>['id'=>1,'adAccountId'=>123],'totalMetrics'=>['localSpend'=>['amount'=>'12.34','currency'=>'USD'],'impressions'=>1000,'taps'=>20,'totalInstalls'=>5]]]],'pagination'=>['offset'=>0,'pageSize'=>100,'totalCount'=>1]])]);$r=$this->adapter()->deliveryReport($this->account(),'2026-11-01','2026-11-01');$this->assertNull($r['rows'][0]['revenue']);$this->assertSame(5.0,$r['rows'][0]['installs']);$this->assertFalse($r['revenue_optimizer_eligible']);Http::assertSent(fn($r)=>!isset($r['timeRange']['granularity']) && $r['timeRange']['timeZone']==='UTC');
    }
    public function test_revenue_sync_abstains_instead_of_inventing_zero(): void
    {
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('not purchase revenue');$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
    }
}
