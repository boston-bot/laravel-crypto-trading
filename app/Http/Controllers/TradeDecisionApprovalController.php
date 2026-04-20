<?php

namespace App\Http\Controllers;

use App\Enums\TradingDecisionStatus;
use App\Jobs\SubmitBrokerOrderJob;
use App\Models\TradeDecision;
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

    public function approve(Request $request, TradeDecision $tradeDecision): JsonResponse
    {
        if ($tradeDecision->status !== TradingDecisionStatus::AWAITING_HUMAN_APPROVAL) {
            return response()->json([
                'message' => 'Trade decision is not awaiting approval.',
            ], 422);
        }

        $actor = (string) $request->input('actor', 'api');
        $tradeDecision->update([
            'status' => TradingDecisionStatus::APPROVED->value,
            'approved_by' => $actor,
            'approved_at' => now(),
        ]);

        SubmitBrokerOrderJob::dispatch($tradeDecision->id);

        return response()->json([
            'message' => 'Trade decision approved.',
            'data' => $tradeDecision->fresh(),
        ]);
    }

    public function reject(Request $request, TradeDecision $tradeDecision): JsonResponse
    {
        if ($tradeDecision->status !== TradingDecisionStatus::AWAITING_HUMAN_APPROVAL) {
            return response()->json([
                'message' => 'Trade decision is not awaiting approval.',
            ], 422);
        }

        $tradeDecision->update([
            'status' => TradingDecisionStatus::BLOCKED_BY_POLICY->value,
            'policy_result_json' => array_merge($tradeDecision->policy_result_json ?? [], [
                'rejected_by' => (string) $request->input('actor', 'api'),
                'rejected_at' => now()->toIso8601String(),
                'rejection_reason' => (string) $request->input('reason', 'manual rejection'),
            ]),
        ]);

        return response()->json([
            'message' => 'Trade decision rejected.',
            'data' => $tradeDecision->fresh(),
        ]);
    }
}

