<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'api_key',
        'model',
        'is_active',
        'monthly_token_limit',
        'current_month_tokens',
        'metadata',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'monthly_token_limit' => 'integer',
        'current_month_tokens' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toSafeArray(): array
    {
        $maskedKey = null;
        if (!empty($this->api_key)) {
            $len = strlen($this->api_key);
            $suffix = substr($this->api_key, -4);
            $maskedKey = '••••••••' . $suffix;
        }

        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'is_active' => $this->is_active,
            'is_configured' => !empty($this->api_key),
            'api_key_masked' => $maskedKey,
            'monthly_token_limit' => $this->monthly_token_limit,
            'current_month_tokens' => $this->current_month_tokens,
            'base_url' => $this->metadata['base_url'] ?? null,
            'metadata' => $this->metadata,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
