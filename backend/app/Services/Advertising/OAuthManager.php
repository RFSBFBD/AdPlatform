<?php

namespace App\Services\Advertising;

use App\Services\Advertising\Adapters\GoogleAdsAdapter;
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use App\Services\Advertising\Adapters\TikTokAdsAdapter;
use App\Services\Advertising\Contracts\AdvertisingProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OAuthManager
{
    public function getAdapter(string $provider): AdvertisingProviderInterface
    {
        return match (strtolower($provider)) {
            'meta' => new MetaAdsAdapter(),
            'google' => new GoogleAdsAdapter(),
            'tiktok' => new TikTokAdsAdapter(),
            default => throw new InvalidArgumentException("Unsupported advertising provider: '{$provider}'"),
        };
    }

    public function generateState(string $provider, int $userId): string
    {
        $state = Str::random(40);
        $payload = [
            'provider' => $provider,
            'user_id' => $userId,
            'created_at' => now()->timestamp,
        ];

        // Store state in cache for 15 minutes
        Cache::put("oauth_state:{$state}", $payload, 900);

        return $state;
    }

    public function validateState(string $state, string $expectedProvider): ?array
    {
        $payload = Cache::pull("oauth_state:{$state}");

        if (!$payload || ($payload['provider'] !== $expectedProvider)) {
            return null;
        }

        return $payload;
    }
}
