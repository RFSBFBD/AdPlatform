<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class CanonicalAudience extends Model {
 public $timestamps=false;
 protected $dateFormat='Y-m-d H:i:sP';
 protected $fillable=['connected_ad_account_id', 'provider', 'external_audience_id', 'first_observed_at'];
 protected $casts=['first_observed_at'=>'immutable_datetime'];
}
