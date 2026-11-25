<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConversionDefinition extends Model{
 protected $fillable=['connected_ad_account_id','external_key','name','kind','counting_method','attribution_model','click_window_days','view_window_days','source_system'];
 protected $casts=['click_window_days'=>'integer','view_window_days'=>'integer'];
}
