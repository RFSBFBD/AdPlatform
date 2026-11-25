<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAdAccount;
use App\Services\Advertising\Sync\CanonicalIntegrityAudit;
use Illuminate\Http\Request;
final class CanonicalIntegrityController extends Controller {
 public function show(Request $r,int $id,CanonicalIntegrityAudit $audit){
  $a=ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);
  $v=$r->validate(['start_date'=>'required|date_format:Y-m-d','end_date'=>'required|date_format:Y-m-d|after_or_equal:start_date']);
  abort_if(\Carbon\CarbonImmutable::parse($v['start_date'])->diffInDays(\Carbon\CarbonImmutable::parse($v['end_date']))>365,422,'Audit window cannot exceed366 date labels.');
  return response()->json(['success'=>true,'data'=>$audit->run($a,$v['start_date'],$v['end_date'])]);
 }
}
