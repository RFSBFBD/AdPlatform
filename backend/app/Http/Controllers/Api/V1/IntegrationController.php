<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConnectedAdAccount;
use App\Services\Advertising\OAuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class IntegrationController extends Controller
{
    public function __construct(protected OAuthManager $oauthManager) {}

    /**
     * Get OAuth authorization URL.
     */
    public function getRedirectUrl(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        $request->validate([
            'redirect_uri' => 'required|url',
        ]);

        try {
            $adapter = $this->oauthManager->getAdapter($provider);
            $state = $this->oauthManager->generateState($provider, $request->user()->id);
            $authUrl = $adapter->getAuthorizationUrl($state, $request->redirect_uri);

            return response()->json([
                'success' => true,
                'data' => [
                    'provider' => $provider,
                    'auth_url' => $authUrl,
                    'state' => $state,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OAUTH_REDIRECT_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Handle OAuth code callback, exchange tokens, and discover accounts.
     */
    public function handleCallback(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
            'redirect_uri' => 'required|url',
        ]);

        // Validate state
        $statePayload = $this->oauthManager->validateState($request->state, $provider);
        if (!$statePayload) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_OAUTH_STATE',
                    'message' => 'The OAuth state parameter is invalid or has expired.',
                ],
            ], 400);
        }

        try {
            $adapter = $this->oauthManager->getAdapter($provider);
            $tokenData = $adapter->handleOAuthCallback($request->code, $request->redirect_uri);

            // Discover ad accounts using the access token
            $discoveredAccounts = $adapter->discoverAccounts($tokenData['access_token']);

            // Cache discovered accounts for import
            $sessionKey = "discovered_accounts:{$provider}:{$request->user()->id}";
            Cache::put($sessionKey, [
                'token_data' => $tokenData,
                'accounts' => $discoveredAccounts,
            ], 1800);

            return response()->json([
                'success' => true,
                'data' => [
                    'provider' => $provider,
                    'discovered_count' => count($discoveredAccounts),
                    'accounts' => $discoveredAccounts,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'OAUTH_CALLBACK_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Connect selected discovered accounts.
     */
    public function connectAccounts(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        $request->validate([
            'selected_account_ids' => 'required|array',
            'selected_account_ids.*' => 'string',
        ]);

        $sessionKey = "discovered_accounts:{$provider}:{$request->user()->id}";
        $cachedSession = Cache::get($sessionKey);

        if (!$cachedSession) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SESSION_EXPIRED',
                    'message' => 'Authorization session has expired. Please re-authenticate.',
                ],
            ], 400);
        }

        $tokenData = $cachedSession['token_data'];
        $discovered = collect($cachedSession['accounts'])->keyBy('external_account_id');
        $connected = [];

        foreach ($request->selected_account_ids as $accId) {
            $accountMeta = $discovered->get($accId);
            if (!$accountMeta) continue;

            // Prevent account hijacking across tenants (SEC-POST-02)
            $existing = ConnectedAdAccount::where('provider', $provider)
                ->where('external_account_id', $accId)
                ->first();

            if ($existing && $existing->user_id !== null && $existing->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'ACCOUNT_ALREADY_CONNECTED',
                        'message' => "The account '{$accountMeta['account_name']}' ({$accId}) is already connected to another user.",
                    ],
                ], 403);
            }

            $account = ConnectedAdAccount::updateOrCreate(
                [
                    'provider' => $provider,
                    'external_account_id' => $accId,
                ],
                [
                    'user_id' => $request->user()->id,
                    'account_name' => $accountMeta['account_name'],
                    'currency' => $accountMeta['currency'],
                    'timezone' => $accountMeta['timezone'],
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => $tokenData['expires_at'] ?? null,
                    'status' => 'connected',
                    'metadata' => $accountMeta,
                ]
            );

            AuditLog::log('ad_account.connected', $request->user()->id, 'ConnectedAdAccount', (string)$account->id, [
                'provider' => $provider,
                'external_account_id' => $accId,
                'account_name' => $account->account_name,
            ]);

            $connected[] = $account->toSafeArray();
        }

        Cache::forget($sessionKey);

        return response()->json([
            'success' => true,
            'message' => "Successfully connected " . count($connected) . " account(s).",
            'data' => $connected,
        ]);
    }

    /**
     * List all connected advertising accounts.
     */
    public function listAccounts(Request $request): JsonResponse
    {
        $query = ConnectedAdAccount::query();

        // Tenancy / User Scoping (SEC-001)
        $query->where('user_id', $request->user()->id);

        if ($request->has('provider')) {
            $query->where('provider', $request->provider);
        }

        $accounts = $query->orderBy('account_name', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $accounts->map->toSafeArray(),
        ]);
    }

    /**
     * Disconnect an advertising account.
     */
    public function disconnectAccount(Request $request, int $id): JsonResponse
    {
        // Enforce RBAC permission check (SEC-002)
        if (!$request->user()->can('manage-accounts') && !$request->user()->hasAnyRole(['Owner', 'Admin'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'You do not have permission to disconnect advertising accounts.',
                ],
            ], 403);
        }

        $account = ConnectedAdAccount::findOrFail($id);

        // Enforce tenancy / ownership check (SEC-001 / SEC-POST-03)
        if ($account->user_id !== $request->user()->id && !$request->user()->hasAnyRole(['Owner', 'Admin'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to manage this ad account.',
                ],
            ], 403);
        }

        AuditLog::log('ad_account.disconnected', $request->user()->id, 'ConnectedAdAccount', (string)$account->id, [
            'provider' => $account->provider,
            'external_account_id' => $account->external_account_id,
        ]);

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => "Disconnected account '{$account->account_name}'.",
        ]);
    }
}
