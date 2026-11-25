<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class CanonicalCreative extends Model {
 public $timestamps=false;
 protected $dateFormat='Y-m-d H:i:sP';
 protected $fillable=['connected_ad_account_id','provider','external_creative_id','first_observed_at'];
 protected $casts=['first_observed_at'=>'immutable_datetime'];
}
