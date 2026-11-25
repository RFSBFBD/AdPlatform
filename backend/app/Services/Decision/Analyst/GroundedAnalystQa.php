<?php

declare(strict_types=1);

namespace App\Services\Decision\Analyst;

use App\Services\Decision\FrozenReviewQuestions;

/**
 * Evidence-constrained assistance over one frozen decision snapshot.
 *
 * The provider may only pick evidence pointers and assert a numeric relation between two of them. All user-visible
 * text (English and Arabic) is written HERE from the pointer's own path, its exact stored value and a fixed evidence
 * type tag. Entity, metric and number therefore come from the evidence itself, and a "compare" claim is recomputed
 * exactly. What this does NOT establish: that the chosen evidence is relevant to the question, that the frozen
 * evidence is true or complete, causation, calibration, or any permission to change campaigns or spend.
 */
final class GroundedAnalystQa
{
    public const MAX_CLAIMS = 8;

    private const MAX_LEAVES = 3000;

    public function __construct(private readonly AnalystProvider $provider)
    {
    }

    public function ask(array $result, string $question): array
    {
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > 1000) {
            return $this->reject($result, ['question_empty_or_too_long']);
        }
        $leaves = $this->leaves($result);
        if ($leaves === []) {
            return $this->reject($result, ['no_evidence_available']);
        }
        $reply = $this->provider->claims([
            'question' => $question,
            'evidence' => array_map(fn ($p, $v) => ['pointer' => $p, 'value' => $v], array_keys($leaves), array_values($leaves)),
            'instructions' => 'Select evidence pointers only. No prose, no numbers.',
        ]);
        $claims = is_array($reply) ? ($reply['claims'] ?? null) : null;
        if (! is_array($claims) || ! array_is_list($claims) || $claims === [] || count($claims) > self::MAX_CLAIMS || array_diff(array_keys($reply), ['claims'])) {
            return $this->reject($result, ['provider_reply_outside_contract']);
        }
        $errors = [];
        $out = [];
        foreach ($claims as $i => $claim) {
            $rendered = is_array($claim) ? $this->render($claim, $leaves) : null;
            if (is_string($rendered)) {
                $errors[] = "claim_$i:$rendered";
            } else {
                $out[] = $rendered;
            }
        }
        if ($errors !== []) {
            return $this->reject($result, $errors);
        }

