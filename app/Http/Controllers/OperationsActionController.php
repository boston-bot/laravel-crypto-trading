<?php

namespace App\Http\Controllers;

use App\Http\Requests\RequestPipelineCycleRequest;
use App\Http\Requests\RuntimeActionRequest;
use App\Http\Requests\StartPaperSessionRequest;
use App\Jobs\SyncOperationsDataJob;
use App\Models\BrokerAccount;
use App\Models\PaperSession;
use App\Services\Operations\OperatorActionService;
use App\Services\Operations\PipelineCycleService;
use App\Services\Operations\RuntimeControlService;
use App\Services\PaperTrading\PaperSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OperationsActionController extends Controller
{
    public function cycle(RequestPipelineCycleRequest $request, PipelineCycleService $cycles, OperatorActionService $actions): JsonResponse
    {
        $values = $request->validated();
        $account = BrokerAccount::query()->findOrFail((int) $values['broker_account_id']);
        $action = $actions->queue('pipeline_cycle', $values['idempotency_key'], $values, 'broker_account', $account->id);
        $cycle = $cycles->request($account, $values['trigger'], 'paper', $values['idempotency_key']);
        $action->update(['status' => 'completed', 'target_type' => 'pipeline_cycle', 'target_id' => $cycle->id, 'completed_at' => now(), 'result_json' => ['cycle_id' => $cycle->id]]);

        return response()->json(['message' => 'Paper pipeline cycle queued.', 'data' => ['action' => $action->fresh(), 'cycle' => $cycle]], 202);
    }

    public function sync(Request $request, OperatorActionService $actions): JsonResponse
    {
        $values = $request->validate(['idempotency_key' => ['required', 'string', 'max:128']]);
        $action = $actions->queue('sync_data', $values['idempotency_key'], $values);
        if ($action->wasRecentlyCreated) {
            SyncOperationsDataJob::dispatch($action->id)->afterCommit();
        }

        return response()->json(['message' => 'Data synchronization queued.', 'data' => $action], 202);
    }

    public function startPaperSession(StartPaperSessionRequest $request, PaperSessionService $sessions, OperatorActionService $actions): JsonResponse
    {
        $values = $request->validated();
        $action = $actions->queue('start_paper_session', $values['idempotency_key'], $values, 'broker_account', $values['broker_account_id']);
        if (! $action->wasRecentlyCreated && $action->status === 'completed') {
            return response()->json(['message' => 'Paper session was already created.', 'data' => $action], 202);
        }

        try {
            $session = $sessions->start(
                BrokerAccount::query()->findOrFail((int) $values['broker_account_id']),
                $values['funding_mode'],
                isset($values['virtual_capital']) ? (float) $values['virtual_capital'] : null,
                isset($values['strategy_version_id']) ? (int) $values['strategy_version_id'] : null,
                isset($values['universe_version_id']) ? (int) $values['universe_version_id'] : null,
                (bool) ($values['evidence_eligible'] ?? false),
            );
            $action->update(['status' => 'completed', 'target_type' => 'paper_session', 'target_id' => (string) $session->id, 'result_json' => ['paper_session_id' => $session->id], 'completed_at' => now()]);

            return response()->json(['message' => 'Paper session started.', 'data' => ['action' => $action->fresh(), 'session' => $session]], 201);
        } catch (RuntimeException $exception) {
            $action->update(['status' => 'failed', 'last_error' => $exception->getMessage(), 'completed_at' => now()]);

            return response()->json(['message' => $exception->getMessage(), 'data' => $action->fresh()], 422);
        }
    }

    public function endPaperSession(Request $request, PaperSession $paperSession, PaperSessionService $sessions, OperatorActionService $actions): JsonResponse
    {
        $values = $request->validate(['idempotency_key' => ['required', 'string', 'max:128']]);
        $action = $actions->queue('end_paper_session', $values['idempotency_key'], $values, 'paper_session', $paperSession->id);
        try {
            $closed = $sessions->end($paperSession);
            $action->update(['status' => 'completed', 'completed_at' => now(), 'result_json' => ['paper_session_id' => $closed->id]]);

            return response()->json(['message' => 'Paper session ended.', 'data' => ['action' => $action->fresh(), 'session' => $closed]]);
        } catch (RuntimeException $exception) {
            $action->update(['status' => 'failed', 'last_error' => $exception->getMessage(), 'completed_at' => now()]);

            return response()->json(['message' => $exception->getMessage(), 'data' => $action->fresh()], 422);
        }
    }

    public function runtime(RuntimeActionRequest $request, RuntimeControlService $runtime): JsonResponse
    {
        $values = $request->validated();
        $control = $runtime->request($values['process_name'], $values['action'], $values['idempotency_key']);

        return response()->json(['message' => ucfirst($values['action']).' request queued.', 'data' => $control], 202);
    }
}
