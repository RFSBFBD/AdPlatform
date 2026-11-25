<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConnectedAdAccount;
use App\Services\Advertising\OAuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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

        if (!$this->oauthManager->isAllowedRedirectUri((string) $request->redirect_uri)) {
            return $this->redirectNotAllowed();
        }

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
            'code' => $provider==='x'?'nullable|string':'required|string',
            'oauth_token' => $provider==='x'?'required|string':'nullable|string',
            'oauth_verifier' => $provider==='x'?'required|string':'nullable|string',
            'state' => 'required|string',
            'redirect_uri' => 'required|url',
        ]);

        if (!$this->oauthManager->isAllowedRedirectUri((string) $request->redirect_uri)) {
            return $this->redirectNotAllowed();
        }

        // Validate state
        $statePayload = $this->oauthManager->validateState($request->state, $provider, $request->user()->id);
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
            $tokenData = $provider==='x' ? $adapter->complete($request->state,$request->oauth_token,$request->oauth_verifier) : $adapter->handleOAuthCallback($request->code, $request->redirect_uri);

            // Discover ad accounts using the access token
            $discoveredAccounts = $adapter->discoverAccounts($tokenData['access_token']);

            // Cache discovered accounts for import
            $sessionKey = "discovered_accounts:{$provider}:{$request->user()->id}";
            // Shared cache stores (database, redis) serialize without objects, and the payload holds provider tokens.
            // Dates become ISO strings and the whole payload is stored encrypted, never as plain text.
            $tokenForCache = array_map(
                fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v,
                $tokenData
            );
            Cache::put($sessionKey, Crypt::encryptString(json_encode([
                'token_data' => $tokenForCache,
                'accounts' => $discoveredAccounts,
            ], JSON_THROW_ON_ERROR)), 1800);

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

    /** Client-credentials are platform-admin credentials, never exposed to other tenants. */
    public function discoverCredentialAccounts(Request $request, string $provider): JsonResponse
    {
        abort_unless($request->user()->can('manage-developers'), 403);
        abort_unless(in_array($provider, ['taboola', 'apple'], true), 404);
        $adapter = $this->oauthManager->getAdapter($provider);
        $token = $adapter->issueToken();
        $accounts = $adapter->discoverAccounts($token['access_token']);
        $token['expires_at'] = $token['expires_at']->toIso8601String();
        Cache::put("discovered_accounts:{$provider}:{$request->user()->id}", Crypt::encryptString(json_encode([
            'token_data' => $token, 'accounts' => $accounts,
        ], JSON_THROW_ON_ERROR)), 1800);
        return response()->json(['success' => true, 'data' => ['provider' => $provider, 'accounts' => $accounts, 'discovered_count' => count($accounts)]]);
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
        if (is_string($cachedSession)) {
            try {
                $cachedSession = json_decode(Crypt::decryptString($cachedSession), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $cachedSession = null;
            }
        }

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

            // Currency and timezone are never defaulted: a missing value stops the connect for that account.
            if (empty($accountMeta['currency']) || empty($accountMeta['timezone'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'ACCOUNT_METADATA_MISSING',
                        'message' => "The provider did not return a currency or timezone for '{$accountMeta['account_name']}' ({$accId}), so it was not connected. Nothing was assumed.",
                    ],
                ], 422);
            }

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
        $provenance=(new \App\Services\Advertising\SyncProvenance)->forAccounts($accounts);

        return response()->json([
            'success' => true,
            'data' => $accounts->map(fn($a)=>$a->toSafeArray()+['sync_provenance'=>$provenance[$a->id]]),
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
        if ($account->user_id !== $request->user()->id) {
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

    /** Delivery-only Apple data never enters revenue tables or optimizer. */
    public function appleDeliveryReport(Request $request, int $id): JsonResponse
    {
        $account = ConnectedAdAccount::where('user_id', $request->user()->id)->where('provider', 'apple')->findOrFail($id);
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date']);
        $result = $this->oauthManager->getAdapter('apple')->deliveryReport($account, $data['start_date'], $data['end_date']);
        unset($result['raw']);
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function xDeliveryReport(Request $request, int $id): JsonResponse
    {
        $account=ConnectedAdAccount::where('user_id',$request->user()->id)->where('provider','x')->findOrFail($id);
        $v=$request->validate(['start_date'=>'required|date_format:Y-m-d','end_date'=>'required|date_format:Y-m-d|after_or_equal:start_date']);
        return response()->json(['success'=>true,'data'=>$this->oauthManager->getAdapter('x')->deliveryReport($account,$v['start_date'],$v['end_date'])]);
    }

    private function redirectNotAllowed(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'REDIRECT_URI_NOT_ALLOWED',
                'message' => 'This redirect URI is not on the server allowlist (OAUTH_ALLOWED_REDIRECT_URIS). It must match what is registered with the provider.',
            ],
        ], 422);
    }
}