        return ['status' => 'evidence_constrained_answer', 'snapshot_id' => $result['snapshot_id'] ?? null, 'question' => $question, 'claims' => $out,
            'external_ai_called' => $this->provider->isExternal(),
            'relevance_verified' => false, 'validated_answer' => false,
            'limits' => ($this->provider->isExternal() ? 'The AI model only picked' : 'A fixed script, not an AI model, picked').' which frozen evidence to show. Text and numbers are written by the server from that evidence. Relevance of the pick to your question is NOT verified, and neither is the source data, causation, calibration or any permission to change campaigns or spend.',
            'limits_ar' => ($this->provider->isExternal() ? 'نموذج الذكاء الاصطناعي اختار' : 'سكربت ثابت، وليس نموذج ذكاء اصطناعي، اختار').' فقط أي أدلة محفوظة تُعرض. النص والأرقام يكتبها الخادم من هذه الأدلة. لم يُتحقق من مناسبة الاختيار لسؤالك، ولا من صحة بيانات المصدر، ولا من السببية، ولا يوجد إذن بتغيير الحملات أو الإنفاق.'];
    }

    /** @return array|string rendered claim, or an error code */
    private function render(array $claim, array $leaves): array|string
    {
        $type = $claim['type'] ?? null;
        if ($type === 'value' && ! array_diff(array_keys($claim), ['type', 'pointer'])) {
            $p = $claim['pointer'] ?? null;
            if (! is_string($p) || ! array_key_exists($p, $leaves)) {
                return 'unknown_pointer';
            }
            $v = $this->display($leaves[$p]);

            return ['type' => 'value', 'pointers' => [$p], 'evidence_type' => $this->tag($p), 'tag_ar' => $this->tagAr($p),
                'a' => ['label' => $this->label($p), 'value' => $v],
                'text' => $this->label($p).': '.$v.' ['.$this->tag($p).']',
                'text_ar' => $this->label($p).': '.$v.' ['.$this->tagAr($p).']'];
        }
        if ($type === 'compare' && ! array_diff(array_keys($claim), ['type', 'a', 'b', 'relation'])) {
            [$a, $b, $rel] = [$claim['a'] ?? null, $claim['b'] ?? null, $claim['relation'] ?? null];
            if (! is_string($a) || ! is_string($b) || ! array_key_exists($a, $leaves) || ! array_key_exists($b, $leaves)) {
                return 'unknown_pointer';
            }
            if ($a === $b || ! in_array($rel, ['greater', 'less', 'equal'], true)) {
                return 'bad_comparison';
            }
            if ($this->tag($a) !== $this->tag($b)) {
                return 'mixed_evidence_types';
            }
            $why = $this->incomparable($a, $b, $leaves);
            if ($why !== null) {
                return $why;
            }
            $cmp = $this->compare($leaves[$a], $leaves[$b]);
            if ($cmp === null) {
                return 'non_numeric_comparison';
            }
            $actual = $cmp > 0 ? 'greater' : ($cmp < 0 ? 'less' : 'equal');
            if ($actual !== $rel) {
                return 'relation_false';
            }
            $words = ['greater' => ['is greater than', 'أكبر من'], 'less' => ['is less than', 'أقل من'], 'equal' => ['equals', 'يساوي']][$rel];
            $va = $this->display($leaves[$a]);
            $vb = $this->display($leaves[$b]);

            return ['type' => 'compare', 'pointers' => [$a, $b], 'relation' => $rel, 'evidence_type' => $this->tag($a), 'tag_ar' => $this->tagAr($a),
                'a' => ['label' => $this->label($a), 'value' => $va], 'b' => ['label' => $this->label($b), 'value' => $vb],
                'relation_en' => $words[0], 'relation_ar' => $words[1],
                'text' => $this->label($a)." ($va) {$words[0]} ".$this->label($b)." ($vb) [".$this->tag($a).']',
                'text_ar' => $this->label($a)." ($va) {$words[1]} ".$this->label($b)." ($vb) [".$this->tagAr($a).']'];
        }

        return 'claim_outside_contract';
    }

    private function reject(array $result, array $reasons): array
    {
        return ['status' => 'rejected_outside_evidence', 'snapshot_id' => $result['snapshot_id'] ?? null, 'reasons' => $reasons, 'external_ai_called' => $this->provider->isExternal(),
            'limits' => 'Nothing from the model is shown. Use the fixed evidence questions instead.'];
    }

    /**
     * Comparability gate: same metric key at the same depth, and any currency/unit label found on the path or in the
     * enclosing sections must be identical. Key names are the only semantic signal available in frozen evidence, so
     * this is a conservative heuristic: it can refuse a valid comparison but must not admit a different metric/unit.
     */
    private function incomparable(string $a, string $b, array $leaves): ?string
    {
        $pa = explode('/', ltrim($a, '/'));
        $pb = explode('/', ltrim($b, '/'));
        if (count($pa) !== count($pb) || end($pa) !== end($pb)) {
            return 'different_metric_or_depth';
        }
        if ($pa[0] !== $pb[0]) {
            return 'different_section';
        }
        foreach (['currency', 'unit', 'response_unit', 'timezone', 'window'] as $label) {
            $va = $this->nearestLabel($pa, $label, $leaves);
            $vb = $this->nearestLabel($pb, $label, $leaves);
            if ($va !== $vb) {
                return 'different_'.$label.'_or_scope';
            }
        }

        return null;
    }

    private function nearestLabel(array $parts, string $label, array $leaves): ?string
    {
        for ($n = count($parts) - 1; $n >= 1; $n--) {
            $key = '/'.implode('/', array_slice($parts, 0, $n)).'/'.$label;
            if (array_key_exists($key, $leaves)) {
                return (string) $leaves[$key];
            }
        }

        return null;
    }

    private function tag(string $pointer): string
    {
        return match (true) {
            str_starts_with($pointer, '/forecast') => 'EXPECTED',
            str_starts_with($pointer, '/sensitivity'), str_starts_with($pointer, '/primitive_scenarios'), str_starts_with($pointer, '/consumers/decision_review'), str_starts_with($pointer, '/consumers/charts/scenario_comparison') => 'SCENARIO',
            str_starts_with($pointer, '/actual'), str_starts_with($pointer, '/consumers/contribution_trace') => 'ACTUAL',
            default => 'COMPUTED',
        };
    }

    private function tagAr(string $pointer): string
    {
        return ['EXPECTED' => 'متوقع', 'SCENARIO' => 'سيناريو', 'ACTUAL' => 'فعلي', 'COMPUTED' => 'محسوب'][$this->tag($pointer)];
    }

    private function label(string $pointer): string
    {
        return implode(' / ', array_map(fn ($s) => str_replace(['~1', '~0'], ['/', '~'], $s), explode('/', ltrim($pointer, '/'))));
    }

    private function display(mixed $v): string
    {
        return match (true) {
            $v === null => 'null (unavailable)',
            is_bool($v) => $v ? 'true' : 'false',
            is_float($v) => rtrim(rtrim(sprintf('%.15F', $v), '0'), '.'),
            default => (string) $v,
        };
    }

    private function compare(mixed $a, mixed $b): ?int
    {
        $x = $this->numeric($a);
        $y = $this->numeric($b);

        return $x === null || $y === null ? null : bccomp($x, $y, 18);
    }

    private function numeric(mixed $v): ?string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? sprintf('%.18F', $v) : null;
        }

        return is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : null;
    }

    /** @return array<string,scalar|null> */
    private function leaves(array $result): array
    {
        $out = [];
        foreach (FrozenReviewQuestions::ROUTES as $route) {
            foreach (array_slice($route, 1) as $pointer) {
                $value = $result;
                foreach (explode('/', substr($pointer, 1)) as $key) {
                    if (! is_array($value) || ! array_key_exists($key, $value)) {
                        $value = null;
                        break;
                    }
                    $value = $value[$key];
                }
                if ($value !== null) {
                    $this->walk($value, $pointer, $out);
                }
            }
        }

        return $out;
    }

    private function walk(mixed $value, string $pointer, array &$out): void
    {
        if (count($out) >= self::MAX_LEAVES) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->walk($v, $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $k), $out);
            }

            return;
        }
        if (is_scalar($value) || $value === null) {
            $out[$pointer] = $value;
        }
    }
}
