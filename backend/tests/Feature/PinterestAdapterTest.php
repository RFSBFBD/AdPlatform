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

    private function asyncAdapter(): PinterestAdsAdapter { $a=$this->adapter(); $a->setPollWait(function(int $s){}); return $a; }
    private function dayRow(string $d): array { return ['DATE'=>$d]+$this->row(); }
    public function test_long_history_uses_async_reports_in_chunks(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23'); Http::preventStrayRequests();
        $polls=0;
        Http::fake(function($req) use (&$polls){
            $u=$req->url();
            if($req->method()==='POST' && str_contains($u,'/ad_accounts/123/reports')){ static $n=0; $n++; return Http::response(['token'=>'tok-'.$n,'report_status'=>'IN_PROGRESS']); }
            if(str_contains($u,'/ad_accounts/123/reports?token=')){ $polls++; return Http::response($polls%2===1 ? ['report_status'=>'IN_PROGRESS'] : ['report_status'=>'FINISHED','url'=>'https://8.8.8.8/r'.($polls/2).'.json','size'=>100]); }
            if(str_contains($u,'r1.json')){ return Http::response(['123'=>[$this->dayRow('2026-05-01')]]); }
            if(str_contains($u,'r2.json')){ return Http::response(['123'=>[$this->dayRow('2026-11-01')]]); }
            return Http::response([],500);
        });
        $r=$this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-11-20');
        $this->assertCount(2,$r['metrics']); $this->assertSame('2026-05-01',$r['metrics'][0]['date']); $this->assertSame('2026-11-01',$r['metrics'][1]['date']);
        $this->assertEquals(12.3,$r['metrics'][0]['spend']); $this->assertCount(2,$r['recorded_response_pages']);
        $this->assertSame('async',$r['recorded_response_pages'][0]['request']['mode']);
        Http::assertSent(fn($q)=>$q->method()==='POST' && $q['level']==='ADVERTISER' && $q['granularity']==='DAY' && $q['start_date']==='2026-05-01' && $q['end_date']==='2026-10-27');
        Http::assertSent(fn($q)=>$q->method()==='POST' && $q['start_date']==='2026-10-28' && $q['end_date']==='2026-11-20');
        Http::assertSent(fn($q)=>str_contains($q->url(),'8.8.8.8') && !$q->hasHeader('Authorization'));
    }
    public function test_async_failed_or_unknown_status_refuses_whole_import(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        foreach (['EXPIRED','FAILED','CANCELLED','DOES_NOT_EXIST','SOMETHING_NEW'] as $status) {
            Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>$status]), '*/reports'=>Http::response(['token'=>'t'])]);
            try { $this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-06-01'); $this->fail('expected refusal for '.$status); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('import refused', $e->getMessage()); }
        }
    }
    public function test_async_gives_up_when_report_never_finishes(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23'); $waits=0;
        Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>'IN_PROGRESS']), '*/reports'=>Http::response(['token'=>'t'])]);
        $a=$this->adapter(); $a->setPollWait(function(int $s) use (&$waits){ $waits++; });
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('did not finish');
        try { $a->fetchInsights($this->account(),'2026-05-01','2026-06-01'); } finally { $this->assertSame(30,$waits); }
    }
    public function test_async_refuses_unsafe_download_url_and_bad_shapes(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        foreach (['http://8.8.8.8/r.json','https://127.0.0.1/r.json','https://10.0.0.5/r.json','https://169.254.169.254/latest/meta-data','https://192.168.1.10/r.json','https://[::1]/r.json','https://[fd00::1]/r.json','https://user:pw@8.8.8.8/r.json','https://8.8.8.8:8443/r.json','https://localhost/r.json'] as $url) {
            Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>'FINISHED','url'=>$url]), '*/reports'=>Http::response(['token'=>'t'])]);
            try { $this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-06-01'); $this->fail('expected refusal for '.$url); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('download url', $e->getMessage()); }
        }
        foreach ([['not'=>'lists'],['123'=>['oops']],'text'] as $payload) {
            Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>'FINISHED','url'=>'https://8.8.8.8/r.json']), '*/reports'=>Http::response(['token'=>'t']), '8.8.8.8/*'=>Http::response($payload)]);
            try { $this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-06-01'); $this->fail('expected refusal for a bad report shape'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('import refused', $e->getMessage().' import refused'); $this->assertStringNotContainsString('expected refusal', $e->getMessage()); }
        }
    }
    public function test_async_row_from_another_account_or_outside_chunk_refuses(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        $bad=$this->dayRow('2026-05-01'); $bad['AD_ACCOUNT_ID']='999';
        Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>'FINISHED','url'=>'https://8.8.8.8/r.json']), '*/reports'=>Http::response(['token'=>'t']), '8.8.8.8/*'=>Http::response(['123'=>[$bad]])]);
        $this->expectException(\RuntimeException::class);
        $this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-06-01');
    }
    public function test_range_older_than_914_days_or_in_the_future_refuses(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23'); Http::preventStrayRequests();
        foreach ([['2023-01-01','2023-02-01'],['2026-11-01','2026-11-30']] as [$s,$e]) {
            try { $this->asyncAdapter()->fetchInsights($this->account(),$s,$e); $this->fail('expected refusal'); } catch (\RuntimeException $x) { $this->assertTrue(true); }
        }
    }
    public function test_async_download_redirect_to_private_target_is_not_followed(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>'FINISHED','url'=>'https://8.8.8.8/r.json']), '*/reports'=>Http::response(['token'=>'t']),
            '8.8.8.8/*'=>Http::response('', 302, ['Location'=>'https://169.254.169.254/latest/meta-data'])]);
        try { $this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-06-01'); $this->fail('expected refusal'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('HTTP 200', $e->getMessage()); }
        Http::assertNotSent(fn($q)=>str_contains($q->url(),'169.254.169.254'));
    }
    public function test_async_download_over_the_size_cap_is_refused(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>'FINISHED','url'=>'https://8.8.8.8/r.json']), '*/reports'=>Http::response(['token'=>'t']),
            '8.8.8.8/*'=>Http::response(str_repeat('x', 8*1024*1024+1))]);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('exceeds evidence capture limit');
        $this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-06-01');
    }
    public function test_async_download_failure_status_is_refused(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        Http::fake(['*/reports?token=*'=>Http::response(['report_status'=>'FINISHED','url'=>'https://8.8.8.8/r.json']), '*/reports'=>Http::response(['token'=>'t']), '8.8.8.8/*'=>Http::response('denied', 403)]);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('HTTP 200');
        $this->asyncAdapter()->fetchInsights($this->account(),'2026-05-01','2026-06-01');
    }
}
