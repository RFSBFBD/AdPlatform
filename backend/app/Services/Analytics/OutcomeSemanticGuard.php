<?php

namespace App\Services\Analytics;

/** Source-scoped descriptive outcomes. Never an equivalence declaration or sales verification. */
final class OutcomeSemanticGuard
{
    public const VERSION = 'source-outcomes-1';

    public function problem(iterable $rows): ?string
    {
        $providers = [];
        $definitions = [];
        foreach ($rows as $row) {
            $provider = $row->provider ?? null;
            if ($provider === 'snapchat') return 'Snapchat purchase outcomes are unsupported from the implemented bare account stats source. Historical declarations do not establish a supported conversion source.';
            $meta = $row->metadata ?? [];
            $d = $meta['outcome_contract'] ?? null;
            if (! is_string($provider) || ! is_array($d) || ($d['version'] ?? null) !== self::VERSION || ($d['provider'] ?? null) !== $provider || ! is_string($d['count_metric'] ?? null) || $d['count_metric'] === '' || ! is_string($d['value_metric'] ?? null) || $d['value_metric'] === '' || ! is_string($d['attribution_basis'] ?? null) || $d['attribution_basis'] === '' || ($d['value_truth'] ?? null) !== 'provider_reported_not_verified_sales' || ($d['cross_source_equivalence'] ?? null) !== false) {
                return 'Outcome/value source contract is missing or invalid. Numeric parsing evidence does not establish outcome meaning, attribution or revenue truth.';
            }
            $providers[$provider] = true;
            $copy = $d;
            ksort($copy);
            $definitions[json_encode($copy, JSON_THROW_ON_ERROR)] = true;
        }
        if (count($providers) > 1) {
            return 'Cross-provider outcome/value pooling is not supported: matching declarations do not establish equivalent outcomes, attribution for a joint business objective. Reported attributed events are not unique people or verified sales.';
        }
        if (count($definitions) > 1) {
            return 'Differing outcome/value definitions cannot be pooled in this history. No historical declarations were repaired.';
        }

        return null;
    }
}
