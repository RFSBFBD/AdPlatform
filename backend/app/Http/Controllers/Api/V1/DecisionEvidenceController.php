<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAdAccount;
use App\Services\Advertising\Sync\RecordedDeliveryRepository;
use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionEvidenceArchive;
use App\Services\Decision\DecisionEvidenceReplay;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\FrozenCustomScenario;
use App\Services\Ai\AiManager;
use App\Services\Decision\Analyst\AiManagerAnalystProvider;
use App\Services\Decision\Analyst\GroundedAnalystQa;
use App\Services\Decision\FrozenReviewQuestions;
use App\Services\Decision\IntegratedDecisionPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Owned original-source decision evidence. No caller models, source labels, synthetic data or campaign writes. */
final class DecisionEvidenceController extends Controller
{
    public function preview(Request $request)
    {
        if (array_diff(array_keys($request->all()), ['account_id', 'start_date', 'end_date', 'problem', 'custom'])) {
            return response()->json(['success' => false, 'error' => 'unknown_decision_evidence_input'], 422);
        }
        $v = $request->validate([
            'account_id' => 'required|integer|min:1', 'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'problem' => 'required|array', 'custom' => 'sometimes|array|max:40',
        ]);
        $account = ConnectedAdAccount::where('user_id', $request->user()->id)->findOrFail($v['account_id']);

        return DB::transaction(function () use ($request, $v, $account) {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            try {
                $source = (new RecordedDeliveryRepository)->latestForAccount($account, (int) $request->user()->id, $v['start_date'], $v['end_date']);
                if ($source === null) {
                    return response()->json(['success' => false, 'error' => 'owned_current_original_snapshot_unavailable'], 409);
                }
                $rows = [];
                foreach ($source['rows'] as $key => $row) {
                    $r = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'], 'scope' => $source['scope']];
                    foreach ($row['metrics'] as $metric => $value) {
                        $r[$metric] = $value['lexeme'];
                    }$rows[] = $r;
                }
                $ledger = new RecordedLedger($source['scope'], $rows);
                if ($ledger->jsonSerialize()['snapshot_id'] !== $source['snapshot_id']) {
                    throw new InvalidArgumentException('source_snapshot_reconstruction_mismatch');
                }
                $problem = new DecisionProblem($v['problem']);
                $p = $problem->jsonSerialize();
                if ($p['source_snapshot_id'] !== $source['snapshot_id']) {
                    throw new InvalidArgumentException('problem_source_snapshot_mismatch');
                }
                $ids = array_column($p['variables'], 'id');
                $sourceIds = array_keys($source['entity_totals']);
                if (array_diff($ids, $sourceIds) || array_diff($sourceIds, $ids)) {
                    throw new InvalidArgumentException('problem_source_population_mismatch');
                }
                foreach ($p['variables'] as $variable) {
                    if ($variable['platform'] !== 'google' || $variable['entity_level'] !== 'campaign' || $variable['id'] !== 'campaign:'.$variable['entity_id']) {
                        throw new InvalidArgumentException('problem_owned_source_identity_mismatch');
                    }
                }
                $result = (new IntegratedDecisionPipeline)->fromLedger($ledger, $problem, $v['custom'] ?? null);
                $id = (new DecisionEvidenceArchive)->save((int) $request->user()->id, $result);

                return response()->json(['success' => true, 'data' => ['id' => $id, 'evidence' => $result]]);
            } catch (InvalidArgumentException|\JsonException $e) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }
        });
    }

    public function index(Request $request)
    {
        $v = $request->validate(['before_id' => 'sometimes|integer|min:1', 'limit' => 'sometimes|integer|min:1|max:20']);
        $limit = (int) ($v['limit'] ?? 10);
        $rows = DB::table('decision_evidence_runs')->where('user_id', $request->user()->id)->when($v['before_id'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))->orderByDesc('id')->limit($limit + 1)->get(['id', 'pipeline_version', 'snapshot_id', 'problem_id', 'audit_hash', 'created_at']);
        $more = $rows->count() > $limit;
        $page = $rows->take($limit)->values();

        return response()->json(['success' => true, 'data' => ['rows' => $page, 'next_before_id' => $more ? $page->last()->id : null,
            'limits' => 'Stored evidence, including abstentions. Listing is not integrity verification, operational approval or a successful optimization claim. Open checks integrity; replay uses frozen inputs.']]);
    }

    public function show(Request $request, int $id)
    {
        try {
            $record = (new DecisionEvidenceArchive)->load((int) $request->user()->id, $id);
        } catch (InvalidArgumentException|\JsonException $e) {
            return response()->json(['success' => false, 'error' => 'owned_evidence_integrity_check_failed'], 409);
        }
        abort_if($record === null, 404);

        return response()->json(['success' => true, 'data' => ['id' => $id, 'evidence' => $record]]);
    }

    public function question(Request $request, int $id)
    {
        $v = $request->validate(['intent' => 'required|string|max:40']);
        try {
            $record = (new DecisionEvidenceArchive)->load((int) $request->user()->id, $id);
        } catch (InvalidArgumentException|\JsonException $e) {
            return response()->json(['success' => false, 'error' => 'owned_evidence_integrity_check_failed'], 409);
        }
        abort_if($record === null, 404);

        return response()->json(['success' => true, 'data' => (new FrozenReviewQuestions)->answer($record, $v['intent'])]);
    }

    /** Optional AI-assisted evidence selection: only on explicit request, with the owner's own configured model. */
    public function assist(Request $request, int $id)
    {
        $v = $request->validate(['question' => 'required|string|max:1000', 'use_ai' => 'required|boolean']);
        try {
            $record = (new DecisionEvidenceArchive)->load((int) $request->user()->id, $id);
        } catch (InvalidArgumentException|\JsonException $e) {
            return response()->json(['success' => false, 'error' => 'owned_evidence_integrity_check_failed'], 409);
        }
        abort_if($record === null, 404);
        if (! $v['use_ai']) {
            return response()->json(['success' => true, 'data' => ['status' => 'ai_not_requested', 'snapshot_id' => $record['snapshot_id'], 'external_ai_called' => false, 'intents' => array_keys(FrozenReviewQuestions::ROUTES)]]);
        }
        if (! \App\Models\AiConfig::where('user_id', $request->user()->id)->where('is_active', true)->exists()) {
            return response()->json(['success' => true, 'data' => ['status' => 'no_ai_config', 'snapshot_id' => $record['snapshot_id'], 'external_ai_called' => false, 'intents' => array_keys(FrozenReviewQuestions::ROUTES)]]);
        }
        $answer = (new GroundedAnalystQa(new AiManagerAnalystProvider(app(AiManager::class), $request->user())))->ask($record, $v['question']);

        return response()->json(['success' => true, 'data' => $answer]);
    }

    public function custom(Request $request, int $id)
    {
        if (array_diff(array_keys($request->all()), ['allocation'])) {
            return response()->json(['success' => false, 'error' => 'unknown_custom_scenario_input'], 422);
        }
        $v = $request->validate(['allocation' => 'required|array|min:1|max:100', 'allocation.*' => 'required|string|max:4096']);
        try {
            $record = (new DecisionEvidenceArchive)->load((int) $request->user()->id, $id);
        } catch (InvalidArgumentException|\JsonException) {
            return response()->json(['success' => false, 'error' => 'owned_evidence_integrity_check_failed'], 409);
        }
        abort_if($record === null, 404);
        try {
            return response()->json(['success' => true, 'data' => (new FrozenCustomScenario)->evaluate($record, $v['allocation'])]);
        } catch (InvalidArgumentException|\JsonException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function replay(Request $request, int $id)
    {
        try {
            $record = (new DecisionEvidenceArchive)->load((int) $request->user()->id, $id);
        } catch (InvalidArgumentException|\JsonException $e) {
            return response()->json(['success' => false, 'error' => 'owned_evidence_integrity_check_failed'], 409);
        }
        abort_if($record === null, 404);

        return response()->json(['success' => true, 'data' => (new DecisionEvidenceReplay)->replay($record)]);
    }
}
