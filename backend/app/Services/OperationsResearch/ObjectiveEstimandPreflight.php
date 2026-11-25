<?php

namespace App\Services\OperationsResearch;

use App\Services\Analytics\OutcomeSemanticGuard;

/** Selection is intent, never evidence that source metrics measure a common business outcome. */
final class ObjectiveEstimandPreflight
{
    public const VERSION = 'objective-estimand-1';
    public const OBJECTIVES = ['MAXIMIZE_REPORTED_VALUE', 'MAXIMIZE_REPORTED_CONVERSIONS', 'MAXIMIZE_CONVERSIONS', 'MINIMIZE_CPA', 'MAXIMIZE_REVENUE', 'MAXIMIZE_ROAS', 'MAXIMIZE_LEADS', 'TARGET_CPA', 'TARGET_ROAS', 'TARGET_REVENUE'];
    public const BASES = ['platform_reported_credit', 'common_business_events'];

    /** Descriptive source groups only. No mixed-action total or ROAS/CPA is invented. */
    public function descriptive(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = ($row->provider ?? 'unknown') . '/' . ($row->connected_ad_account_id ?? 'unknown') . '/' . ($row->currency ?? 'unknown');
            $groups[$key][] = $row;
        }
        ksort($groups);
        $result = [];
        foreach ($groups as $key => $group) {
            $problem = (new \App\Services\Analytics\MetaDefinitionTransitionGuard)->problem($group);
            $counts = 0.0;
            $value = 0.0;
            $days = [];
            foreach ($group as $row) {
                $day = substr((string) $row->date, 0, 10);
                if (isset($days[$day])) $problem = 'Multiple hierarchy or duplicate rows on an account-day require an explicit aggregation source. No descriptive total calculated.';
                $days[$day] = true;
                foreach (['conversions', 'conversion_value'] as $field) {
                    if (!is_numeric($row->{$field}) || !is_finite((float) $row->{$field}) || (float) $row->{$field} < 0) $problem = 'Invalid reported operand.';
                }
                $counts += (float) $row->conversions;
                $value += (float) $row->conversion_value;
            }
            if (!is_finite($counts) || !is_finite($value)) $problem = 'Reported sum exceeds finite numeric range.';
            $first = $group[0];
            if (!is_string($first->currency) || !preg_match('/^[A-Z]{3}$/', $first->currency)) $problem = 'Explicit source currency is required.';
            $result[$key] = ['provider' => $first->provider, 'connected_ad_account_id' => $first->connected_ad_account_id, 'currency' => $first->currency,
                'status' => $problem ? 'withheld' : 'reported', 'reason' => $problem,
                'platform_reported_conversions' => $problem ? null : $counts,
                'platform_reported_conversion_value' => $problem ? null : $value,
                'outcome_contract' => $first->metadata['outcome_contract'] ?? null];
        }
        return ['groups' => $result, 'pooled_business_outcomes' => null, 'note' => 'Source-reported attributed credits only. Groups may count the same event or person. Different metric families and attribution rules remain separate. Value is not verified revenue or profit; no count/value ratio or cross-source common objective is implied.'];
    }

    public function inspect(array $options, iterable $rows, array $window): array
    {
        $objective = $options['objective'] ?? null;
        $basis = $options['outcome_basis'] ?? null;
        $missing = [];
        if ($objective === null) $missing[] = 'objective';
        if ($basis === null) $missing[] = 'outcome_basis';
        $status = $missing ? 'objective_basis_required' : 'ready_for_source_validation';
        $reason = $missing ? 'Select an objective and outcome basis explicitly. Neither is inferred from a business profile, example budget or metric column.' : 'Selection recorded. Existing source, model, quality and constraint gates still apply.';
        if (!$missing && (!in_array($objective, self::OBJECTIVES, true) || !in_array($basis, self::BASES, true))) {
            $status = 'unsupported_objective_basis';
            $reason = 'Unknown objective or outcome basis. No replacement objective was selected.';
        } elseif (!$missing && $basis === 'common_business_events') {
            $status = 'business_outcome_source_required';
            $reason = 'A common business event/value source and an implemented portfolio adapter are required. Provider attribution credit and a declaration of equivalence do not establish business outcomes.';
            $missing = ['common_event_definition', 'business_event_source_and_population', 'counting_and_value_rules', 'attribution_credit_policy', 'date_and_currency_basis', 'portfolio_business_source_adapter'];
        } elseif (!$missing && $objective !== 'MAXIMIZE_REPORTED_VALUE') {
            $status = 'unsupported_objective_basis';
            $reason = 'This portfolio path currently implements only MAXIMIZE_REPORTED_VALUE with platform_reported_credit. Business revenue, count, lead and ratio/target objectives are not substituted.';
        }
        $sources = [];
        $unverified = [];
        foreach ($rows as $row) {
            $metadata = $row->metadata ?? [];
            $contract = $metadata['outcome_contract'] ?? null;
            $source = [
                'provider' => $row->provider ?? null,
                'connected_ad_account_id' => $row->connected_ad_account_id ?? null,
                'currency' => $row->currency ?? null,
                'outcome_contract' => $contract,
            ];
            $key = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
            $sources[$key] = $source;
            $problems = [];
            if (!is_array($contract) || ($contract['version'] ?? null) !== OutcomeSemanticGuard::VERSION) {
                $problems[] = 'source_outcome_contract';
            } else {
                foreach (['count_metric', 'value_metric', 'attribution_basis'] as $field) {
                    if (!is_string($contract[$field] ?? null) || $contract[$field] === '') $problems[] = $field;
                }
                // Current imports preserve selected fields, but do not independently verify these dimensions.
                $problems = array_merge($problems, ['configured_event_population', 'counting_rules', 'value_rules', 'report_date_basis']);
                $attribution = is_string($contract['attribution_basis'] ?? null) ? $contract['attribution_basis'] : '';
                if ($attribution === '' || str_contains($attribution, 'unverified') || str_contains($attribution, 'not_independently_verified')) $problems[] = 'attribution_rules';
            }
            if ($problems) $unverified[$key] = ['source_key' => $key, 'unverified_dimensions' => array_values(array_unique($problems))];
        }
        ksort($sources);
        ksort($unverified);
        return [
            'version' => self::VERSION, 'status' => $status, 'reason' => $reason,
            'selection' => ['objective' => $objective, 'outcome_basis' => $basis, 'selection_source' => 'explicit_request_fields_only'],
            'missing_inputs' => $missing, 'window' => $window,
            'source_evidence' => $sources, 'source_evidence_sha256' => hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR)),
            'source_limitations' => array_values($unverified),
            'claims' => ['business_outcome_equivalence' => false, 'unique_people_or_events' => false, 'verified_sales_or_profit' => false, 'causal_response' => false],
            'limits' => 'Reported-credit selection is not a common business estimand. Cross-provider optimization remains unsupported by the current semantic/model path; descriptive source groups are not an allocation recommendation.',
        ];
    }
}
