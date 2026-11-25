<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,ProviderDeveloperConfig,ProviderReportJob};
use App\Services\Advertising\Reports\MicrosoftReportWorkflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{Http,Queue};
use Tests\TestCase;
class MicrosoftReportWorkflowTest extends TestCase
{
    use DatabaseTransactions;
    private function account(): ConnectedAdAccount
    {
        ProviderDeveloperConfig::updateOrCreate(['provider'=>'microsoft'],['app_id'=>'fixture','app_secret'=>'fixture','developer_token'=>'fixture','is_enabled'=>true]);
        return ConnectedAdAccount::create(['user_id'=>User::factory()->create()->id,'provider'=>'microsoft','external_account_id'=>'123','account_name'=>'Fixture','currency'=>'EUR','timezone'=>'Europe/Paris','access_token'=>'token','metadata'=>['parent_customer_id'=>5,'report_timezone_enum'=>'AmsterdamBerlinBernRomeStockholmVienna']]);
    }
    private function job(ConnectedAdAccount $a): ProviderReportJob { return ProviderReportJob::create(['connected_ad_account_id'=>$a->id,'provider'=>'microsoft','external_report_id'=>'job','start_date'=>'2026-11-01','end_date'=>'2026-11-01','state'=>'pending','deadline_at'=>now()->addHours(23),'next_poll_at'=>now()->subSecond()]); }
    public function test_pending_report_has_no_import_and_bounded_next_poll(): void
    {
        $a=$this->account();$j=$this->job($a);Http::preventStrayRequests();Http::fake(['*'=>Http::response(['ReportRequestStatus'=>['Status'=>'Pending']])]);
        (new MicrosoftReportWorkflow())->advance($j);$j->refresh();$this->assertSame('polling',$j->state);$this->assertNull($j->metrics);$this->assertTrue($j->next_poll_at->isFuture());
    }
    public function test_download_host_not_approved_never_receives_request(): void
    {
        $a=$this->account();$j=$this->job($a);Http::preventStrayRequests();Http::fake(['*'=>Http::response(['ReportRequestStatus'=>['Status'=>'Success','ReportDownloadUrl'=>'https://unapproved.invalid/report.zip']])]);
        (new MicrosoftReportWorkflow())->advance($j);$this->assertSame('blocked_download_host',$j->fresh()->state);Http::assertSentCount(1);
    }
    public function test_ready_requires_verified_archive_and_no_token_on_download(): void
    {
        $a=$this->account();$j=$this->job($a);$p=tempnam(sys_get_temp_dir(),'fixture');$z=new \ZipArchive();$z->open($p,\ZipArchive::OVERWRITE);$z->addFromString('report.csv',"AccountId,TimePeriod,CurrencyCode,Spend,Impressions,Clicks,Conversions,Revenue\n123,2026-11-01,EUR,12.30,1000,20,2,50\n");$z->close();$bytes=file_get_contents($p);unlink($p);
        config(['provider_reports.microsoft_download_hosts'=>['reports.fixture.test']]);Http::preventStrayRequests();Http::fake(['*/GenerateReport/Poll'=>Http::response(['ReportRequestStatus'=>['Status'=>'Success','ReportDownloadUrl'=>'https://reports.fixture.test/report.zip?signature=fixture']]),'https://reports.fixture.test/*'=>Http::response($bytes)]);
        (new MicrosoftReportWorkflow())->advance($j);$j->refresh();$this->assertSame('ready',$j->state);$this->assertEquals(12.3,$j->metrics[0]['spend']);Http::assertSent(fn($r)=>str_contains($r->url(),'reports.fixture.test') && !$r->hasHeader('Authorization'));
    }
    public function test_status_is_owner_scoped_and_sync_queue_refused(): void
    {
        Queue::fake();$a=$this->account();$j=$this->job($a);$other=User::factory()->create();$this->actingAs($other,'sanctum')->getJson('/api/v1/ad-accounts/'.$a->id.'/microsoft-reports/'.$j->id)->assertNotFound();
        config(['queue.default'=>'sync']);$this->actingAs($a->user,'sanctum')->postJson('/api/v1/ad-accounts/'.$a->id.'/microsoft-reports',['start_date'=>'2026-11-01','end_date'=>'2026-11-01'])->assertUnprocessable();Queue::assertNothingPushed();
    }

    public function test_ready_report_imports_through_normal_sync_and_job_is_idempotent(): void
    {
        $a=$this->account();$j=$this->job($a);
        $j->update(['state'=>'ready','metrics'=>[['date'=>'2026-11-01','spend'=>12.3,'impressions'=>1000,'clicks'=>20,'conversions'=>2.5,'conversion_value'=>50]]]);
        $worker=new \App\Jobs\PollMicrosoftReport($j->id);
        $worker->handle(app(MicrosoftReportWorkflow::class),app(\App\Services\Advertising\Sync\AdDataSyncEngine::class));
        $this->assertSame('imported',$j->fresh()->state);
        $this->assertDatabaseHas('performance_metrics',['connected_ad_account_id'=>$a->id,'provider'=>'microsoft','spend'=>12.3,'conversion_value'=>50]);
        $worker->handle(app(MicrosoftReportWorkflow::class),app(\App\Services\Advertising\Sync\AdDataSyncEngine::class));
        $this->assertSame(1,\App\Models\PerformanceMetric::where('connected_ad_account_id',$a->id)->count());
    }
}
