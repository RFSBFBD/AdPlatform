<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class AudienceTargetingReference extends Model {
 public $timestamps=false;
 protected $dateFormat='Y-m-d H:i:sP';
 protected $fillable=['audience_targeting_observation_id', 'canonical_audience_id', 'role', 'name'];
 protected $casts=[];
}
