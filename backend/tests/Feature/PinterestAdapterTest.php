<?php
namespace Tests\Feature;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\PinterestAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PinterestAdapterTest extends TestCase
{
    protected function tearDown(): void { \Carbon\Carbon::setTestNow(); parent::tearDown(); }
    private function adapter(): PinterestAdsAdapter { return new PinterestAdsAdapter(new ProviderDeveloperConfig(['app_id'=>'fixture-id','app_secret'=>'fixture-secret','is_enabled'=>true])); }
    private function account(string $currency = 'USD'): ConnectedAdAccount { return new ConnectedAdAccount(['external_account_id'=>'123','access_token'=>'fixture','currency'=>$currency,'timezone'=>'America/New_York']); }
    private function row(): array { return ['DATE'=>'2026-11-01','AD_ACCOUNT_ID'=>'123','SPEND_IN_MICRO_DOLLAR'=>12300000,'PAID_IMPRESSION'=>1000,'OUTBOUND_CLICK_1'=>20,'TOTAL_CHECKOUT'=>3,'TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR'=>40000000]; }
    public function test_oauth_basic_auth_exchange_and_state(): void
    {
        Http::preventStrayRequests(); Http::fake(['*/oauth/token'=>Http::response(['access_token'=>'a','refresh_token'=>'r','expires_in'=>100])]);
        $a=$this->adapter(); $url=$a->getAuthorizationUrl('fixture-state','https://example.test/cb');
        parse_str(parse_url($url, PHP_URL_QUERY),$q); $this->assertSame('ads:read',$q['scope']); $this->assertSame('fixture-state',$q['state']);
        $this->assertSame('a',$a->handleOAuthCallback('c','https://example.test/cb')['access_token']);
        Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Basic '.base64_encode('fixture-id:fixture-secret')) && $r['grant_type']==='authorization_code');
        $this->assertSame('r',$a->refreshToken('r')['refresh_token']);
    }
    public function test_account_pagination_preserves_currency_and_timezone(): void
    {
        Http::preventStrayRequests(); Http::fake(['*/ad_accounts*'=>Http::sequence()->push(['items'=>[['id'=>'123','currency'=>'USD','time_zone'=>'America/New_York']], 'bookmark'=>'page2'])->push(['items'=>[['id'=>'456','currency'=>'EUR']], 'bookmark'=>null])]);
        $r=$this->adapter()->discoverAccounts('a'); $this->assertCount(2,$r); $this->assertSame('EUR',$r[1]['currency']); $this->assertNull($r[1]['timezone']); Http::assertSentCount(2);
    }
    public function test_daily_units_attribution_and_outbound_clicks(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23'); Http::preventStrayRequests(); Http::fake(['*/analytics*'=>Http::response([$this->row()])]);
        $r=$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
        $this->assertEquals(12.3,$r['metrics'][0]['spend']); $this->assertEquals(40,$r['metrics'][0]['conversion_value']); $this->assertEquals(3,$r['metrics'][0]['conversions']);
        Http::assertSent(fn($r)=>$r['granularity']==='DAY' && (int)$r['view_window_days']===1 && $r['conversion_report_time']==='TIME_OF_AD_ACTION' && $r['reporting_timezone']==='AD_ACCOUNT_TIME_ZONE');
    }
    public function test_known_advertiser_currency_used_for_microcurrency(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23'); Http::preventStrayRequests(); Http::fake(['*'=>Http::response([$this->row()])]); $r=$this->adapter()->fetchInsights($this->account('EUR'),'2026-11-01','2026-11-01'); $this->assertEquals(12.3,$r['metrics'][0]['spend']);
    }
    public function test_missing_or_suppressed_values_are_not_zero(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23'); $r=$this->row(); unset($r['TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR']); Http::fake(['*'=>Http::response([$r])]);
        $this->expectException(\RuntimeException::class); $this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
    }
    public function test_wrong_account_refuses_import(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23'); $r=$this->row();$r['AD_ACCOUNT_ID']='999';Http::fake(['*'=>Http::response([$r])]);
        $this->expectException(\RuntimeException::class); $this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
    }
    public function test_repeated_bookmark_abstains(): void
    {
        Http::fake(['*'=>Http::response(['items'=>[],'bookmark'=>'loop'])]); $this->expectException(\RuntimeException::class);$this->adapter()->discoverAccounts('a');
    }
}
