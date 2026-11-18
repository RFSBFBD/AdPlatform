<?php

namespace App\Services\Advertising\Contracts;

interface AdvertisingProviderInterface
{
    /**
     * Generate the provider's official OAuth 2.0 authorization URL.
     */
    public function getAuthorizationUrl(string $state, string $redirectUri): string;

    /**
     * Exchange the authorization code for access and refresh tokens.
     */
    public function handleOAuthCallback(string $code, string $redirectUri): array;

    /**
     * Discover all accessible advertising accounts for the authorized user.
     */
    public function discoverAccounts(string $accessToken): array;

    /**
     * Refresh an expired access token using the refresh token where supported.
     */
    public function refreshToken(string $refreshToken): array;

    /**
     * Return provider identifier (e.g. 'meta', 'google', 'tiktok').
     */
    public function getProviderName(): string;

    /**
     * Fetch all campaigns for a connected ad account from the provider.
     */
    public function fetchCampaigns(\App\Models\ConnectedAdAccount $account): array;

    /**
     * Fetch insights/metrics for a connected ad account over a date range.
     */
    public function fetchInsights(\App\Models\ConnectedAdAccount $account, string $startDate, string $endDate): array;
}
