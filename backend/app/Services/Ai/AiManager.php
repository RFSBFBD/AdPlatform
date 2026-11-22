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
     * Generate platform-tailored ad copy with strict character boundaries.
     */
    public function generateAdCopy(User $user, array $params): array
    {
        $platform = $params['platform'] ?? 'meta';
        $product = $params['product_name'] ?? 'Product';
        $objective = $params['objective'] ?? 'Conversions';
        $tone = $params['tone'] ?? 'Engaging';
        $audience = $params['target_audience'] ?? 'General consumers';

        $config = $this->getActiveConfig($user);

        // System prompt enforcing strict character limits and structured output
        $systemPrompt = <<<PROMPT
You are an expert performance marketing copywriter for paid digital advertising.
Generate high-converting ad copy adhering strictly to the character constraints of {$platform} Ads:
- Meta Ads: Headline (<= 40 chars), Primary Text (<= 125 chars recommended), Description (<= 30 chars).
- Google Ads: Headlines (<= 30 chars each, produce 3 variations), Descriptions (<= 90 chars each, produce 2 variations).
- TikTok Ads: Hook / Ad Text (<= 100 chars, fast-paced and natural).

Return pure JSON with keys:
{
  "platform": "{$platform}",
  "headlines": ["..."],
  "primary_texts": ["..."],
  "descriptions": ["..."],
  "call_to_action": "Shop Now"
}
PROMPT;

        $userPrompt = "Product: {$product}\nObjective: {$objective}\nTone: {$tone}\nTarget Audience: {$audience}";

        $result = $this->executeAiCompletion($config, $systemPrompt, $userPrompt, 'generate_copy');
        return $result;
    }

    /**
     * Analyze campaign performance and recommend actionable optimizations.
     */
    public function analyzePerformance(User $user, array $metrics): array
    {
        $config = $this->getActiveConfig($user);

        $systemPrompt = <<<PROMPT
You are a senior paid media performance analyst.
Review the provided campaign metrics and deliver actionable optimization advice.
RULES:
1. You may ONLY offer suggestions and observations.
2. NEVER propose automated budget changes or pauses without explicit human approval.
3. Categorize output into: Observations, Creative Recommendations, and Budget Allocations.
Return pure JSON with keys:
{
  "summary": "...",
  "strengths": ["..."],
  "concerns": ["..."],
  "recommendations": ["..."]
}
PROMPT;

        $userPrompt = "Campaign Metrics:\n" . json_encode($metrics, JSON_PRETTY_PRINT);

        return $this->executeAiCompletion($config, $systemPrompt, $userPrompt, 'analyze_performance');
    }

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
                throw new RuntimeException("OpenAI connection failed: " . ($res->json('error.message') ?? $res->body()));
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
                throw new RuntimeException("Anthropic connection failed: " . ($res->json('error.message') ?? $res->body()));
            }
            $latency = (int) round((microtime(true) - $startTime) * 1000);
            return ['status' => 'connected', 'provider' => 'anthropic', 'latency_ms' => $latency];
        }

        if ($config->provider === 'gemini') {
            $res = Http::timeout(10)
                ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$config->api_key}");
            if ($res->failed()) {
                throw new RuntimeException("Gemini connection failed: " . ($res->json('error.message') ?? $res->body()));
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
            throw new RuntimeException("Custom Model API connection failed (HTTP {$res->status()}): " . ($res->json('error.message') ?? $res->body()));
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
            // High quality deterministic fallback structure when in mock mode or unconfigured test environment
            if ($action === 'generate_copy') {
                $content = [
                    'platform' => 'meta',
                    'headlines' => ['Premium Quality Guaranteed', 'Special Seasonal Offer', 'Upgrade Your Everyday'],
                    'primary_texts' => ['Discover our top-rated collection crafted for performance and value. Shop now for fast delivery!'],
                    'descriptions' => ['Limited Time Offer'],
                    'call_to_action' => 'Shop Now',
                ];
            } else {
                $content = [
                    'summary' => 'Campaign shows positive ROAS with opportunity to optimize click-through rate.',
                    'strengths' => ['Strong conversion rate at 5.2%', 'ROAS exceeds target threshold at 3.4x'],
                    'concerns' => ['CTR in Ad Set #2 dropped 18% over the past 7 days indicating potential fatigue'],
                    'recommendations' => ['Refresh creative assets in Ad Set #2', 'Scale budget by 10% on Top Performing Ad Set'],
                ];
            }
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
     * Interactive Chat for marketing data analysis.
     */
    public function chat(User $user, array $messages): array
    {
        $config = $this->getActiveConfig($user);

        // Fetch user marketing context to make the chatbot an expert data analyst
        $connectedAccountsCount = \App\Models\ConnectedAdAccount::where('user_id', $user->id)->count();
        $metrics = \App\Models\PerformanceMetric::whereHas('connectedAdAccount', fn($q) => $q->where('user_id', $user->id))
            ->where('date', '>=', now()->subDays(30))
            ->selectRaw('provider, SUM(spend) as total_spend, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, SUM(conversions) as total_conversions, SUM(conversion_value) as total_value')
            ->groupBy('provider')
            ->get();

        $metricsContext = "User has {$connectedAccountsCount} connected ad accounts.\n30-Day Channel Summary:\n" . json_encode($metrics, JSON_PRETTY_PRINT);

        $systemPrompt = <<<PROMPT
You are an expert Performance Advertising Data Analyst & Growth Strategist.
Your goal is to converse with the marketer, interpret their real performance metrics, and answer questions to maximize ROAS, lower CPC/CPA, and identify scale opportunities across Meta, Google Ads, and TikTok.
Here is the user's current advertising performance data context:
{$metricsContext}

Guidelines:
1. Provide concise, clear, and actionable data-driven answers.
2. Structure answers with clean bullet points and metric summaries when helpful.
3. Suggest concrete budget or creative optimizations.
PROMPT;

        return $this->executeAiChat($config, $systemPrompt, $messages);
    }

    protected function executeAiChat(AiConfig $config, string $systemPrompt, array $messages): array
    {
        // Enforce monthly token limits if configured
        if ($config->monthly_token_limit && $config->current_month_tokens >= $config->monthly_token_limit) {
            throw new RuntimeException("Monthly AI token limit reached ({$config->monthly_token_limit} tokens). Adjust your limit in AI Center settings.");
        }

        $tokensUsed = 280;
        $reply = '';

        $isMock = $this->usesFakeProvider($config);

        if (!$isMock) {
            try {
                $formattedMessages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages);

                if ($config->provider === 'openai' || !in_array($config->provider, ['anthropic', 'gemini'])) {
                    $baseUrl = rtrim($config->metadata['base_url'] ?? '', '/');
                    if (empty($baseUrl)) {
                        $baseUrl = 'https://api.openai.com/v1';
                    }
                    $model = $config->model ?: ($config->provider === 'openai' ? 'gpt-4o' : 'custom-model');

                    $response = Http::withToken($config->api_key)->timeout(30)->post("{$baseUrl}/chat/completions", [
                        'model' => $model,
                        'messages' => $formattedMessages,
                    ]);

                    if (!$response->successful()) {
                        throw new RuntimeException("API returned HTTP {$response->status()}: " . $response->body());
                    }

                    $json = $response->json();
                    $tokensUsed = $json['usage']['total_tokens'] ?? 280;
                    $reply = $json['choices'][0]['message']['content'] ?? '';
                } elseif ($config->provider === 'anthropic') {
                    $response = Http::withHeaders([
                        'x-api-key' => $config->api_key,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                        'model' => $config->model ?: 'claude-3-5-sonnet-20241022',
                        'max_tokens' => 1500,
                        'system' => $systemPrompt,
                        'messages' => $messages,
                    ]);

                    if (!$response->successful()) {
                        throw new RuntimeException("Anthropic API returned HTTP {$response->status()}: " . $response->body());
                    }

                    $json = $response->json();
                    $tokensUsed = ($json['usage']['input_tokens'] ?? 150) + ($json['usage']['output_tokens'] ?? 130);
                    $reply = $json['content'][0]['text'] ?? '';
                } elseif ($config->provider === 'gemini') {
                    $model = $config->model ?: 'gemini-1.5-flash';
                    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$config->api_key}";

                    $contents = [];
                    foreach ($messages as $m) {
                        $role = ($m['role'] === 'assistant' ? 'model' : 'user');
                        $contents[] = [
                            'role' => $role,
                            'parts' => [['text' => $m['content']]],
                        ];
                    }

                    $response = Http::timeout(30)->post($url, [
                        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                        'contents' => $contents,
                    ]);

                    if (!$response->successful()) {
                        throw new RuntimeException("Gemini API returned HTTP {$response->status()}: " . $response->body());
                    }

                    $json = $response->json();
                    $tokensUsed = $json['usageMetadata']['totalTokenCount'] ?? 280;
                    $reply = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("AI Chat failed [{$config->provider}]: " . $e->getMessage());
                throw new RuntimeException("AI Chat failed: " . $e->getMessage(), 0, $e);
            }
        } else {
            // Intelligent contextual deterministic response in mock environment
            $lastUserMessage = end($messages)['content'] ?? '';
            $reply = "📊 **Performance Analysis Insight**:\n\nBased on your 30-day advertising data across connected channels:\n\n" .
                "• **Top Channel Efficiency**: Google Ads is generating strong search intent conversion with high blended ROAS, while Meta Ads provides scale at competitive CPM.\n" .
                "• **Budget Allocation Recommendation**: We recommend shifting ~15% of underperforming TikTok test budgets into your highest-performing Meta Ad Sets.\n" .
                "• **Action Item**: Review your top 3 ads with CTR below 0.8% and refresh creatives with fresh hooks.\n\n*Question asked: \"{$lastUserMessage}\"*";
        }

        // Track usage & audit
        $config->increment('current_month_tokens', $tokensUsed);

        AiAuditLog::create([
            'user_id' => $config->user_id,
            'provider' => $config->provider,
            'action' => 'chat_analysis',
            'prompt_tokens' => (int) ($tokensUsed * 0.6),
            'completion_tokens' => (int) ($tokensUsed * 0.4),
            'total_tokens' => $tokensUsed,
            'estimated_cost' => round(($tokensUsed / 1000) * 0.005, 4),
            'metadata' => ['model' => $config->model],
        ]);

        return [
            'reply' => $reply,
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
