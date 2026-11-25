<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;
use Symfony\Component\Process\Process;

/** Concave PSD covariance-penalty allocation. Proposal only numerical; objective and supporting bound rational. */
final class TypedQuadraticRiskSolver
{
    public function solve(DecisionProblem $problem, array $models): array
    {
        $p = $problem->jsonSerialize();
        if (! isset($p['quadratic_risk'])) {
            return ['status' => 'quadratic_policy_required'];
        }
        (new QuadraticRiskPolicy)->validate($p);
        $risk = $p['quadratic_risk'];
        $slopes = [];
        $variables = [];
        foreach ($p['variables'] as $v) {
            $c = $models[$v['id']]['curve'];
            if ($c->name() !== 'linear') {
                return ['status' => 'quadratic_linear_response_required'];
            }$slopes[] = Binary64Boundary::rational($c->parameters()['a']);
            foreach (['minimum', 'maximum'] as $field) {
                if (! is_finite((float) $v[$field]) || (float) $v[$field] > 1e12 || Binary64Boundary::loss($v[$field], (float) $v[$field])->isGreaterThan('0.0001')) {
                    return ['status' => 'quadratic_bound_range'];
                }
            }$variables[] = ['id' => $v['id'], 'minimum' => (float) $v['minimum'], 'maximum' => (float) $v['maximum']];
        }
        $groups = [];
        foreach ($p['groups'] as $g) {
            $groups[] = ['id' => $g['id'], 'members' => $g['members'], 'minimum' => (float) $g['minimum'], 'maximum' => (float) $g['maximum']];
        }
        $input = ['variables' => $variables, 'slopes' => array_map(fn ($x) => $x->toFloat(), $slopes), 'groups' => $groups, 'budget_cap' => (float) $p['budget_cap'], 'minimum_spend' => (float) $p['minimum_spend'], 'minimum_response' => (float) $p['minimum_response'], 'lambda' => (float) $risk['lambda'], 'covariance' => array_map(fn ($row) => array_map(fn ($x) => (float) $x, $row), $risk['covariance'])];
        try {
            $proposal = $this->execute('typed_quadratic_risk.py', $input);
        } catch (\Throwable) {
            return ['status' => 'quadratic_solver_unavailable_or_failed'];
        }
        if (($proposal['status'] ?? null) !== 'proposed') {
            return $proposal;
        }
        $allocation = [];
        foreach ($variables as $v) {
            $x = $proposal['allocation'][$v['id']] ?? null;
            if (! is_numeric($x) || ! is_finite((float) $x) || $x < 0) {
                return ['status' => 'invalid_quadratic_proposal'];
            }$allocation[$v['id']] = (string) Binary64Boundary::rational((float) $x)->toBigDecimal();
        }
        $recovery = (new ExactLinearVertexRecovery)->recover($problem, $proposal['allocation'], array_fill(0, count($variables), 0.), true);
        if ($recovery['status'] === 'recovered_candidate') {
            $allocation = $recovery['allocation'];
        }
        $check = (new IndependentAllocationCheck)->check($problem, $models, $allocation);
        if ($check['status'] !== 'checked') {
            return ['status' => 'quadratic_independent_check_rejected', 'check' => $check];
        }
        $evaluated = (new QuadraticRiskPolicy)->evaluate($p, $allocation, $slopes);
        $gradient = $evaluated['gradient'];
        $intercept = $evaluated['objective'];
        foreach ($p['variables'] as $i => $v) {
            $intercept = $intercept->minus($gradient[$i]->multipliedBy($allocation[$v['id']]));
        }
        $boundInput = $input;
        unset($boundInput['lambda'],$boundInput['covariance']);
        $boundInput['objective'] = 'MAXIMIZE_CONVERSIONS';
        $boundInput['target'] = null;
        $boundInput['minimum_response'] = 0.;
        $boundInput['objective_slopes'] = array_map(fn ($x) => $x->toFloat(), $gradient);
        $boundInput['extra_inequalities'] = [['row' => array_map(fn ($x) => -$x->toFloat(), $slopes), 'rhs' => -(float) $p['minimum_response']]];
        foreach ($boundInput['variables'] as $i => &$v) {
            $v['slope'] = $slopes[$i]->toFloat();
        }unset($v);
        try {
            $lp = $this->execute('typed_linear_lp.py', $boundInput);
        } catch (\Throwable) {
            return ['status' => 'quadratic_tangent_lp_failed'];
        }
        if (($lp['status'] ?? null) !== 'solved' || empty($lp['trace'])) {
            return ['status' => 'quadratic_tangent_lp_not_solved'];
        }
        $spec = $p;
        unset($spec['problem_version'],$spec['quadratic_risk']);
        $spec['minimum_response'] = '0';
        $bound = (new LinearDualResidualBound)->maximum(new DecisionProblem($spec), $gradient, $lp['trace'][count($lp['trace']) - 1], [array_map(fn ($x) => $x->negated(), $slopes)], [BigRational::of($p['minimum_response'])->negated()]);
        if ($bound['status'] !== 'bounded') {
            return ['status' => 'quadratic_bound_unavailable'];
        }
        $upper = $intercept->plus(BigRational::of($bound['upper_exact']['numerator'])->dividedBy($bound['upper_exact']['denominator']));
        $gap = $upper->minus($evaluated['objective']);
        if ($gap->isLessThan(0) || $gap->toFloat() > 1e-6 * max(1., abs($evaluated['objective']->toFloat()))) {
            return ['status' => 'quadratic_gap_not_closed', 'gap_approximate' => $gap->toFloat()];
        }

        return ['status' => 'checked_numerical_solver', 'allocation' => $allocation, 'independent_check' => $check, 'certificate' => ['version' => 'typed-quadratic-risk-v1', 'proposal' => $proposal, 'exact_candidate_recovery' => $recovery, 'tangent_lp' => $lp, 'represented_linear_bound' => $bound, 'upper_bound_exact' => ExactValue::fraction($upper), 'objective_exact' => ExactValue::fraction($evaluated['objective']), 'gap_exact' => ExactValue::fraction($gap), 'gap_approximate' => $gap->toFloat(), 'psd_check' => 'exact_rational_Schur_complement', 'guarantee' => 'global_concave_quadratic_supporting_bound_for_declared_exact_decimal_covariance_and_represented_linear_response_not_calibrated_empirical_risk', 'probability' => null]];
    }

    private function execute(string $script, array $input): array
    {
        $python = config('solver.python');
        if (! is_string($python) || ! str_starts_with($python, '/') || ! is_executable($python)) {
            throw new \RuntimeException('solver_unavailable');
        }$p = new Process([$python, base_path('solver/'.$script)]);
        $p->setTimeout(20);
        $p->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $p->mustRun();

        return json_decode($p->getOutput(), true, 128, JSON_THROW_ON_ERROR);
    }
}
