<?php
namespace App\Services\Advertising\Reports;
use RuntimeException;
use ZipArchive;

/** Local application safety limits, not Microsoft quotas. Never extracts archive paths. */
final class MicrosoftReportParser
{
    public const MAX_COMPRESSED=20_000_000;
    public const MAX_UNCOMPRESSED=100_000_000;
    public function parse(string $zipBytes,string $accountId,string $currency,string $start,string $end): array
    {
        if (strlen($zipBytes)>self::MAX_COMPRESSED) { throw new RuntimeException('Report exceeds local compressed size policy.'); }
        $path=tempnam(sys_get_temp_dir(),'ad-report-');
        if ($path===false) { throw new RuntimeException('Cannot create temporary report file.'); }
        try {
            file_put_contents($path,$zipBytes);$z=new ZipArchive();
            if ($z->open($path)!==true) { throw new RuntimeException('Invalid Microsoft report ZIP.'); }
            try {
                if ($z->numFiles!==1) { throw new RuntimeException('Expected one CSV archive member.'); }
                $st=$z->statIndex(0);
                if (!$st || !str_ends_with(strtolower($st['name']),'.csv') || str_contains($st['name'],'/') || str_contains($st['name'],'\\') || $st['size']>self::MAX_UNCOMPRESSED || $st['size']>max(1,$st['comp_size'])*1000 || ($st['encryption_method']??0)!==0) { throw new RuntimeException('Unsafe report archive under local extraction policy.'); }
                $csv=$z->getFromIndex(0,(int)$st['size']);
                if (!is_string($csv) || strlen($csv)>self::MAX_UNCOMPRESSED) { throw new RuntimeException('Cannot read bounded report CSV.'); }
            } finally { $z->close(); }
        } finally { @unlink($path); }
        $f=fopen('php://temp','r+');fwrite($f,$csv);rewind($f);$header=fgetcsv($f,0,',','"','');
        if (!is_array($header)) { fclose($f);throw new RuntimeException('Report CSV header missing.'); }
        $header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
        $required=['AccountId','TimePeriod','CurrencyCode','Spend','Impressions','Clicks','Conversions','Revenue'];
        if (count(array_unique($header))!==count($header) || array_diff($required,$header)) { fclose($f);throw new RuntimeException('Report CSV required columns missing or duplicate.'); }
        $rows=[];$seen=[];
        try {
            while (($v=fgetcsv($f,0,',','"',''))!==false) {
                if ($v===[null]) { continue; }
                if (count($v)!==count($header)) { throw new RuntimeException('Report CSV row width mismatch.'); }$r=array_combine($header,$v);
                if ($r['AccountId']!==$accountId || $r['CurrencyCode']!==$currency) { throw new RuntimeException('Report account or currency mismatch.'); }
                $date=$r['TimePeriod'];$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
                if (!$parsed || $parsed->format('Y-m-d')!==$date || $date<$start || $date>$end || isset($seen[$date])) { throw new RuntimeException('Report invalid or duplicate day.'); }$seen[$date]=true;
                foreach (['Spend','Impressions','Clicks','Conversions','Revenue'] as $k) { if (!is_numeric($r[$k]) || !is_finite((float)$r[$k]) || $r[$k]<0) { throw new RuntimeException('Report missing, suppressed or invalid metric.'); } }
                foreach (['Impressions','Clicks'] as $k) { if ((float)$r[$k]!==floor((float)$r[$k])) { throw new RuntimeException('Report delivery count must be an integer.'); } }
                $rows[]=['external_campaign_id'=>null,'external_ad_set_id'=>null,'external_ad_id'=>null,'date'=>$date,'spend'=>\App\Services\Advertising\MetricOperand::read($r,'Spend'),'impressions'=>\App\Services\Advertising\MetricOperand::read($r,'Impressions',true),'clicks'=>\App\Services\Advertising\MetricOperand::read($r,'Clicks',true),'conversions'=>\App\Services\Advertising\MetricOperand::read($r,'Conversions'),'conversion_value'=>\App\Services\Advertising\MetricOperand::read($r,'Revenue')];
                if (count($rows)>1000000) { throw new RuntimeException('Report row count exceeds local policy.'); }
            }
        } finally { fclose($f); }
        return $rows;
    }
}
