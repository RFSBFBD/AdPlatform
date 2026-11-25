<?php
namespace Tests\Feature;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\RedditAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class RedditAdapterTest extends TestCase
{
    protected function tearDown(): void { \Carbon\Carbon::setTestNow(); parent::tearDown(); }
    private function adapter(): RedditAdsAdapter { return new RedditAdsAdapter(new ProviderDeveloperConfig(['app_id'=>'fixture-id','app_secret'=>'fixture-secret','is_enabled'=>true])); }
    private function account(): ConnectedAdAccount { return new ConnectedAdAccount(['external_account_id'=>'t2_123','access_token'=>'fixture','currency'=>'EGP','timezone'=>'Africa/Cairo']); }
    private function row(): array { return ['date'=>'2026-11-01','currency'=>'EGP','spend'=>12000000,'impressions'=>1000,'clicks'=>20,'conversion_purchase_clicks'=>2,'conversion_purchase_views'=>1,'conversion_purchase_total_value'=>5000]; }
    public function test_authorization_and_refresh(): void
    {
        Http::preventStrayRequests();Http::fake(['*/access_token'=>Http::response(['access_token'=>'a','expires_in'=>3600,'refresh_token'=>'rotated'])]);
        $a=$this->adapter();parse_str(parse_url($a->getAuthorizationUrl('state','https://example.test/cb'),PHP_URL_QUERY),$q);$this->assertSame('adsread',$q['scope']);$this->assertSame('permanent',$q['duration']);
        $this->assertSame('rotated',$a->refreshToken('r')['refresh_token']);Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Basic '.base64_encode('fixture-id:fixture-secret')) && $r->hasHeader('User-Agent'));
    }
    public function test_business_and_account_discovery(): void
    {
        Http::preventStrayRequests();Http::fake(['*/me/businesses'=>Http::response(['data'=>[['id'=>'fixture-business']],'pagination'=>[]]),'*/businesses/fixture-business/ad_accounts'=>Http::response(['data'=>[['id'=>'t2_123','name'=>'Fixture','currency'=>'EGP','time_zone_id'=>'Africa/Cairo']],'pagination'=>[]])]);
        $r=$this->adapter()->discoverAccounts('a');$this->assertSame('Africa/Cairo',$r[0]['timezone']);$this->assertSame('EGP',$r[0]['currency']);
    }
    public function test_different_money_factors_and_preserved_post_pagination(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');Http::preventStrayRequests();
        Http::fake(['*/reports'=>Http::response(['data'=>['metrics'=>[$this->row()]],'pagination'=>['next_url'=>'https://ads-api.reddit.com/api/v3/ad_accounts/t2_123/reports?page.token=next']]),'*/reports?page.token=next'=>Http::response(['data'=>['metrics'=>[]],'pagination'=>[]])]);
        $r=$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');$this->assertEquals(12,$r['metrics'][0]['spend']);$this->assertEquals(50,$r['metrics'][0]['conversion_value']);$this->assertEquals(3,$r['metrics'][0]['conversions']);
        Http::assertSentCount(2);Http::assertSent(fn($r)=>str_contains($r->url(),'page.token=next') && $r->method()==='POST' && $r['data']['time_zone_id']==='UTC' && $r['data']['ends_at']==='2026-11-02T00:00:00Z');
    }
    public function test_untrusted_pagination_destination_never_receives_auth(): void
    {
        Http::preventStrayRequests();Http::fake(['*'=>Http::response(['data'=>[],'pagination'=>['next_url'=>'https://example.invalid/leak']])]);
        try { $this->adapter()->discoverAccounts('fixture-secret');$this->fail('must reject'); } catch (\RuntimeException $e) { $this->assertStringContainsString('pagination',$e->getMessage()); }
        Http::assertSentCount(1);
    }
    public function test_missing_metric_not_zero(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');$r=$this->row();$r['conversion_purchase_total_value']=null;Http::fake(['*'=>Http::response(['data'=>['metrics'=>[$r]],'pagination'=>[]])]);$this->expectException(\RuntimeException::class);$this->adapter()->fetchInsights($this->account(),'2026-11-01','2026-11-01');
    }
}
