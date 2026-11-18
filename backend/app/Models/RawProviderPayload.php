<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawProviderPayload extends Model
{
    use HasFactory;

    protected $fillable = [
        'connected_ad_account_id',
        'provider',
        'endpoint',
        'reporting_date',
        'payload',
    ];

    protected $casts = [
        'reporting_date' => 'date',
        'payload' => 'array',
    ];

    public function connectedAdAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAdAccount::class);
    }
}
