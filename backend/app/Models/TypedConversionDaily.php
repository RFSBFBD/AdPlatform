<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TypedConversionDaily extends Model{
 protected $table='typed_conversion_daily';
 protected $fillable=['conversion_definition_id','entity_level','entity_id','date','count','currency','timezone','source_reference','retrieved_at'];
 protected $casts=['date'=>'date','count'=>'decimal:4','retrieved_at'=>'datetime'];
}
