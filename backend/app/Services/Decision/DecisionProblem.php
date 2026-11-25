<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Owner-selected inputs, with no objective, budget, risk, horizon or currency defaults. */
final class DecisionProblem implements \JsonSerializable
{
    public const OBJECTIVES = ['MAXIMIZE_CONVERSIONS', 'MINIMIZE_CPA', 'MAXIMIZE_REVENUE', 'MAXIMIZE_ROAS',
        'MAXIMIZE_LEADS', 'TARGET_CPA', 'TARGET_ROAS', 'TARGET_REVENUE'];

    private readonly array $problem;

    public function __construct(array $spec)
    {
        $allowed = ['objective', 'currency', 'outcome_definition', 'reporting_window', 'timezone', 'source_snapshot_id',
            'response_metric', 'response_unit', 'population', 'business_estimand', 'budget_semantics', 'outcome_kind',
            'hierarchy', 'quadratic_risk', 'risk', 'primitive_shocks', 'budget_shock_multipliers', 'forecast_horizon_days', 'budget_cap', 'minimum_spend', 'minimum_response', 'target', 'target_optimization', 'horizon_days', 'allocation_mode', 'interaction_model', 'variables', 'groups'];
        if (array_diff(array_keys($spec), $allowed)) {
            throw new InvalidArgumentException('unsupported_problem_fields_or_constraints');
        }
        foreach (['objective', 'currency', 'outcome_definition', 'reporting_window', 'timezone', 'source_snapshot_id',
            'response_metric', 'response_unit', 'population', 'business_estimand', 'budget_semantics', 'outcome_kind'] as $key) {
            if (! isset($spec[$key]) || ! is_string($spec[$key]) || $spec[$key] === '') {
                throw new InvalidArgumentException('problem_required:'.$key);
            }
        }
        if (! preg_match('/^[A-Z]{3}$/D', $spec['currency']) || ! in_array($spec['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('problem_valid_currency_and_iana_timezone_required');
        }
        $window = explode('/', $spec['reporting_window']);
        if (count($window) !== 2) {
            throw new InvalidArgumentException('explicit_valid_ordered_problem_window_required');
        }
        foreach ($window as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('explicit_valid_ordered_problem_window_required');
            }
        }
        if ($window[0] > $window[1]) {
            throw new InvalidArgumentException('explicit_valid_ordered_problem_window_required');
        }
        if (isset($spec['primitive_shocks'])) {
            if (! is_array($spec['primitive_shocks']) || ! array_is_list($spec['primitive_shocks']) || $spec['primitive_shocks'] === [] || count($spec['primitive_shocks']) > 5) {
                throw new InvalidArgumentException('one_to_five_primitive_shocks_required');
            }
            foreach ($spec['primitive_shocks'] as $shock) {
                if (! is_array($shock) || $shock === [] || array_diff(array_keys($shock), ['budget', 'cpm', 'ctr', 'cvr', 'value_per_conversion'])) {
                    throw new InvalidArgumentException('derived_or_unknown_metric_cannot_be_independently_shocked');
                }foreach ($shock as $factor) {
                    ExactValue::of($factor);
                }
            }
        }
        if (isset($spec['budget_shock_multipliers'])) {
            $shocks = $spec['budget_shock_multipliers'];
            if (! is_array($shocks) || ! array_is_list($shocks) || $shocks === [] || count($shocks) > 5) {
                throw new InvalidArgumentException('one_to_five_explicit_budget_shocks_required');
            }
            foreach ($shocks as $scale) {
                $factor = ExactValue::of($scale)->decimal();
                if ($factor->isZero() || $factor->isGreaterThan('10')) {
                    throw new InvalidArgumentException('invalid_budget_shock_factor');
                }
            }
        }
        if (isset($spec['forecast_horizon_days']) && (! is_int($spec['forecast_horizon_days']) || $spec['forecast_horizon_days'] < 1 || $spec['forecast_horizon_days'] > 30)) {
            throw new InvalidArgumentException('unsupported_explicit_forecast_horizon');
        }
        if ($spec['budget_semantics'] !== 'period_total_not_daily_rate') {
            throw new InvalidArgumentException('period_total_budget_semantics_required');
        }
        if (! in_array($spec['outcome_kind'], ['purchase', 'lead', 'qualified_lead', 'other_explicit'], true)) {
            throw new InvalidArgumentException('unsupported_problem_outcome_kind');
        }
        if (! in_array($spec['objective'], self::OBJECTIVES, true)) {
            throw new InvalidArgumentException('unsupported_objective');
        }
        $count = in_array($spec['objective'], ['MAXIMIZE_CONVERSIONS', 'MINIMIZE_CPA', 'MAXIMIZE_LEADS', 'TARGET_CPA'], true);
        $response = $count ? 'conversions' : 'conversion_value';
        if ($spec['response_metric'] !== $response || ($spec['objective'] === 'MAXIMIZE_LEADS' && ($spec['outcome_kind'] ?? '') !== 'lead')) {
            throw new InvalidArgumentException('objective_response_definition_mismatch');
        }
        foreach (['budget_cap', 'minimum_spend', 'minimum_response'] as $key) {
            ExactValue::of($spec[$key] ?? null);
        }
        if (BigDecimal::of($spec['budget_cap'])->isZero() || BigDecimal::of($spec['minimum_spend'])->isGreaterThan($spec['budget_cap'])) {
            throw new InvalidArgumentException('infeasible_budget_interval');
        }
        if (in_array($spec['objective'], ['MINIMIZE_CPA', 'MAXIMIZE_ROAS', 'TARGET_CPA', 'TARGET_ROAS'], true)
            && (BigDecimal::of($spec['minimum_spend'])->isZero() || BigDecimal::of($spec['minimum_response'])->isZero())) {
            throw new InvalidArgumentException('ratio_requires_explicit_positive_minimum_spend_and_response');
        }
        // These are model-numerical admission limits, not precision limits on recorded actuals.
        foreach (['budget_cap', 'minimum_spend', 'minimum_response', 'target'] as $number) {
            if (! isset($spec[$number])) {
                continue;
            }
            $exact = ExactValue::of($spec[$number]);
            $float = (float) $exact->lexeme;
            if (! is_finite($float) || $float > 1e12 || Binary64Boundary::loss($exact->lexeme, $float)->isGreaterThan('0.0001')) {
                throw new InvalidArgumentException('problem_model_numerical_range_or_conversion_loss');
            }
        }
        if (str_starts_with($spec['objective'], 'TARGET_')) {
            if (! in_array($spec['target_optimization'] ?? null, ['minimize_spend', 'maximize_response'], true)) {
                throw new InvalidArgumentException('explicit_target_optimization_required');
            }

            if (ExactValue::of($spec['target'] ?? null)->decimal()->isZero()) {
                throw new InvalidArgumentException('positive_target_required');
            }
        }
        if (! str_starts_with($spec['objective'], 'TARGET_') && (array_key_exists('target', $spec) || array_key_exists('target_optimization', $spec))) {
            throw new InvalidArgumentException('target_policy_on_non_target_objective');
        }
        if (isset($spec['risk'])) {
            $risk = $spec['risk'];
            if (! is_array($risk) || array_diff(array_keys($risk), ['mode', 'bounds', 'evidence_id'])
                || ($risk['mode'] ?? '') !== 'independent_response_scale_box' || ! is_array($risk['bounds'] ?? null)
                || ! is_string($risk['evidence_id'] ?? null) || $risk['evidence_id'] === '') {
                throw new InvalidArgumentException('explicit_supported_risk_contract_required');
            }
            if (! in_array($spec['objective'], ['MAXIMIZE_REVENUE', 'MAXIMIZE_CONVERSIONS', 'MAXIMIZE_LEADS'], true)) {
                throw new InvalidArgumentException('robust_ratio_target_route_not_implemented');
            }
            foreach ($risk['bounds'] as $bound) {
                if (! is_array($bound) || array_diff(array_keys($bound), ['lower_scale', 'upper_scale']) || ! isset($bound['lower_scale'],$bound['upper_scale'])) {
                    throw new InvalidArgumentException('explicit_scale_box_bounds_required');
                }
                ExactValue::of($bound['lower_scale']);
                ExactValue::of($bound['upper_scale']);
                foreach (['lower_scale', 'upper_scale'] as $k) {
                    $float = (float) $bound[$k];
                    if (! is_finite($float) || $float > 1e6 || Binary64Boundary::loss($bound[$k], $float)->isGreaterThan('0.0000000001')) {
                        throw new InvalidArgumentException('risk_scale_numeric_range_or_conversion_loss');
                    }
                }
                if (BigDecimal::of($bound['lower_scale'])->isGreaterThan($bound['upper_scale'])) {
                    throw new InvalidArgumentException('invalid_scale_box_order');
                }
            }
        }
        if (! isset($spec['horizon_days']) || ! is_int($spec['horizon_days']) || $spec['horizon_days'] < 1 || $spec['horizon_days'] > 366) {
            throw new InvalidArgumentException('explicit_horizon_days_required');
        }
        if (! in_array(($spec['allocation_mode'] ?? ''), ['finite_menu', 'continuous_linear', 'continuous_concave'], true) || ($spec['interaction_model'] ?? '') !== 'separable_no_overlap_claim') {
            throw new InvalidArgumentException('unsupported_allocation_or_interaction_model');
        }
        if ($spec['allocation_mode'] === 'continuous_concave' && ! (in_array($spec['objective'], ['MAXIMIZE_CONVERSIONS', 'MAXIMIZE_REVENUE', 'MAXIMIZE_LEADS', 'MINIMIZE_CPA', 'MAXIMIZE_ROAS'], true) || $spec['objective'] === 'TARGET_REVENUE' || in_array($spec['objective'], ['TARGET_CPA', 'TARGET_ROAS'], true))) {
            throw new InvalidArgumentException('continuous_concave_maxima_or_revenue_target_only');
        }
        if (! isset($spec['variables']) || ! is_array($spec['variables']) || ! array_is_list($spec['variables']) || $spec['variables'] === [] || count($spec['variables']) > 100) {
            throw new InvalidArgumentException('explicit_atomic_variables_required');
        }
        $ids = [];
        $entities = [];
        foreach ($spec['variables'] as $v) {
            if (! is_array($v) || array_diff(array_keys($v), ['id', 'platform', 'campaign_type', 'entity_level', 'entity_id', 'current_spend', 'minimum', 'maximum', 'menu'])) {
                throw new InvalidArgumentException('unsupported_variable_fields_or_constraints');
            }
            foreach (['id', 'platform', 'campaign_type', 'entity_level', 'entity_id', 'current_spend', 'minimum', 'maximum', 'menu'] as $key) {
                if (! array_key_exists($key, $v)) {
                    throw new InvalidArgumentException('variable_required:'.$key);
                }
            }
            if (! is_string($v['id']) || $v['id'] === '' || isset($ids[$v['id']])) {
                throw new InvalidArgumentException('duplicate_or_invalid_variable');
            }
            $ids[$v['id']] = true;
            foreach (['platform', 'campaign_type', 'entity_level', 'entity_id'] as $field) {
                if (! is_string($v[$field]) || $v[$field] === '') {
                    throw new InvalidArgumentException('explicit_variable_context_required');
                }
            }
            $identity = RecordedLedger::canonical([$v['platform'], $v['entity_level'], $v['entity_id']]);
            if (isset($entities[$identity])) {
                throw new InvalidArgumentException('duplicate_atomic_entity');
            }
            $entities[$identity] = true;
            foreach (['current_spend', 'minimum', 'maximum'] as $key) {
                ExactValue::of($v[$key]);
            }
            if (BigDecimal::of($v['minimum'])->isGreaterThan($v['maximum'])) {
                throw new InvalidArgumentException('infeasible_variable_bounds');
            }
            if ($spec['allocation_mode'] !== 'finite_menu' && $v['menu'] !== []) {
                throw new InvalidArgumentException('continuous_domain_requires_empty_menu_not_discretization');
            }
            if (! is_array($v['menu']) || ! array_is_list($v['menu']) || ($spec['allocation_mode'] === 'finite_menu' && $v['menu'] === []) || count($v['menu']) > 1000) {
                throw new InvalidArgumentException('explicit_finite_menu_required');
            }
            $menu = [];
            foreach ($v['menu'] as $point) {
                $number = ExactValue::of($point)->decimal();
                if ($number->isLessThan($v['minimum']) || $number->isGreaterThan($v['maximum'])) {
                    throw new InvalidArgumentException('menu_outside_bounds');
                }
                $key = (string) $number->toBigRational();
                if (isset($menu[$key])) {
                    throw new InvalidArgumentException('duplicate_menu_amount');
                }
                $menu[$key] = true;
            }
        }
        if (isset($spec['risk']) && (array_diff(array_keys($spec['risk']['bounds']), array_keys($ids)) || array_diff(array_keys($ids), array_keys($spec['risk']['bounds'])))) {
            throw new InvalidArgumentException('risk_population_mismatch');
        }
        if (! isset($spec['groups']) || ! is_array($spec['groups']) || ! array_is_list($spec['groups']) || count($spec['groups']) > 100) {
            throw new InvalidArgumentException('explicit_group_constraints_required');
        }
        if (isset($spec['hierarchy'])) {
            $spec = (new DeclaredHierarchyConstraints)->normalize($spec);
        }
        $groups = [];
        foreach ($spec['groups'] as $g) {
            if (! is_array($g) || array_diff(array_keys($g), ['id', 'members', 'minimum', 'maximum'])) {
                throw new InvalidArgumentException('unsupported_group_fields_or_constraints');
            }
            if (! isset($g['id'], $g['members'], $g['minimum'], $g['maximum']) || ! is_string($g['id'])
                || $g['id'] === '' || isset($groups[$g['id']]) || ! is_array($g['members']) || ! array_is_list($g['members']) || $g['members'] === []
                || count(array_filter($g['members'], fn ($member) => is_string($member) && $member !== '')) !== count($g['members'])
                || count(array_unique($g['members'])) !== count($g['members']) || array_diff($g['members'], array_keys($ids))) {
                throw new InvalidArgumentException('invalid_atomic_group_constraint');
            }
            if (! isset($spec['hierarchy']) && str_starts_with($g['id'], DeclaredHierarchyConstraints::PREFIX)) {
                throw new InvalidArgumentException('hierarchy_reserved_group_requires_contract');
            }
            $groups[$g['id']] = true;
            ExactValue::of($g['minimum']);
            ExactValue::of($g['maximum']);
            if (BigDecimal::of($g['minimum'])->isGreaterThan($g['maximum'])) {
                throw new InvalidArgumentException('infeasible_group_bounds');
            }
        }
        if (count(array_unique(array_column($spec['variables'], 'entity_level'))) !== 1) {
            throw new InvalidArgumentException('mixed_decision_hierarchy_requires_verified_incidence_contract');
        }
        if (isset($spec['quadratic_risk'])) {
            (new QuadraticRiskPolicy)->validate($spec);
        }
        // Bounds on overlapping sets are legitimate constraints. Response interaction/deduplication is a different problem.
        $this->problem = ['problem_version' => 'typed-problem-v1'] + $spec;
    }

    public function jsonSerialize(): array
    {
        return $this->problem;
    }

    public function id(): string
    {
        return hash('sha256', RecordedLedger::canonical($this->problem));
    }
}
