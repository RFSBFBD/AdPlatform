<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountBillingProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'connected_ad_account_id',
        'outstanding_balance',
        'currency',
        'payment_method_type',
        'payment_method_masked',
        'status',
        'metadata',
    ];

    protected $casts = [
        'outstanding_balance' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function connectedAdAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAdAccount::class);
    }
}
