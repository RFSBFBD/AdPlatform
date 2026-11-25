<?php
namespace App\Services\Advertising\Reports;
use RuntimeException;
/** SP campaign/day rows aggregate once to account/day. Local caps are not vendor quotas. */
final class AmazonReportParser
{
    public const MAX_COMPRESSED=20_000_000;
    public const MAX_UNCOMPRESSED=100_000_000;
    public function parse(string $gzip,string $currency,string $start,string $end): array
    {
        if (strlen($gzip)>self::MAX_COMPRESSED) { throw new RuntimeException('Amazon report exceeds local compressed size policy.'); }
        $json=@gzdecode($gzip,self::MAX_UNCOMPRESSED+1);
        if (!is_string($json) || strlen($json)>self::MAX_UNCOMPRESSED) { throw new RuntimeException('Invalid or oversized Amazon GZIP_JSON.'); }
        try { $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); } catch (\JsonException) { throw new RuntimeException('Invalid Amazon report JSON.'); }
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>500000 || !preg_match('/^[A-Z]{3}$/',$currency)) { throw new RuntimeException('Amazon report rows or profile currency invalid.'); }
        $days=[];$seen=[];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['campaignId'],$r['date']) || !preg_match('/^\d+$/',(string)$r['campaignId'])) { throw new RuntimeException('Amazon campaign/date missing.'); }
            $d=$r['date'];$dt=is_string($d)?\DateTimeImmutable::createFromFormat('!Y-m-d',$d):false;
            if (!$dt || $dt->format('Y-m-d')!==$d || $d<$start || $d>$end || isset($seen[$d.':'.$r['campaignId']])) { throw new RuntimeException('Amazon duplicate or invalid campaign/day.'); }
            $seen[$d.':'.$r['campaignId']]=true;
            foreach (['impressions','clicks','cost','purchases7d','sales7d'] as $k) {
                if (!isset($r[$k]) || !is_numeric($r[$k]) || !is_finite((float)$r[$k]) || $r[$k]<0) { throw new RuntimeException('Amazon missing, suppressed or invalid metric.'); }
            }
            foreach (['impressions','clicks','purchases7d'] as $k) { if ((float)$r[$k]!==floor((float)$r[$k]) || $r[$k]>PHP_INT_MAX) { throw new RuntimeException('Amazon count must be a bounded integer.'); } }
            foreach(['impressions','clicks','purchases7d','cost','sales7d']as$operand)\App\Services\Advertising\MetricOperand::read($r,$operand,in_array($operand,['impressions','clicks','purchases7d'],true));
            $days[$d]??=['date'=>$d,'spend'=>0.0,'impressions'=>0,'clicks'=>0,'conversions'=>0.0,'conversion_value'=>0.0,'metric_definition'=>['ad_product'=>'SPONSORED_PRODUCTS','report_type'=>'spCampaigns','click_window_days'=>7,'conversion_metric'=>'purchases7d','value_metric'=>'sales7d','currency'=>$currency,'source_scope'=>'campaign/day aggregated to account/day','date_basis'=>'ad_activity_profile_timezone','recent_values_revisable'=>true]];
            foreach (['spend'=>'cost','impressions'=>'impressions','clicks'=>'clicks','conversions'=>'purchases7d','conversion_value'=>'sales7d'] as $target=>$source) {
                $days[$d][$target]+=$r[$source];if (!is_finite((float)$days[$d][$target]) || in_array($target,['impressions','clicks'],true) && !is_int($days[$d][$target])) { throw new RuntimeException('Amazon aggregation overflow.'); }
            }
        }
        ksort($days);return array_values($days); // No row is not evidence of a measured zero day.
    }
}
