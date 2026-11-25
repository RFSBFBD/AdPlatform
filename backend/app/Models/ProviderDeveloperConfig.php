<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderDeveloperConfig extends Model
{
    protected $fillable = [
        'provider',
        'app_id',
        'app_secret',
        'developer_token',
        'redirect_uri',
        'api_version',
        'is_enabled',
        'extra_settings',
        'last_tested_at',
        'test_status',
        'test_error',
    ];

    protected $casts = [
        'app_secret' => 'encrypted',
        'developer_token' => 'encrypted',
        'extra_settings' => 'array',
        'is_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    /**
     * Return safe, masked representation for frontend exposure.
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'app_id' => $this->app_id,
            'has_secret' => !empty($this->app_secret),
            'masked_secret' => $this->maskSecret($this->app_secret),
            'has_developer_token' => !empty($this->developer_token),
            'masked_developer_token' => $this->maskSecret($this->developer_token),
            'redirect_uri' => $this->redirect_uri,
            'api_version' => $this->api_version,
            'region' => $this->provider==='amazon'?($this->extra_settings['region']??null):null,
            'is_enabled' => $this->is_enabled,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'test_status' => $this->test_status,
            'test_error' => $this->test_error,
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    private function maskSecret(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $length = strlen($value);
        if ($length <= 4) {
            return '••••••••';
        }

        $last4 = substr($value, -4);
        return '••••••••' . $last4;
    }
}
