<?php

namespace App\Services\OperationsResearch;

use Symfony\Component\Process\Process;

/** External HiGHS MILP of explicit sampled budget menus. Not the continuous nonlinear optimum. */
final class DiscreteBudgetOptimizer
{
    public function optimize(array $channels, float $budget, float $step, int $maximumActive, float $minimumSpend = 0, ?float $targetResponse = null, ?float $minimumResponsePerSpend = null): array
    {
        $no = fn ($status, $reason) => ['status' => $status, 'reason' => $reason];
        $python = config('solver.python');
        if (! $python || ! str_starts_with($python, '/') || ! is_executable($python)) {
            return $no('solver_unavailable', 'Optional external MILP requires an installed deployment-owned absolute Python interpreter with SciPy/HiGHS. No fallback changes the requested solver.');
        }
        if (! $channels || count($channels) > 100 || ! is_finite($budget) || $budget <= 0 || ! is_finite($step) || $step <= 0 || ! is_finite($minimumSpend) || $minimumSpend < 0 || $minimumSpend > $budget || $maximumActive < 1 || $maximumActive > count($channels)) {
            return $no('invalid_constraints', 'Finite positive cap/step and feasible minimum spend/active-count limit required.');
        }
        if(!is_finite($budget/$step)||$budget/$step>PHP_INT_MAX/4){return $no('menu_limit','Budget step is too small for bounded menu generation.');}
        if($targetResponse!==null&&(!is_finite($targetResponse)||$targetResponse<=0))return $no('invalid_constraints','Positive finite response target required.');
        if($minimumResponsePerSpend!==null&&(!is_finite($minimumResponsePerSpend)||$minimumResponsePerSpend<=0||$targetResponse!==null||$minimumSpend<=0))return $no('invalid_constraints','Positive finite ratio target and explicit positive minimum spend required; no combined value target.');
        $menus = [];
        foreach ($channels as $ch) {
            $menu=(new DiscreteSpendMenu)->build((float)$ch['min'],(float)$ch['max'],$budget,$step);
            if($menu['status']!=='ok')return $no($menu['status'],$menu['reason']);
            $lo=$menu['min_if_active'];$hi=$menu['max'];$spends=$menu['spends'];
            $choices = [];
            foreach ($spends as $x) {
                $choices[] = ['spend' => $x, 'response' => $ch['curve']->revenueAt($x)];
            }$menus[] = ['key' => $ch['channel'], 'choices' => $choices, 'min_if_active' => $lo, 'max' => $hi];
        }
        try {
            $p = new Process([$python, base_path('solver/discrete_milp.py')]);
            $p->setTimeout(15);
            $p->setInput(json_encode(['menus' => $menus, 'budget' => $budget, 'minimum_spend' => $minimumSpend, 'maximum_active' => $maximumActive,'target_response'=>$targetResponse,'minimum_response_per_spend'=>$minimumResponsePerSpend], JSON_THROW_ON_ERROR));
            $p->run();
            if (! $p->isSuccessful()) {
                return $no('solver_failed', 'External solver process failed.');
            }$r = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $no('solver_failed', 'External solver failed or exceeded15seconds. No result accepted.');
        }
        if (($r['status'] ?? null) !== 'ok') {
            return $no($r['status'] ?? 'solver_failed', $r['reason'] ?? 'No optimal discrete solution.');
        }
        $alloc = [];
        $sum = $value = 0.;
        $active = 0;
        foreach ($menus as $m) {
            $i = $r['choices'][$m['key']] ?? null;
            if (! is_int($i) || ! isset($m['choices'][$i])) {
                return $no('invalid_solver_output', 'Solver did not select one valid menu choice per entity.');
            }$c = $m['choices'][$i];
            $alloc[$m['key']] = $c['spend'];
            $sum += $c['spend'];
            $value += $c['response'];
            if ($c['spend'] > 0) {
                $active++;
            }
        }
        if ($sum > $budget + 1e-6 || $sum < $minimumSpend - 1e-6 || $active > $maximumActive || abs($value - ($r['objective_response'] ?? INF)) > 1e-5 * max(1, $value) || ($r['mip_gap'] ?? INF) > 1e-8 || ($r['solver_status'] ?? null) !== 0) {
            return $no('invalid_solver_output', 'Independent menu feasibility/objective/gap check failed.');
        }

        if(!(new DiscreteSolverCertificate)->accepts($r,$sum,$value,$targetResponse!==null))return $no('invalid_solver_output','Runtime identity/version, finite certificate or primal-dual consistency check failed.');
        if($targetResponse!==null&&($value<$targetResponse-1e-7||!isset($r['objective_spend'],$r['dual_spend_bound'])||!is_finite((float)$r['dual_spend_bound'])||abs($sum-$r['objective_spend'])>1e-6||abs($sum-$r['dual_spend_bound'])>1e-5*max(1,$sum)))return $no('invalid_solver_output','Independent target/spend/lower-bound check failed.');
        if($minimumResponsePerSpend!==null&&($sum<=0||$value<$minimumResponsePerSpend*$sum-1e-7))return $no('invalid_solver_output','Independent portfolio ratio target check failed.');
        return ['status' => 'ok', 'allocation' => $alloc, 'total_spend' => $sum, 'modeled_response' => $value, 'active_entities' => $active, 'menus' => $menus, 'certificate' => ['solver' => $r['solver'], 'scipy_version' => $r['scipy_version'], 'model_version' => $r['model_version'], 'mip_gap' => $r['mip_gap'], 'objective'=>$targetResponse===null?'maximize_response':'minimize_spend_for_target_response','target_response'=>$targetResponse,'minimum_response_per_spend'=>$minimumResponsePerSpend,'dual_response_bound' => $r['dual_response_bound']??null,'dual_spend_bound'=>$r['dual_spend_bound']??null, 'independent_feasibility_checked' => true, 'global_optimum_scope' => 'finite sampled menus only', 'continuous_error_bound' => null], 'note' => ($targetResponse===null?($minimumResponsePerSpend===null?'Explicit discrete response-maximizing pause/activate scenario.':'Explicit discrete response maximization subject to portfolio ratio target.'):'Explicit discrete minimum-spend scenario meeting the stated response target.').' Entity min is minimum IF ACTIVE, unlike continuous mandatory minima. At most cap, maximum active entities and optional total minimum spend. Optimal only for the sampled menus, not continuous fitted curves; no continuous approximation-error bound. No campaign execution.'];
    }
}
