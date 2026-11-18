<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'connected_ad_account_id',
        'external_invoice_id',
        'invoice_date',
        'amount',
        'tax_amount',
        'total_amount',
        'currency',
        'status',
        'download_url',
        'metadata',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function connectedAdAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAdAccount::class);
    }
}
