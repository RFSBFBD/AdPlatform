<?php
namespace Tests\Feature;
use App\Models\{ConnectedAdAccount,ProviderDeveloperConfig};
use App\Services\Advertising\Adapters\AmazonAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class AmazonAdapterTest extends TestCase
{
    private function adapter(): AmazonAdsAdapter { return new AmazonAdsAdapter(new ProviderDeveloperConfig(['app_id'=>'id','app_secret'=>'secret','is_enabled'=>true,'extra_settings'=>['region'=>'EU']])); }
    public function test_profile_id_not_account_id_and_region_currency_timezone(): void
    {
        Http::preventStrayRequests();Http::fake(['https://advertising-api-eu.amazon.com/v2/profiles*'=>Http::response([['profileId'=>123,'currencyCode'=>'EGP','timezone'=>'Africa/Cairo','accountInfo'=>['id'=>'DIFFERENT','name'=>'Fixture']]])]);$r=$this->adapter()->discoverAccounts('t');$this->assertSame('123',$r[0]['external_account_id']);$this->assertSame('EGP',$r[0]['currency']);Http::assertSent(fn($r)=>$r->hasHeader('Amazon-Advertising-API-ClientId','id'));
    }
    public function test_pending_has_no_fake_download_or_import(): void
    {
        Http::preventStrayRequests();Http::fake(['*'=>Http::response(['reportId'=>'r','status'=>'PENDING','url'=>null,'urlExpiresAt'=>null])]);$r=$this->adapter()->reportStatus(new ConnectedAdAccount(['external_account_id'=>'123','access_token'=>'t']),'r');$this->assertNull($r['download_url']);$this->assertFalse($r['imported']);$this->assertSame('GZIP_JSON',$r['format']);
    }
    public function test_expired_url_not_assumed_valid_for_fixed_hour(): void
    {
        Http::fake(['*'=>Http::response(['reportId'=>'r','status'=>'COMPLETED','url'=>'https://example.test/fixture.gz','urlExpiresAt'=>now()->subSecond()->toIso8601String()])]);$this->expectException(\RuntimeException::class);$this->expectExceptionMessage('expired');$this->adapter()->reportStatus(new ConnectedAdAccount(['external_account_id'=>'123','access_token'=>'t']),'r');
    }
    public function test_end_boundary_not_guessed_and_no_submit_occurs(): void
    {
        Http::preventStrayRequests();Http::fake();$this->expectException(\RuntimeException::class);$this->expectExceptionMessage('endDate semantics');
        $this->adapter()->submitSponsoredProductsReport(new ConnectedAdAccount(['external_account_id'=>'1','access_token'=>'t','currency'=>'EGP','timezone'=>'Africa/Cairo']),'2026-11-01','2026-11-02');
    }
    public function test_sp_columns_and_one_group_and_explicit_window(): void
    {
        config(['provider_reports.amazon_end_date_semantics'=>'inclusive']);Http::preventStrayRequests();Http::fake(['*'=>Http::response(['reportId'=>'r','status'=>'PENDING'])]);
        $this->adapter()->submitSponsoredProductsReport(new ConnectedAdAccount(['external_account_id'=>'1','access_token'=>'t','currency'=>'EGP','timezone'=>'Africa/Cairo']),'2026-11-01','2026-11-02');
        Http::assertSent(fn($r)=>$r['configuration']['groupBy']===['campaign'] && in_array('sales7d',$r['configuration']['columns']) && !in_array('sales14d',$r['configuration']['columns']) && $r['endDate']==='2026-11-02');
    }
}
