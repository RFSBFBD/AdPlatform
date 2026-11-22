<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Services\Ai\AiManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiController extends Controller
{
    public function __construct(
        protected AiManager $aiManager
    ) {}

    /**
     * Get user AI configurations.
     */
    public function getConfig(Request $request): JsonResponse
    {
        $user = $request->user();
        $configs = AiConfig::where('user_id', $user->id)->get();

        $data = $configs->map(fn($c) => $c->toSafeArray());

        return response()->json([
            'configs' => $data,
        ]);
    }

    /**
     * Update or create a provider AI configuration (BYOK & Custom Models).
     */
    public function updateConfig(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'min:3'],
            'model' => ['nullable', 'string', 'max:255'],
            'base_url' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'monthly_token_limit' => ['nullable', 'integer', 'min:1000'],
        ]);

        $user = $request->user();

        $config = AiConfig::firstOrNew([
            'user_id' => $user->id,
            'provider' => strtolower($provider),
        ]);

        if (!empty($validated['api_key'])) {
            $config->api_key = $validated['api_key'];
        }

        if (!empty($validated['model'])) {
            $config->model = $validated['model'];
        }

        if (isset($validated['base_url'])) {
            $meta = $config->metadata ?? [];
            $meta['base_url'] = $validated['base_url'];
            $config->metadata = $meta;
        }

        if (isset($validated['is_active'])) {
            $config->is_active = $validated['is_active'];
        }

        if (isset($validated['monthly_token_limit'])) {
            $config->monthly_token_limit = $validated['monthly_token_limit'];
        }

        $config->save();

        return response()->json([
            'message' => "AI configuration for {$provider} updated successfully.",
            'config' => $config->toSafeArray(),
        ]);
    }

    /**
     * Delete an AI configuration.
     */
    public function deleteConfig(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        AiConfig::where('user_id', $user->id)
            ->where('provider', strtolower($provider))
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "AI configuration for {$provider} removed successfully.",
        ]);
    }

    /**
     * Test connection for an AI configuration.
     */
    public function testConnection(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        $config = AiConfig::where('user_id', $user->id)
            ->where('provider', $provider)
            ->firstOrFail();

        if (empty($config->api_key)) {
            return response()->json([
                'success' => false,
                'message' => "No API key is saved for {$provider}. Add the key, then test again.",
                'error' => [
                    'code' => 'AI_KEY_NOT_CONFIGURED',
                    'message' => "No API key is saved for {$provider}. Add the key, then test again.",
                ],
            ], 422);
        }

        try {
            $result = $this->aiManager->testConnection($config);
        } catch (ConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => "Could not reach the {$provider} API. Check the endpoint URL and try again.",
                'error' => [
                    'code' => 'AI_UPSTREAM_UNREACHABLE',
                    'message' => "Could not reach the {$provider} API. Check the endpoint URL and try again.",
                ],
            ], 502);
        } catch (RuntimeException $e) {
            // AiManager failure messages are safe by contract: provider + HTTP status only, never the key or response body.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'AI_UPSTREAM_REJECTED',
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        return response()->json([
            'message' => "Connection to {$provider} verified successfully.",
            'result' => $result,
        ]);
    }

}
