<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Services\Ai\AiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $result = $this->aiManager->testConnection($config);

        return response()->json([
            'message' => "Connection to {$provider} verified successfully.",
            'result' => $result,
        ]);
    }

    /**
     * Converse with AI Data Analyst to evaluate marketing performance.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant,system'],
            'messages.*.content' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $output = $this->aiManager->chat($request->user(), $validated['messages']);

            return response()->json([
                'success' => true,
                'reply' => $output['reply'],
                'usage' => [
                    'tokens' => $output['tokens_used'],
                    'model' => $output['model'],
                    'provider' => $output['provider'],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AI_CHAT_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Generate platform-specific ad copy variations.
     */
    public function generateCopy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:meta,google,tiktok'],
            'product_name' => ['required', 'string', 'max:150'],
            'objective' => ['nullable', 'string', 'max:100'],
            'tone' => ['nullable', 'string', 'max:50'],
            'target_audience' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $output = $this->aiManager->generateAdCopy($request->user(), $validated);

            return response()->json([
                'success' => true,
                'copy' => $output['data'],
                'usage' => [
                    'tokens' => $output['tokens_used'],
                    'model' => $output['model'],
                    'provider' => $output['provider'],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AI_EXECUTION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Analyze campaign metrics and provide AI optimization advice.
     */
    public function analyzePerformance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metrics' => ['required', 'array'],
        ]);

        try {
            $output = $this->aiManager->analyzePerformance($request->user(), $validated['metrics']);

            return response()->json([
                'success' => true,
                'analysis' => $output['data'],
                'usage' => [
                    'tokens' => $output['tokens_used'],
                    'model' => $output['model'],
                    'provider' => $output['provider'],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AI_EXECUTION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }
}
