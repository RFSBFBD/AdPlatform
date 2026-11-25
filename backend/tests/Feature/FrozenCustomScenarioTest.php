<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionEvidenceArchive;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\FrozenCustomScenario;
use App\Services\Decision\IntegratedDecisionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FrozenCustomScenarioTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        require_once __DIR__.'/ConcaveIntegratedDecisionTest.php';
        $t = new ConcaveIntegratedDecisionTest('test_analytic_log_allocation_has_independent_tangent_bound_and_frozen_replay');
        $m = new \ReflectionMethod($t, 'fixtureProblem');
        [$ledger,$spec,$models] = $m->invoke($t);
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);

        return (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
    }

    public function test_custom_uses_only_frozen_models_and_constraints_without_changing_parent(): void
    {
        $r = $this->fixture();
        $original = RecordedLedger::canonical($r);
        $service = new FrozenCustomScenario;
        $ok = $service->evaluate($r, ['a' => '6', 'b' => '4']);
        self::assertSame('checked_custom_scenario', $ok['status']);
        self::assertSame('10', $ok['custom']['check']['spend_exact']);
        self::assertEqualsWithDelta(2 * log(7) + log(5), $ok['custom']['check']['response_approximate'], 1e-10);
        self::assertSame($r['snapshot_id'], $ok['snapshot_id']);
        self::assertFalse($ok['stored_archive_changed']);
        self::assertFalse($ok['operational_approval']);
        self::assertSame('compared_frozen_model_scenarios', $ok['comparison']['comparisons']['custom']['status']);
        self::assertSame('rejected_custom_scenario', $service->evaluate($r, ['a' => '10', 'b' => '10'])['status']);
        self::assertSame('rejected_custom_scenario', $service->evaluate($r, ['a' => '5'])['status']);
        self::assertSame($original, RecordedLedger::canonical($r));
        $r['execution_manifest'] = [];
        unset($r['audit_hash']);
        $r['audit_hash'] = hash('sha256', RecordedLedger::canonical($r));
        self::assertSame('frozen_execution_manifest_mismatch', $service->evaluate($r, ['a' => '6', 'b' => '4'])['reason']);
    }

    public function test_endpoint_checks_owner_integrity_payload_and_does_not_create_new_archive(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $r = $this->fixture();
        $id = (new DecisionEvidenceArchive)->save($u->id, $r);
        $url = '/api/v1/analytics/decision-evidence/'.$id.'/custom-scenario';
        $this->actingAs($other)->postJson($url, ['allocation' => ['a' => '6', 'b' => '4']])->assertNotFound();
        $this->actingAs($u)->postJson($url, ['allocation' => ['a' => '6', 'b' => '4']])->assertOk()->assertJsonPath('data.status', 'checked_custom_scenario');
        $this->postJson($url, ['allocation' => ['a' => 6, 'b' => '4']])->assertUnprocessable();
        $this->postJson($url, ['allocation' => ['a' => '6', 'b' => '4'], 'models' => []])->assertUnprocessable();
        self::assertSame(1, DB::table('decision_evidence_runs')->count());
        self::assertSame($r['audit_hash'], (new DecisionEvidenceArchive)->load($u->id, $id)['audit_hash']);
        DB::table('decision_evidence_runs')->where('id', $id)->update(['audit_hash' => 'changed']);
        $this->postJson($url,['allocation' => ['a' => '6', 'b' => '4']])->assertStatus(409);
    }
}
