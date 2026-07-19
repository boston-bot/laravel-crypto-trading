<?php

namespace App\Http\Controllers;

use App\Enums\TradingDecisionStatus;
use App\Jobs\SubmitBrokerOrderJob;
use App\Models\TradeDecision;
use App\Services\Execution\ApprovalRevalidationService;
use App\Services\Operations\OperatorActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradeDecisionApprovalController extends Controller
{
    public function indexPending(): JsonResponse
    {
        $pending = TradeDecision::query()
            ->with(['asset', 'strategyRun'])
            ->where('status', TradingDecisionStatus::AWAITING_HUMAN_APPROVAL->value)
            ->latest()
            ->get();

        return response()->json([
            'data' => $pending,
        ]);
    }

    public function approve(Request $request, TradeDecision $tradeDecision, ApprovalRevalidationService $revalidation, OperatorActionService $actions): JsonResponse
    {
        if ((string) config('broker.mode', 'paper') === 'live' && ! (bool) config('operations.live_console_actions_enabled', false)) {
            return response()->json(['message' => 'Live approvals remain disabled until authentication and approver roles are installed.'], 403);
        }
        if ($tradeDecision->status !== TradingDecisionStatus::AWAITING_HUMAN_APPROVAL) {
            return response()->json([
                'message' => 'Trade decision is not awaiting approval.',
            ], 422);
        }

        $action = $actions->queue('approve_paper_decision', (string) $request->input('idempotency_key', hash('sha256', 'approve|'.$tradeDecision->id.'|'.$tradeDecision->updated_at)), ['trade_decision_id' => $tradeDecision->id], 'trade_decision', $tradeDecision->id);

        $result = $revalidation->refreshAndEvaluate($tradeDecision);
        if (! $result->passed) {
            $tradeDecision->update([
                'status' => TradingDecisionStatus::BLOCKED_BY_POLICY->value,
                'policy_result_json' => array_merge((array) $tradeDecision->policy_result_json, [
                    'approval_revalidation' => ['passed' => false, 'reasons' => $result->reasons, 'context' => $result->context, 'checked_at' => now()->toIso8601String()],
                ]),
            ]);
            $action->update(['status' => 'failed', 'last_error' => implode(' ', $result->reasons), 'completed_at' => now()]);

            return response()->json([
                'message' => 'Trade decision failed approval revalidation.',
                'reasons' => $result->reasons,
                'data' => $tradeDecision->fresh(),
            ], 422);
        }

        $actor = (string) $request->input('actor', 'api');
        $tradeDecision->update([
            'status' => TradingDecisionStatus::APPROVED->value,
            'approved_by' => $actor,
            'approved_at' => now(),
            'policy_result_json' => array_merge((array) $tradeDecision->policy_result_json, [
                'approval_revalidation' => ['passed' => true, 'context' => $result->context, 'checked_at' => now()->toIso8601String()],
            ]),
        ]);

        SubmitBrokerOrderJob::dispatch($tradeDecision->id);
        $action->update(['status' => 'completed', 'completed_at' => now(), 'result_json' => ['trade_decision_id' => $tradeDecision->id, 'status' => 'approved']]);

        return response()->json([
            'message' => 'Trade decision approved.',
            'data' => $tradeDecision->fresh(),
        ]);
    }

    public function reject(Request $request, TradeDecision $tradeDecision, OperatorActionService $actions): JsonResponse
    {
        if ((string) config('broker.mode', 'paper') === 'live' && ! (bool) config('operations.live_console_actions_enabled', false)) {
            return response()->json(['message' => 'Live approvals remain disabled until authentication and approver roles are installed.'], 403);
        }
        if ($tradeDecision->status !== TradingDecisionStatus::AWAITING_HUMAN_APPROVAL) {
            return response()->json([
                'message' => 'Trade decision is not awaiting approval.',
            ], 422);
        }

        $tradeDecision->update([
            'status' => TradingDecisionStatus::REJECTED->value,
            'policy_result_json' => array_merge($tradeDecision->policy_result_json ?? [], [
                'rejected_by' => (string) $request->input('actor', 'api'),
                'rejected_at' => now()->toIso8601String(),
                'rejection_reason' => (string) $request->input('reason', 'manual rejection'),
            ]),
        ]);
        $action = $actions->queue('reject_paper_decision', (string) $request->input('idempotency_key', hash('sha256', 'reject|'.$tradeDecision->id.'|'.$tradeDecision->updated_at)), ['trade_decision_id' => $tradeDecision->id], 'trade_decision', $tradeDecision->id);
        $action->update(['status' => 'completed', 'completed_at' => now(), 'result_json' => ['trade_decision_id' => $tradeDecision->id, 'status' => 'rejected']]);

        return response()->json([
            'message' => 'Trade decision rejected.',
            'data' => $tradeDecision->fresh(),
        ]);
    }
}
