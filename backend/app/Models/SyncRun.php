<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'connected_ad_account_id',
        'sync_type',
        'status',
        'started_at',
        'completed_at',
        'records_processed',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'records_processed' => 'integer',
        'metadata' => 'array',
    ];

    public function connectedAdAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAdAccount::class);
    }
}
