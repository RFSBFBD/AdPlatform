<?php
namespace Tests\Feature;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\SpotifyAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class SpotifyAdapterTest extends TestCase
{
    protected function tearDown(): void { \Carbon\Carbon::setTestNow();parent::tearDown(); }
    private function adapter(): SpotifyAdsAdapter { return new SpotifyAdsAdapter(new ProviderDeveloperConfig(['app_id'=>'fixture-id','app_secret'=>'fixture-secret','is_enabled'=>true])); }
    private function account(): ConnectedAdAccount { return new ConnectedAdAccount(['external_account_id'=>'fixture-account','access_token'=>'fixture','currency'=>'USD','timezone'=>'UTC']); }
    private function row(): array { $stats=[];foreach (['IMPRESSIONS'=>1000,'CLICKS'=>20,'SPEND'=>17.482913,'PURCHASES'=>6,'REVENUE'=>90] as $k=>$v) { $stats[]=['field_type'=>$k,'field_value'=>$v]; }return ['entity_type'=>'AD_ACCOUNT','entity_id'=>'fixture-account','start_time'=>'2026-11-01T00:00:00Z','end_time'=>'2026-11-02T00:00:00Z','stats'=>$stats]; }
    public function test_auth_refresh_and_no_invented_scope(): void
    {
        Http::preventStrayRequests();Http::fake(['*/api/token'=>Http::response(['access_token'=>'a','refresh_token'=>'r','expires_in'=>3600])]);$a=$this->adapter();parse_str(parse_url($a->getAuthorizationUrl('state','https://example.test/cb'),PHP_URL_QUERY),$q);$this->assertArrayNotHasKey('scope',$q);$this->assertSame('r',$a->refreshToken('old')['refresh_token']);Http::assertSent(fn($r)=>$r['refresh_token']==='old');
    }
    public function test_exact_discovery_wrappers(): void
    {
        Http::preventStrayRequests();Http::fake(['*/businesses'=>Http::response(['businesses'=>[['id'=>'b']]]),'*/businesses/b/ad_accounts'=>Http::response(['ad_accounts'=>[['id'=>'fixture-account','name'=>'Fixture','currency_code'=>'USD']]])]);$r=$this->adapter()->discoverAccounts('a');$this->assertSame('USD',$r[0]['currency']);$this->assertSame('UTC',$r[0]['timezone']);
    }
    public function test_billing_currency_units_inclusive_day_and_token_only_paging(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');Http::preventStrayRequests();Http::fake(['*'=>Http::sequence()->push(['rows'=>[$this->row()],'continuation_token'=>'next'])->push(['rows'=>[],'continuation_token'=>''])]);$r=$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');$this->assertSame(17.482913,$r['metrics'][0]['spend']);$this->assertSame(90.0,$r['metrics'][0]['conversion_value']);
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'?continuation_token=next'));Http::assertSent(fn($r)=>substr_count($r->url(),'fields=')===5 && str_contains($r->url(),'report_end=2026-11-01T00%3A00%3A00Z'));
    }
    public function test_privacy_sentinel_is_not_imported_as_zero(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');$r=$this->row();$r['stats'][3]['field_value']=-5;Http::fake(['*'=>Http::response(['rows'=>[$r]])]);$this->expectException(\RuntimeException::class);$this->expectExceptionMessage('suppressed');$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
    }
    public function test_warnings_refuse_silent_partial_report(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');Http::fake(['*'=>Http::response(['rows'=>[],'warnings'=>['fixture-warning']])]);$this->expectException(\RuntimeException::class);$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
    }
}
