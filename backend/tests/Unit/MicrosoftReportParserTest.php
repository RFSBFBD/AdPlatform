<?php
namespace Tests\Unit;
use App\Services\Advertising\Reports\MicrosoftReportParser;
use PHPUnit\Framework\TestCase;
class MicrosoftReportParserTest extends TestCase
{
    private function archive(string $csv,string $name='report.csv'): string
    {
        $p=tempnam(sys_get_temp_dir(),'fixture-');$z=new \ZipArchive();$z->open($p,\ZipArchive::OVERWRITE);$z->addFromString($name,$csv);$z->close();$b=file_get_contents($p);unlink($p);return $b;
    }
    public function test_normalizes_documented_columns_with_fractional_attribution(): void
    {
        $b=$this->archive("AccountId,TimePeriod,CurrencyCode,Spend,Impressions,Clicks,Conversions,Revenue\n123,2026-11-01,EUR,12.30,1000,20,2.5,50.20\n");$r=(new MicrosoftReportParser())->parse($b,'123','EUR','2026-11-01','2026-11-01');$this->assertSame(12.3,$r[0]['spend']);$this->assertSame(2.5,$r[0]['conversions']);
    }
    public function test_traversal_member_is_rejected_without_extraction(): void
    {
        $this->expectException(\RuntimeException::class);(new MicrosoftReportParser())->parse($this->archive('x','../report.csv'),'123','EUR','2026-11-01','2026-11-01');
    }
    public function test_unknown_value_is_not_zero(): void
    {
        $this->expectException(\RuntimeException::class);(new MicrosoftReportParser())->parse($this->archive("AccountId,TimePeriod,CurrencyCode,Spend,Impressions,Clicks,Conversions,Revenue\n123,2026-11-01,EUR,12.30,1000,20,--,50\n"),'123','EUR','2026-11-01','2026-11-01');
    }
}
