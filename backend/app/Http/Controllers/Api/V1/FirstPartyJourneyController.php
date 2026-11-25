<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{ConnectedAdAccount,ConversionDefinition,FirstPartyConversionEvent,FirstPartyEventJourney};
use App\Services\Analytics\AttributionEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Explicit source-declared event/click linkage. Never infers a person's identity. */
final class FirstPartyJourneyController extends Controller
{
 private function scope(Request $r,int $id,int $definition):ConversionDefinition {
  ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);
  return ConversionDefinition::where('connected_ad_account_id',$id)->findOrFail($definition);
 }
 private function event(int $definition,int $event):FirstPartyConversionEvent {return FirstPartyConversionEvent::where('conversion_definition_id',$definition)->findOrFail($event);}
 private function journey(FirstPartyConversionEvent $e,array $touches):array {return ['conversion_id'=>(string)$e->id,'value'=>$e->value,'currency'=>$e->currency,'converted_at'=>$e->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),'touches'=>$touches];}
 private function limits():array {return [
  'Stored user-declared click linkage, not independently verified identity, provider attribution or cross-source deduplication.',
  'Click timestamps must fall in the definition click-window duration before conversion. No silent truncation; source declares paid click channels. Views and organic/direct semantics are not verified.',
  'Declared definition attribution model is retained as metadata. Selected rule models are separate analytical scenarios, not source truth or causal lift.',
  'No daily aggregate/provider metrics or optimization inputs are updated. No external campaign or account changes.',
 ];}
 public function store(Request $r,int $id,int $definition,int $event,AttributionEngine $engine) {
  $d=$this->scope($r,$id,$definition);$e=$this->event($definition,$event);
  abort_unless($r->user()->can('manage-accounts')||$r->user()->hasAnyRole(['Owner','Admin']),403);
  abort_if(array_diff(array_keys($r->all()),['touches'])!==[],422,'Unknown top-level fields.');
  $v=$r->validate(['touches'=>'required|array|min:1|max:100','touches.*'=>'required|array:event_id,channel,at','touches.*.event_id'=>'required|string|max:128|regex:/^[a-zA-Z0-9_.:-]+$/','touches.*.channel'=>'required|string|regex:/^[a-z0-9_-]{1,64}$/','touches.*.at'=>'required|string|max:25']);
  $touches=$v['touches'];
  // The shared engine checks validity, uniqueness and strict position ordering before canonicalization.
  try {$engine->compare([$this->journey($e,$touches)]);}catch(\InvalidArgumentException $ex){abort(422,$ex->getMessage());}
  foreach($touches as &$t) {
   if(substr($t['at'],-1)!=='Z') {$h=(int)substr($t['at'],-5,2);$m=(int)substr($t['at'],-2);abort_unless($h<=14&&$m<60&&($h<14||$m===0),422,'Invalid timezone offset.');}
   $at=CarbonImmutable::parse($t['at'])->utc();
   abort_if($at->lessThan(CarbonImmutable::parse('1970-01-01T00:00:00Z'))||$at->lessThan($e->occurred_at->subSeconds($d->click_window_days*86400)),422,'Click is outside the declared fixed-duration click window.');
   $t['at']=$at->format('Y-m-d\TH:i:s\Z');
   // Fixed key order makes exact content hashing independent of JSON object order.
   $t=['event_id'=>$t['event_id'],'channel'=>$t['channel'],'at'=>$t['at']];
  }unset($t);
  $hash=hash('sha256',json_encode($touches,JSON_THROW_ON_ERROR));
  $replayed=DB::transaction(function()use($event,$touches,$hash){
   FirstPartyConversionEvent::whereKey($event)->lockForUpdate()->firstOrFail();
   $old=FirstPartyEventJourney::where('first_party_conversion_event_id',$event)->first();
   if($old){abort_unless(hash_equals($old->payload_sha256,$hash),409,'A different immutable journey already exists for this event.');return true;}
   FirstPartyEventJourney::create(['first_party_conversion_event_id'=>$event,'touches'=>$touches,'payload_sha256'=>$hash,'accepted_at'=>CarbonImmutable::now()]);return false;
  });
  return response()->json(['success'=>true,'data'=>['event_id'=>$event,'replayed'=>$replayed,'source'=>'user_imported_event_journey','provider_verified'=>false,'limits'=>$this->limits()]],$replayed?200:201);
 }
 public function attribute(Request $r,int $id,int $definition,AttributionEngine $engine) {
  $d=$this->scope($r,$id,$definition);
  abort_if(array_diff(array_keys($r->all()),['event_ids','model','compare_models','half_life_days'])!==[],422,'Unknown fields.');
  $v=$r->validate(['event_ids'=>'required|array|min:1|max:500','event_ids.*'=>'required|integer|min:1|distinct','model'=>'required|in:first_click,last_click,linear,time_decay,position_based,data_driven','compare_models'=>'sometimes|boolean','half_life_days'=>'sometimes|numeric|gt:0|max:3650']);
  return DB::transaction(function()use($v,$definition,$d,$engine){
   $events=FirstPartyConversionEvent::where('conversion_definition_id',$definition)->whereIn('id',$v['event_ids'])->orderBy('id')->get();
   abort_unless($events->count()===count($v['event_ids']),404,'One or more events are outside this definition.');
   $stored=FirstPartyEventJourney::whereIn('first_party_conversion_event_id',$v['event_ids'])->get()->keyBy('first_party_conversion_event_id');
   if($stored->count()!==$events->count())return response()->json(['success'=>true,'data'=>['status'=>'incomplete_journey_evidence','reason'=>'Every selected event needs explicit stored click evidence. No events silently excluded.','allocations'=>[],'limits'=>$this->limits()]]);
   $journeys=[];$provenance=[];
   foreach($events as $e){
    $j=$stored->get($e->id);
    $canonical=['event_key'=>$e->event_key,'entity_level'=>$e->entity_level,'entity_id'=>(int)$e->entity_id,'occurred_at'=>$e->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),'value'=>$e->value,'currency'=>$e->currency];
    abort_unless(hash_equals($e->payload_sha256,hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR)))&&hash_equals($j->payload_sha256,hash('sha256',json_encode($j->touches,JSON_THROW_ON_ERROR))),409,'Stored event or journey integrity mismatch.');
    $normalized=$this->journey($e,$j->touches);$journeys[]=$normalized;$provenance[]=['event_id'=>$e->id,'event_key'=>$e->event_key,'event_payload_sha256'=>$e->payload_sha256,'journey_payload_sha256'=>$j->payload_sha256,'event_accepted_at'=>$e->accepted_at,'journey_accepted_at'=>$j->accepted_at];
   }
   try {$options=['half_life_days'=>$v['half_life_days']??7];$result=($v['compare_models']??false)?$engine->compare($journeys,$options):$engine->attribute($journeys,$v['model'],$options);}catch(\InvalidArgumentException $ex){abort(422,$ex->getMessage());}
   $result['source']='user_imported_event_journey';$result['provider_verified']=false;$result['definition']=$d->only(['id','kind','counting_method','attribution_model','click_window_days','view_window_days','source_system']);$result['source_evidence']=$provenance;
   $limits=array_values(array_filter($result['limits']??[],fn($text)=>!str_contains($text,'No lookback filter or window is silently imposed.')));
   if($v['compare_models']??false)$limits[]='All five rules use the same stored events, declared fixed-duration click window and time-decay half-life. Credit range is rule disagreement, not confidence.';
   $result['input_sha256']=hash('sha256',json_encode($journeys,JSON_THROW_ON_ERROR));$result['limits']=array_merge($limits,$this->limits());
   return response()->json(['success'=>true,'data'=>$result]);
  });
 }
}
