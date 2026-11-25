<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'connected_ad_account_id',
        'campaign_id',
        'ad_set_id',
        'ad_id',
        'provider',
        'date',
        'spend',
        'impressions',
        'reach',
        'frequency',
        'clicks',
        'conversions',
        'conversion_value',
        'cpc',
        'cpm',
        'ctr',
        'roas',
        'cpa',
        'currency',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'spend' => 'decimal:4',
        'impressions' => 'integer',
        'reach' => 'integer',
        'frequency' => 'decimal:6',
        'clicks' => 'integer',
        'conversions' => 'decimal:4',
        'conversion_value' => 'decimal:4',
        'cpc' => 'decimal:4',
        'cpm' => 'decimal:4',
        'ctr' => 'decimal:4',
        'roas' => 'decimal:4',
        'cpa' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function connectedAdAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAdAccount::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }
}
