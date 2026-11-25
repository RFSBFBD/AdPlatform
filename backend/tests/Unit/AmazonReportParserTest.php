<?php
namespace Tests\Unit;
use App\Services\Advertising\Reports\AmazonReportParser;
use PHPUnit\Framework\TestCase;
class AmazonReportParserTest extends TestCase
{
    private function row(string $id='123'): array { return ['campaignId'=>$id,'date'=>'2026-11-01','impressions'=>100,'clicks'=>10,'cost'=>2.5,'purchases7d'=>1,'sales7d'=>8]; }
    public function test_gzip_json_aggregates_campaigns_once_without_summing_windows(): void
    {
        $r=(new AmazonReportParser())->parse(gzencode(json_encode([$this->row(),$this->row('456')])),'EGP','2026-11-01','2026-11-02');
        $this->assertCount(1,$r);$this->assertSame(5.0,$r[0]['spend']);$this->assertEquals(16,$r[0]['conversion_value']);$this->assertSame(200,$r[0]['impressions']);$this->assertSame('sales7d',$r[0]['metric_definition']['value_metric']);
    }
    public function test_duplicate_campaign_day_refused(): void { $this->expectException(\RuntimeException::class);(new AmazonReportParser())->parse(gzencode(json_encode([$this->row(),$this->row()])),'EGP','2026-11-01','2026-11-01'); }
    public function test_missing_sales_not_zero(): void { $r=$this->row();unset($r['sales7d']);$this->expectException(\RuntimeException::class);(new AmazonReportParser())->parse(gzencode(json_encode([$r])),'EGP','2026-11-01','2026-11-01'); }
    public function test_zip_not_gzip_refused(): void { $this->expectException(\RuntimeException::class);(new AmazonReportParser())->parse('PKfixture','EGP','2026-11-01','2026-11-01'); }
    public function test_outside_requested_dates_refused(): void { $this->expectException(\RuntimeException::class);(new AmazonReportParser())->parse(gzencode(json_encode([$this->row()])),'EGP','2026-11-02','2026-11-03'); }
}
