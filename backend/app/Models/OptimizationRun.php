<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptimizationRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'engine',
        'engine_version',
        'model_name',
        'model_version',
        'status',
        'input_hash',
        'inputs',
        'dataset_fingerprint',
        'result',
        'source_snapshot',
        'snapshot_sha256',
    ];

    protected $casts = [
        'inputs' => 'array',
        'dataset_fingerprint' => 'array',
        'result' => 'array',
        'source_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted():void
    {
        static::creating(function(self $r){$r->archive_integrity_sha256=$r->integrityDigest();});
    }
    public function integrityDigest():string
    {
        return hash('sha256',json_encode(['engine'=>$this->engine,'engine_version'=>$this->engine_version,'model_name'=>$this->model_name,'model_version'=>$this->model_version,'status'=>$this->status,'inputs'=>$this->inputs,'result'=>$this->result,'snapshot_sha256'=>$this->snapshot_sha256],JSON_THROW_ON_ERROR));
    }
    public function integrityStatus():string
    {
        if(!$this->archive_integrity_sha256)return 'legacy_unsealed';
        return hash_equals($this->archive_integrity_sha256,$this->integrityDigest())?'matches':'mismatch';
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
