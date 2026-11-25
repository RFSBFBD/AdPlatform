<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProviderReportJob extends Model
{
    protected $guarded=[];
    protected $casts=['start_date'=>'date','end_date'=>'date','deadline_at'=>'datetime','next_poll_at'=>'datetime','metrics'=>'array'];
    public function account() { return $this->belongsTo(ConnectedAdAccount::class,'connected_ad_account_id'); }
}
