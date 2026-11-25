<?php

namespace App\Services\Advertising;

use App\Services\Advertising\Adapters\GoogleAdsAdapter;
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use App\Services\Advertising\Adapters\SnapchatAdsAdapter;
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
            'snapchat' => new SnapchatAdsAdapter(),
            'x' => new \App\Services\Advertising\Adapters\XAdsAdapter(),
            'amazon' => new \App\Services\Advertising\Adapters\AmazonAdsAdapter(),
            'microsoft' => new \App\Services\Advertising\Adapters\MicrosoftAdsAdapter(),
            'outbrain' => new \App\Services\Advertising\Adapters\OutbrainAdsAdapter(),
            'apple' => new \App\Services\Advertising\Adapters\AppleAdsAdapter(),
            'linkedin' => new \App\Services\Advertising\Adapters\LinkedInAdsAdapter(),
            'spotify' => new \App\Services\Advertising\Adapters\SpotifyAdsAdapter(),
            'reddit' => new \App\Services\Advertising\Adapters\RedditAdsAdapter(),
            'pinterest' => new \App\Services\Advertising\Adapters\PinterestAdsAdapter(),
            'taboola' => new \App\Services\Advertising\Adapters\TaboolaAdsAdapter(),
            default => throw new InvalidArgumentException("Unsupported advertising provider: '{$provider}'"),
        };
    }

    /**
     * Exact-match allowlist from config/oauth.php. The client never chooses a redirect target;
     * an empty list means every redirect is refused.
     */
    public function isAllowedRedirectUri(string $uri): bool
    {
        return in_array($uri, (array) config('oauth.allowed_redirect_uris', []), true);
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

    public function validateState(string $state, string $expectedProvider, int $expectedUserId): ?array
    {
        $key = "oauth_state:{$state}";
        $payload = Cache::get($key);

        // Check provider AND user BEFORE consuming: someone else's attempt must not burn the real user's state.
        if (!$payload
            || ($payload['provider'] ?? null) !== $expectedProvider
            || (int) ($payload['user_id'] ?? 0) !== $expectedUserId) {
            return null;
        }

        Cache::forget($key); // single use

        return $payload;
    }
}
