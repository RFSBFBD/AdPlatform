<?php

declare(strict_types=1);

namespace App\Services\Decision;

/** Closed intent routing to computed evidence sections. Unsupported questions never become invented answers. */
final class FrozenReviewQuestions
{
    public const ROUTES = ['actual' => ['Recorded actuals', '/actual/totals', '/consumers/contribution_trace'],
        'changes' => ['Simulation budget changes', '/consumers/decision_review', '/consumers/charts/scenario_comparison'],
        'confidence' => ['Uncertainty and its limits', '/consumers/analyst_input/confidence', '/forecast'],
        'quality' => ['Source and missingness', '/consumers/calendar_coverage', '/consumers/analyst_input/data_quality'],
        'diagnostics' => ['Descriptive signals', '/consumers/diagnostics', '/consumers/anomaly_evidence'],
        'sensitivity' => ['Budget and primitive stress', '/sensitivity', '/primitive_scenarios']];

    public function answer(array $result, string $question): array
    {
        if (! isset(self::ROUTES[$question])) {
            return ['status' => 'unsupported_question', 'snapshot_id' => $result['snapshot_id'], 'reason' => 'Only listed evidence intents are supported; no free-form numeric answer is generated.'];
        }
        $title = self::ROUTES[$question][0];
        $pointers = array_slice(self::ROUTES[$question], 1);
        $sections = [];
        foreach ($pointers as $pointer) {
            $value = $result;
            foreach (explode('/', substr($pointer, 1)) as $key) {
                if (! is_array($value) || ! array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }
            $sections[] = ['pointer' => $pointer, 'status' => $value === null ? 'unavailable' : 'frozen_evidence', 'value' => $value];
        }

        return ['status' => 'computed_evidence_answer', 'snapshot_id' => $result['snapshot_id'], 'intent' => $question, 'title' => $title,
            'sections' => $sections, 'external_ai_called' => false, 'limits' => 'Evidence retrieval, not an open-ended AI answer. Values retain their original types and availability. No new numbers, causal claims, ranking, confidence or operational permission.'];
    }
}
