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
            'metrics' => ['sometimes', 'array'],
        ]);

        // Numbers come from the user's own synced data (computed in code), never from the client or the model.
        $metrics = $this->metricsForUser($request->user()->id);
        if (empty($metrics)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSUFFICIENT_DATA',
                    'message' => 'No synced performance data in the last 30 days. Connect an ad account and sync before requesting an analysis.',
                ],
            ], 422);
        }

        if (count(array_unique(array_column($metrics, 'currency'))) > 1) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'MIXED_CURRENCY',
                    'message' => 'Your connected accounts report in more than one currency. No combined analysis is produced and nothing is converted; filter to a single currency first.',
                ],
            ], 422);
        }

        try {
            $output = $this->aiManager->analyzePerformance($request->user(), $metrics);

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

    /**
     * Exact per-provider totals for the last 30 days, scoped to the authenticated user.
     */
    private function metricsForUser(int $userId): array
    {
        $accountIds = \App\Models\ConnectedAdAccount::where('user_id', $userId)->pluck('id');
        if ($accountIds->isEmpty()) {
            return [];
        }

        // Grouped per provider AND currency: amounts in different currencies are never summed.
        return \App\Models\PerformanceMetric::whereIn('connected_ad_account_id', $accountIds)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('provider, currency, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(conversions) as conversions, SUM(conversion_value) as conversion_value')
            ->groupBy('provider', 'currency')
            ->get()
            ->filter(fn($r) => (float) $r->spend > 0 || (int) $r->impressions > 0)
            ->map(fn($r) => [
                'name' => $r->provider,
                'currency' => $r->currency,
                'spend' => round((float) $r->spend, 2),
                'impressions' => (int) $r->impressions,
                'clicks' => (int) $r->clicks,
                'conversions' => (float) $r->conversions,
                'roas' => (float) $r->spend > 0 ? round((float) $r->conversion_value / (float) $r->spend, 2) : null,
                'ctr' => (int) $r->impressions > 0 ? round(100 * (int) $r->clicks / (int) $r->impressions, 2) : null,
            ])
            ->values()
            ->all();
    }
}
