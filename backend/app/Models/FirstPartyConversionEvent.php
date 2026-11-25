<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class FirstPartyConversionEvent extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';
 public $timestamps = false;
    protected $fillable = ['conversion_definition_id','event_key','entity_level','entity_id','occurred_at','value','currency','payload_sha256','accepted_at'];
    protected $casts = ['occurred_at'=>'immutable_datetime','accepted_at'=>'immutable_datetime','value'=>'decimal:4'];
}
