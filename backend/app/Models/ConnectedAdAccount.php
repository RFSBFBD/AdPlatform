<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ConnectedAdAccount extends Model
{
    protected $fillable = [
        'user_id',
        'uuid',
        'provider',
        'external_account_id',
        'account_name',
        'currency',
        'timezone',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'metadata',
        'last_synced_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($account) {
            if (empty($account->uuid)) {
                $account->uuid = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function performanceMetrics()
    {
        return $this->hasMany(PerformanceMetric::class);
    }

    public function syncRuns()
    {
        return $this->hasMany(SyncRun::class);
    }

    public function rawPayloads()
    {
        return $this->hasMany(RawProviderPayload::class);
    }

    public function billingProfile()
    {
        return $this->hasOne(AccountBillingProfile::class);
    }

    public function billingInvoices()
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'provider' => $this->provider,
            'external_account_id' => $this->external_account_id,
            'account_name' => $this->account_name,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
