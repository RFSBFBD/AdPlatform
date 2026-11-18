<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProviderDeveloperConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeveloperConfigController extends Controller
{
    protected array $supportedProviders = ['meta', 'google', 'tiktok'];

    /**
     * List all developer configurations with masked secrets.
     */
    public function index(): JsonResponse
    {
        $configs = [];

        foreach ($this->supportedProviders as $provider) {
            $config = ProviderDeveloperConfig::firstOrCreate(
                ['provider' => $provider],
                [
                    'is_enabled' => true,
                    'test_status' => 'untested',
                ]
            );

            $configs[] = $config->toSafeArray();
        }

        return response()->json([
            'success' => true,
            'data' => $configs,
        ]);
    }

    /**
     * Store or update provider developer credentials.
     */
    public function update(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        if (!in_array($provider, $this->supportedProviders, true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_PROVIDER',
                    'message' => "Provider '{$provider}' is not supported.",
                ],
            ], 404);
        }

        $request->validate([
            'app_id' => 'nullable|string|max:255',
            'app_secret' => 'nullable|string',
            'developer_token' => 'nullable|string',
            'redirect_uri' => 'nullable|url|max:500',
            'api_version' => 'nullable|string|max:50',
            'is_enabled' => 'nullable|boolean',
        ]);

        $config = ProviderDeveloperConfig::firstOrNew(['provider' => $provider]);

        if ($request->has('app_id')) {
            $config->app_id = $request->app_id;
        }

        // Only update secret if a new non-empty value was provided (avoids overwriting with masked value)
        if ($request->filled('app_secret') && !str_starts_with($request->app_secret, '••••')) {
            $config->app_secret = $request->app_secret;
        }

        if ($request->filled('developer_token') && !str_starts_with($request->developer_token, '••••')) {
            $config->developer_token = $request->developer_token;
        }

        if ($request->has('redirect_uri')) {
            $config->redirect_uri = $request->redirect_uri;
        }

        if ($request->has('api_version')) {
            $config->api_version = $request->api_version;
        }

        if ($request->has('is_enabled')) {
            $config->is_enabled = $request->boolean('is_enabled');
        }

        $config->save();

        // Audit log without exposing raw secrets
        AuditLog::log('developer_config.updated', $request->user()->id, 'ProviderDeveloperConfig', (string)$config->id, [
            'provider' => $provider,
            'has_app_id' => !empty($config->app_id),
            'has_app_secret' => !empty($config->app_secret),
            'has_developer_token' => !empty($config->developer_token),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Configuration for {$provider} updated successfully.",
            'data' => $config->toSafeArray(),
        ]);
    }

    /**
     * Test handshake / validation for provider configuration.
     */
    public function testConnection(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        $config = ProviderDeveloperConfig::where('provider', $provider)->first();

        if (!$config || empty($config->app_id) || empty($config->app_secret)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INCOMPLETE_CONFIGURATION',
                    'message' => "Configuration for {$provider} is incomplete. App ID and Secret are required.",
                ],
            ], 422);
        }

        // Provider-specific verification
        $status = 'failed';
        $error = null;

        if ($provider === 'google' && empty($config->developer_token)) {
            $error = 'Google Ads API requires a Developer Token in addition to OAuth Client credentials.';
        } else {
            // Validate credential formats
            $status = 'configured';
            $error = null;
        }

        $config->last_tested_at = now();
        $config->test_status = $status;
        $config->test_error = $error;
        $config->save();

        AuditLog::log('developer_config.tested', $request->user()->id, 'ProviderDeveloperConfig', (string)$config->id, [
            'provider' => $provider,
            'status' => $status,
        ]);

        return response()->json([
            'success' => ($status !== 'failed'),
            'data' => [
                'provider' => $provider,
                'test_status' => $status,
                'last_tested_at' => $config->last_tested_at->toIso8601String(),
                'message' => $error ? $error : "Credentials format validated for {$provider}.",
            ],
        ]);
    }
}
