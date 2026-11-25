<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class AudienceTargetingObservation extends Model {
 public $timestamps=false;
 protected $dateFormat='Y-m-d H:i:sP';
 protected $fillable=['ad_set_id', 'sync_run_id', 'raw_provider_payload_id', 'included_observed', 'excluded_observed', 'references_sha256', 'observed_at'];
 protected $casts=['included_observed'=>'boolean','excluded_observed'=>'boolean','observed_at'=>'immutable_datetime'];
}
