<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class CreativeObservation extends Model {
 public $timestamps=false;
 protected $dateFormat='Y-m-d H:i:sP';
 protected $fillable=['canonical_creative_id','ad_id','sync_run_id','raw_provider_payload_id','name','object_type','descriptor_sha256','observed_at'];
 protected $casts=['observed_at'=>'immutable_datetime'];
}
