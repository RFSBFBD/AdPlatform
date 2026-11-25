<?php
namespace Tests\Feature;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\MicrosoftAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class MicrosoftAdapterTest extends TestCase
{
    private function adapter(): MicrosoftAdsAdapter { return new MicrosoftAdsAdapter(new ProviderDeveloperConfig(['app_id'=>'id','app_secret'=>'secret','developer_token'=>'dev-token','is_enabled'=>true])); }
    public function test_rest_discovery_uses_bearer_not_soap_authenticationtoken(): void
    {
        Http::preventStrayRequests();Http::fake(['*/User/Query'=>Http::response(['User'=>['Id'=>7]]),'*/Accounts/Search'=>Http::response(['Accounts'=>[['Id'=>123,'Name'=>'Fixture','CurrencyCode'=>'EGP','TimeZone'=>'Cairo','ParentCustomerId'=>5]]])]);$r=$this->adapter()->discoverAccounts('access');$this->assertSame('EGP',$r[0]['currency']);$this->assertSame(5,$r[0]['parent_customer_id']);Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Bearer access') && $r->hasHeader('DeveloperToken','dev-token') && !$r->hasHeader('AuthenticationToken'));
    }
    public function test_async_submit_poll_not_false_sync_success(): void
    {
        Http::preventStrayRequests();Http::fake(['*/GenerateReport/Submit'=>Http::response(['ReportRequestId'=>'fixture-job']),'*/GenerateReport/Poll'=>Http::response(['ReportRequestStatus'=>['Status'=>'Pending']])]);$a=new ConnectedAdAccount(['external_account_id'=>'123','access_token'=>'access','timezone'=>'Cairo','metadata'=>['parent_customer_id'=>5]]);$job=$this->adapter()->submitReport($a,'2026-11-01','2026-11-02');$this->assertSame('submitted_not_imported',$job['state']);$this->assertFalse($this->adapter()->pollReport($a,$job['report_request_id'])['imported']);Http::assertSent(fn($r)=>str_ends_with($r->url(),'/Submit') && $r->hasHeader('CustomerId','5') && $r['ReportRequest']['Type']==='AccountPerformanceReportRequest' && $r['ReportRequest']['Time']['ReportTimeZone']==='Cairo');
    }
    public function test_canonical_sync_abstains_until_archive_import_exists(): void
    {
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('asynchronous');$this->adapter()->fetchInsights(new ConnectedAdAccount(),'2026-11-01','2026-11-02');
    }
}
