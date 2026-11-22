<?php

namespace App\Services\Ai;

use App\Models\AiAuditLog;
use App\Models\AiConfig;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiManager
{
    /**
     * Narration of an already computed evidence snapshot. Returns null when the configured key is a
     * placeholder/test key: canned demo text is never returned as if it were an analysis.
     */
    public function explainSuggestions(User $user, string $systemPrompt, string $userPrompt): ?array
    {
        $config = $this->getActiveConfig($user);
        if ($this->usesFakeProvider($config)) {
            return null;
        }
        return $this->executeAiCompletion($config, $systemPrompt, $userPrompt, 'explain_suggestions');
    }

    /**
     * Test connection to configured AI provider with latency measurement.
     */
    public function testConnection(AiConfig $config): array
    {
        if (empty($config->api_key)) {
            throw new RuntimeException("API key is not configured.");
        }

        $startTime = microtime(true);

        if ($config->provider === 'openai') {
            $res = Http::withToken($config->api_key)
                ->timeout(10)
                ->get('https://api.openai.com/v1/models');
            if ($res->failed()) {
                // Never leak the upstream body: it can echo request details (including the key).
                throw new RuntimeException("OpenAI rejected the connection (HTTP {$res->status()}). Check the API key and model access.");
            }
            $latency = (int) round((microtime(true) - $startTime) * 1000);
            return ['status' => 'connected', 'provider' => 'openai', 'latency_ms' => $latency];
        }

        if ($config->provider === 'anthropic') {
            $res = Http::withHeaders([
                'x-api-key' => $config->api_key,
                'anthropic-version' => '2023-06-01',
            ])->timeout(10)->get('https://api.anthropic.com/v1/models');
            if ($res->failed()) {
                throw new RuntimeException("Anthropic rejected the connection (HTTP {$res->status()}). Check the API key and model access.");
            }
            $latency = (int) round((microtime(true) - $startTime) * 1000);
            return ['status' => 'connected', 'provider' => 'anthropic', 'latency_ms' => $latency];
        }

        if ($config->provider === 'gemini') {
            $res = Http::timeout(10)
                ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$config->api_key}");
            if ($res->failed()) {
                throw new RuntimeException("Gemini rejected the connection (HTTP {$res->status()}). Check the API key and model access.");
            }
            $latency = (int) round((microtime(true) - $startTime) * 1000);
            return ['status' => 'connected', 'provider' => 'gemini', 'latency_ms' => $latency];
        }

        // Custom / OpenAI-compatible endpoint (Groq, OpenRouter, Mistral, Ollama, DeepSeek, etc.)
        $baseUrl = rtrim($config->metadata['base_url'] ?? '', '/');
        if (empty($baseUrl)) {
            $baseUrl = 'https://api.openai.com/v1';
        }

        $res = Http::withToken($config->api_key)->timeout(10)->get("{$baseUrl}/models");
        if ($res->failed()) {
            throw new RuntimeException("The model API rejected the connection (HTTP {$res->status()}). Check the API key and endpoint URL.");
        }
        $latency = (int) round((microtime(true) - $startTime) * 1000);
        return [
            'status' => 'connected', 
            'provider' => $config->provider, 
            'model' => $config->model ?: 'custom-model',
            'base_url' => $baseUrl,
            'latency_ms' => $latency
        ];
    }

    protected function executeAiCompletion(AiConfig $config, string $systemPrompt, string $userPrompt, string $action): array
    {
        // Enforce monthly token limits if configured
        if ($config->monthly_token_limit && $config->current_month_tokens >= $config->monthly_token_limit) {
            throw new RuntimeException("Monthly AI token limit reached ({$config->monthly_token_limit} tokens). Adjust your limit in AI Center settings.");
        }

        // Provider Execution (OpenAI, Anthropic, or Gemini)
        $tokensUsed = 350;
        $content = [];

        $isMock = $this->usesFakeProvider($config);

        if (!$isMock) {
            try {
                if ($config->provider === 'openai') {
                    $response = Http::withToken($config->api_key)->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $config->model ?: 'gpt-4o',
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ]);

                    if (!$response->successful()) {
                        throw new RuntimeException("OpenAI API returned HTTP {$response->status()}: " . $response->body());
                    }

                    $json = $response->json();
                    $tokensUsed = $json['usage']['total_tokens'] ?? 350;
                    $content = json_decode($json['choices'][0]['message']['content'] ?? '{}', true) ?: [];
                } elseif ($config->provider === 'anthropic') {
                    $response = Http::withHeaders([
                        'x-api-key' => $config->api_key,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                        'model' => $config->model ?: 'claude-3-5-sonnet-20241022',
                        'max_tokens' => 1024,
                        'system' => $systemPrompt,
                        'messages' => [
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                    ]);

                    if (!$response->successful()) {
                        throw new RuntimeException("Anthropic API returned HTTP {$response->status()}: " . $response->body());
                    }

                    $json = $response->json();
                    $tokensUsed = ($json['usage']['input_tokens'] ?? 200) + ($json['usage']['output_tokens'] ?? 150);
                    $text = $json['content'][0]['text'] ?? '{}';
                    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
                    $content = json_decode($clean, true) ?: [];
                } elseif ($config->provider === 'gemini') {
                    $model = $config->model ?: 'gemini-1.5-flash';
                    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$config->api_key}";
                    $response = Http::timeout(30)->post($url, [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [
                                    ['text' => $systemPrompt . "\n\n" . $userPrompt],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                        ],
                    ]);

                    if (!$response->successful()) {
                        throw new RuntimeException("Gemini API returned HTTP {$response->status()}: " . $response->body());
                    }

                    $json = $response->json();
                    $tokensUsed = $json['usageMetadata']['totalTokenCount'] ?? 350;
                    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
                    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
                    $content = json_decode($clean, true) ?: [];
                } else {
                    $baseUrl = rtrim($config->metadata['base_url'] ?? '', '/');
                    if (empty($baseUrl)) {
                        $baseUrl = 'https://api.openai.com/v1';
                    }
                    $response = Http::withToken($config->api_key)->timeout(30)->post("{$baseUrl}/chat/completions", [
                        'model' => $config->model ?: 'custom-model',
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ]);

                    if (!$response->successful()) {
                        throw new RuntimeException("Custom AI Provider returned HTTP {$response->status()}: " . $response->body());
                    }

                    $json = $response->json();
                    $tokensUsed = $json['usage']['total_tokens'] ?? 350;
                    $content = json_decode($json['choices'][0]['message']['content'] ?? '{}', true) ?: [];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("AI provider [{$config->provider}] failed: " . $e->getMessage());
                throw new RuntimeException("AI provider [{$config->provider}] request failed: " . $e->getMessage(), 0, $e);
            }
        } else {
            // A fake/test provider never produces a completion. The narrator checks usesFakeProvider()
            // before calling this and falls back to deterministic order, so reaching here with a mock
            // key means a caller skipped that guard. Refuse instead of inventing canned text.
            throw new RuntimeException('A fake or placeholder AI key cannot produce a completion.');
        }

        // Track usage & audit
        $config->increment('current_month_tokens', $tokensUsed);

        AiAuditLog::create([
            'user_id' => $config->user_id,
            'provider' => $config->provider,
            'action' => $action,
            'prompt_tokens' => (int) ($tokensUsed * 0.6),
            'completion_tokens' => (int) ($tokensUsed * 0.4),
            'total_tokens' => $tokensUsed,
            'estimated_cost' => round(($tokensUsed / 1000) * 0.005, 4),
            'metadata' => ['model' => $config->model],
        ]);

        return [
            'data' => $content,
            'tokens_used' => $tokensUsed,
            'model' => $config->model,
            'provider' => $config->provider,
        ];
    }

    /**
     * Fake provider mode (canned output) exists only for isolated tests. It is decided server-side by the
     * environment, never by what the user typed as a key: outside `testing` a mock-looking or empty key is
     * rejected and no analysis is produced.
     */
    protected function usesFakeProvider(AiConfig $config): bool
    {
        $key = (string) $config->api_key;
        $looksFake = $key === '' || str_starts_with($key, 'mock_') || str_starts_with($key, 'sk-mock-');
        if (!$looksFake) {
            return false;
        }
        if (app()->environment('testing') || (config('ai.allow_fake_provider', false) && !app()->environment('production'))) {
            return true;
        }
        throw new RuntimeException('This AI provider has no valid API key. Fake or placeholder keys are rejected outside the testing environment, so no analysis was produced.');
    }

    protected function getActiveConfig(User $user): AiConfig
    {
        // Strictly per-user: never borrow another tenant's AI configuration or key.
        $config = AiConfig::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            throw new \RuntimeException('No active AI provider is configured for your account. Add one in AI settings first.');
        }

        return $config;
    }
}
