<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{ConnectedAdAccount,ConversionDefinition,FirstPartyConversionEvent};
use App\Services\Analytics\EntityOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Manual first-party event evidence only. No people matching or provider mutations. */
final class FirstPartyEventController extends Controller
{
    private const LIMITS = [
        'User-imported immutable event evidence, not provider verified.',
        'Idempotency is only within this account definition and exact event key. No cross-definition/source/customer identity resolution.',
        'These events are separate from daily aggregates, provider conversions and optimization inputs. They are never added to those totals.',
        'Use random opaque source event keys. Do not submit email, phone, customer identifiers, secrets or free customer payload.',
    ];
    private function definition(Request $r, int $id, int $definition): ConversionDefinition
    {
        ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);
        return ConversionDefinition::where('connected_ad_account_id',$id)->findOrFail($definition);
    }
    public function index(Request $r, int $id, int $definition)
    {
        $this->definition($r,$id,$definition);
        $v=$r->validate(['before_id'=>'sometimes|integer|min:1','limit'=>'sometimes|integer|min:1|max:100']);
        $limit=(int)($v['limit']??50);
        $q=FirstPartyConversionEvent::where('conversion_definition_id',$definition);
        if(isset($v['before_id'])) $q->where('id','<',$v['before_id']);
        $rows=$q->orderByDesc('id')->limit($limit+1)->get();$hasMore=$rows->count()>$limit;$rows=$rows->take($limit)->values();
        return response()->json(['success'=>true,'data'=>['events'=>$rows,'next_before_id'=>$hasMore?$rows->last()->id:null,'source'=>'user_imported_event','provider_verified'=>false,'limits'=>self::LIMITS]]);
    }
    public function store(Request $r, int $id, int $definition, EntityOwnership $ownership)
    {
        $d=$this->definition($r,$id,$definition);
        abort_unless($r->user()->can('manage-accounts')||$r->user()->hasAnyRole(['Owner','Admin']),403);
        abort_unless($d->counting_method==='events',422,'Event ledger requires events counting semantics, not fractional attribution.');
        abort_if(array_diff(array_keys($r->all()),['events'])!==[],422,'Unknown top-level fields.');
        $v=$r->validate([
            'events'=>'required|array|min:1|max:500',
            'events.*'=>'required|array:event_key,entity_level,entity_id,occurred_at,value,currency',
            'events.*.event_key'=>'required|string|max:128|regex:/^[a-zA-Z0-9_.:-]+$/',
            'events.*.entity_level'=>'required|in:campaign,ad_set,ad',
            'events.*.entity_id'=>'required|integer|min:1',
            'events.*.occurred_at'=>'required|string|max:25',
            'events.*.value'=>'required|numeric|decimal:0,4|min:0|max:9999999999',
            'events.*.currency'=>'required|string|regex:/^[A-Z]{3}$/',
        ]);
        $account=ConnectedAdAccount::findOrFail($id);$prepared=[];$seen=[];
        foreach($v['events'] as $row) {
            abort_if(isset($seen[$row['event_key']]),422,'Duplicate event key in batch.');$seen[$row['event_key']]=true;
            abort_unless($ownership->belongs($account,$row['entity_level'],(int)$row['entity_id']),422,'Entity outside account/provider scope.');
            abort_unless($row['currency']===$account->currency,422,'Exact account currency required.');
            $s=$row['occurred_at'];
            abort_unless((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',$s),422,'Explicit ISO8601 second-resolution timestamp and timezone required.');
            try {$date=new \DateTimeImmutable($s);$errors=\DateTimeImmutable::getLastErrors();}catch(\Throwable){abort(422,'Invalid event timestamp.');}
            abort_if($errors!==false&&($errors['warning_count']||$errors['error_count']),422,'Invalid event timestamp.');
            // DateTime accepts oversized timezone offsets; ISO8601 offsets here are bounded to 14 hours.
            if(substr($s,-1)!=='Z') { $h=(int)substr($s,-5,2);$m=(int)substr($s,-2);abort_unless($h<=14&&$m<60&&($h<14||$m===0),422,'Invalid timezone offset.'); }
            $at=CarbonImmutable::instance($date)->utc();abort_if($at->lessThan(CarbonImmutable::parse('1970-01-01T00:00:00Z'))||$at->greaterThan(CarbonImmutable::now()),422,'Event timestamp must be between the Unix epoch and now.');
            $normalized=['event_key'=>$row['event_key'],'entity_level'=>$row['entity_level'],'entity_id'=>(int)$row['entity_id'],'occurred_at'=>$at->format('Y-m-d\TH:i:s\Z'),'value'=>number_format((float)$row['value'],4,'.',''),'currency'=>$row['currency']];
            $prepared[]=$normalized+['payload_sha256'=>hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR))];
        }
        $result=DB::transaction(function()use($prepared,$definition){
            // Definition lock serializes competing imports, including not-yet-inserted event keys.
            ConversionDefinition::whereKey($definition)->lockForUpdate()->firstOrFail();
            $existing=FirstPartyConversionEvent::where('conversion_definition_id',$definition)->whereIn('event_key',array_column($prepared,'event_key'))->get()->keyBy('event_key');
            foreach($prepared as $row) { $old=$existing->get($row['event_key']);abort_if($old&&!hash_equals($old->payload_sha256,$row['payload_sha256']),409,'Event key already has different immutable content. Entire batch rejected.'); }
            $inserted=0;$replayed=0;
            foreach($prepared as $row) {
                if($existing->has($row['event_key'])) {$replayed++;continue;}
                FirstPartyConversionEvent::create($row+['conversion_definition_id'=>$definition,'accepted_at'=>CarbonImmutable::now()]);$inserted++;
            }
            return ['inserted'=>$inserted,'replayed'=>$replayed];
        });
        return response()->json(['success'=>true,'data'=>$result+['source'=>'user_imported_event','provider_verified'=>false,'limits'=>self::LIMITS]],$result['inserted']>0?201:200);
    }
}
