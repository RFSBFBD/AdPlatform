<?php
namespace App\Services\Advertising;
use RuntimeException;
/** Provider-independent lexical and range boundary only. Does not choose metric meaning or omission semantics. */
final class MetricOperand {
 public const VERSION='strict-operands-1';
 public static function read(array $row,string $key,bool $integer=false,float $divisor=1,bool $optional=false):int|float|null{
  $v=$row[$key]??null;if($v===null){if($optional)return null;throw new RuntimeException('Required metric operand missing: '.$key);}
  if((!is_int($v)&&!is_float($v)&&!is_string($v))||is_string($v)&&!preg_match('/^\d+(?:\.\d+)?$/D',$v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||!is_finite($divisor)||$divisor<=0)throw new RuntimeException('Invalid numeric metric operand: '.$key);
  $n=(float)$v;if($integer&&is_string($v)&&str_contains($v,'.')&&trim(explode('.',$v,2)[1],'0')!=='')throw new RuntimeException('Count must be integral in exact supported range: '.$key);if($integer&&(floor($n)!==$n||$n>9007199254740991))throw new RuntimeException('Count must be integral in exact supported range: '.$key);
  $n/=$divisor;if(!$integer&&$n>9999999999)throw new RuntimeException('Metric exceeds supported storage range: '.$key);
  return $integer&&$divisor===1.0?(int)$n:$n;
 }
 public static function validate(array $row):void{
  foreach(['spend','impressions','clicks','conversions','conversion_value']as$k)self::read($row,$k,in_array($k,['impressions','clicks'],true));
  foreach(['reach','frequency']as$k)self::read($row,$k,$k==='reach',1,true);
 }
}
