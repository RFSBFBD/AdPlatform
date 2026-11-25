<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{ConnectedAdAccount,ConversionDefinition,TypedConversionDaily};
use App\Services\Analytics\EntityOwnership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
/** Explicit manual aggregate import. Not provider verification, customer identity or event deduplication. */
final class ConversionSourceController extends Controller
{
 private function account(Request $r,int $id):ConnectedAdAccount{return ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);}
 private function mayWrite(Request $r):void{abort_unless($r->user()->can('manage-accounts')||$r->user()->hasAnyRole(['Owner','Admin']),403);}
 public function index(Request $r,int $id){$a=$this->account($r,$id);return response()->json(['success'=>true,'data'=>ConversionDefinition::where('connected_ad_account_id',$a->id)->orderBy('id')->get(),'note'=>'User-declared conversion semantics. Not provider verified and not unique people.']);}
 public function store(Request $r,int $id){
  $a=$this->account($r,$id);$this->mayWrite($r);$v=$r->validate(['external_key'=>'required|string|max:128|regex:/^[a-zA-Z0-9_.:-]+$/','name'=>'required|string|max:255','kind'=>'required|in:lead,qualified_lead,purchase,install,other','counting_method'=>'required|in:events,fractional_attributed','attribution_model'=>'required|string|max:128','click_window_days'=>'required|integer|min:0|max:365','view_window_days'=>'required|integer|min:0|max:365','source_system'=>'required|string|max:128']);
  if(ConversionDefinition::where('connected_ad_account_id',$id)->where('external_key',$v['external_key'])->exists())return response()->json(['success'=>false,'message'=>'Definition already exists. Definitions are immutable once declared; use a new external key for changed semantics.'],409);
  $d=ConversionDefinition::create($v+['connected_ad_account_id'=>$id]);return response()->json(['success'=>true,'data'=>$d],201);
 }
 public function import(Request $r,int $id,int $definition,EntityOwnership $ownership){
  $a=$this->account($r,$id);$this->mayWrite($r);$d=ConversionDefinition::where('connected_ad_account_id',$id)->findOrFail($definition);
  $v=$r->validate(['entity_level'=>'required|in:campaign,ad_set,ad','rows'=>'required|array|min:1|max:1000','rows.*'=>'required|array:entity_id,date,count,currency,timezone,source_reference,retrieved_at','rows.*.entity_id'=>'required|integer|min:1','rows.*.date'=>'required|date_format:Y-m-d','rows.*.count'=>'required|numeric|decimal:0,4|min:0|max:9999999999','rows.*.currency'=>'required|string|size:3','rows.*.timezone'=>'required|string|max:100','rows.*.source_reference'=>'required|string|max:255|regex:/^[a-zA-Z0-9_.:-]+$/','rows.*.retrieved_at'=>'required|date_format:Y-m-d\TH:i:sP|before_or_equal:now']);
  abort_unless(in_array($a->timezone,\DateTimeZone::listIdentifiers(),true),422,'Known account timezone required.');$today=now()->setTimezone($a->timezone)->toDateString();
  $seen=[];foreach($v['rows']as$row){abort_unless($row['date']<$today,422,'Only completed account-local days can be imported.');$key=$row['entity_id'].'|'.$row['date'];abort_unless(!isset($seen[$key]),422,'Duplicate entity/day in batch.');$seen[$key]=true;abort_unless($ownership->belongs($a,$v['entity_level'],(int)$row['entity_id']),422,'Entity is outside account/provider scope.');abort_unless($row['currency']===$a->currency&&$row['timezone']===$a->timezone&&in_array($a->timezone,\DateTimeZone::listIdentifiers(),true),422,'Exact account currency and verified timezone required.');abort_unless($d->counting_method!=='events'||floor((float)$row['count'])===(float)$row['count'],422,'Event counts must be integral; choose fractional-attributed semantics explicitly.');abort_unless(\Carbon\CarbonImmutable::parse($row['retrieved_at'])->setTimezone($a->timezone)->toDateString()>$row['date'],422,'Retrieval must follow the completed observation day in account timezone.');}
  $written=DB::transaction(function()use($v,$d){$n=0;foreach($v['rows']as$row){$scope=['conversion_definition_id'=>$d->id,'entity_level'=>$v['entity_level'],'entity_id'=>$row['entity_id'],'date'=>$row['date']];$record=TypedConversionDaily::firstOrNew($scope);$record->fill(array_diff_key($row,array_flip(['entity_id','date'])));$record->save();$n++;}return $n;});
  return response()->json(['success'=>true,'data'=>['written'=>$written,'definition_id'=>$d->id,'source'=>'user_imported_aggregate','provider_verified'=>false,'note'=>'Exact scope/day replacement, not addition. No customer identities, deduplication, generic conversion replacement or external campaign changes.']]);
 }
}
