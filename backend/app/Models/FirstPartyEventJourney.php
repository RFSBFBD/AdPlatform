<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class FirstPartyEventJourney extends Model {
 protected $dateFormat = 'Y-m-d H:i:sP';
 public $timestamps=false;
 protected $fillable=['first_party_conversion_event_id','touches','payload_sha256','accepted_at'];
 protected $casts=['touches'=>'array','accepted_at'=>'immutable_datetime'];
}
