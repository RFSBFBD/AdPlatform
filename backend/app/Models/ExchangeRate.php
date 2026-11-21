<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'base_currency',
        'quote_currency',
        'exchange_rate',
        'effective_date',
        'source',
        'retrieved_at',
    ];

    protected $casts = [
        'exchange_rate' => 'float',
        'effective_date' => 'date',
        'retrieved_at' => 'datetime',
    ];

    /**
     * Scope to find a rate for a specific date and pair.
     */
    public function scopeRateFor($query, string $base, string $quote, string $date)
    {
        return $query->where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote))
            ->where('effective_date', $date);
    }
}
