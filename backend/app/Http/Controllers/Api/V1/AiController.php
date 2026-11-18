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
     * Update or create a provider AI configuration (BYOK).
     */
    public function updateConfig(Request $request, string $provider): JsonResponse
    {
        if (!in_array($provider, ['openai', 'anthropic', 'gemini'])) {
            return response()->json(['message' => 'Invalid AI provider.'], 422);
        }

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'min:10'],
            'model' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'monthly_token_limit' => ['nullable', 'integer', 'min:1000'],
        ]);

        $user = $request->user();

        $config = AiConfig::firstOrNew([
            'user_id' => $user->id,
            'provider' => $provider,
        ]);

        if (!empty($validated['api_key'])) {
            $config->api_key = $validated['api_key'];
        }

        if (!empty($validated['model'])) {
            $config->model = $validated['model'];
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
    }

    /**
     * Analyze campaign metrics and provide AI optimization advice.
     */
    public function analyzePerformance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metrics' => ['required', 'array'],
        ]);

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
    }
}
