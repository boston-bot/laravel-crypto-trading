<?php

namespace App\Http\Resources;

use App\Models\AssetEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetEvaluation */
class DecisionInspectorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $trace = (array) $this->decision_trace_json;
        $decision = $this->decision;
        $order = $decision?->brokerOrder;
        $logicalBar = $this->logical_bar_close;
        $blockers = array_values(array_unique(array_filter([
            $this->action_suppression_reason,
            ...((array) $this->reason_codes_json),
            ...((array) $this->warnings_json),
        ])));

        return [
            'id' => $this->id,
            'asset' => $this->asset ? ['id' => $this->asset->id, 'symbol' => $this->asset->symbol] : null,
            'cycle_id' => $this->pipeline_cycle_id,
            'logical_bar_close' => $logicalBar?->toIso8601String(),
            'performance' => [
                'measurement_state' => $this->decision_trace_hash ? 'measured' : 'incomplete',
                'measured_at' => $this->as_of?->toIso8601String(),
                'source' => 'canonical_decision_trace',
                'stale' => $logicalBar?->lt(now()->subHours(8)) ?? true,
            ],
            'immediate' => [
                'action' => $this->action,
                'explanation' => $this->primary_explanation,
                'eligible' => (bool) $this->eligible,
                'actionable' => (bool) $this->actionable,
                'resolution' => $this->evaluation_resolution,
                'strategy_family' => $this->strategy_family,
                'strategy_definition_version' => $this->strategy_definition_version,
                'position_impact' => $this->portfolio_target_json,
                'cash_impact' => data_get($this->portfolio_target_json, 'cash_weight'),
                'order_intent' => $this->order_intent_json,
            ],
            'mechanics' => [
                'rules' => array_values((array) ($trace['rule_checklist'] ?? [])),
                'rank' => $trace['rank'] ?? null,
                'score' => $this->score !== null ? (float) $this->score : null,
                'calibrated_probability' => $this->calibrated_probability !== null ? (float) $this->calibrated_probability : null,
                'gross_edge_bps' => isset($trace['gross_edge_bps']) ? (float) $trace['gross_edge_bps'] : null,
                'cost_estimate_bps' => isset($trace['cost_estimate_bps']) ? (float) $trace['cost_estimate_bps'] : null,
                'net_edge_bps' => $this->expected_value_bps !== null ? (float) $this->expected_value_bps : (isset($trace['net_edge_bps']) ? (float) $trace['net_edge_bps'] : null),
                'regime' => data_get($trace, 'portfolio_state.regime'),
                'capacity' => data_get($trace, 'portfolio_state.capacity'),
                'blockers' => $blockers,
                'counterfactual' => $trace['counterfactual'] ?? null,
            ],
            'audit' => [
                'factor_contributions' => (array) ($trace['factor_contributions'] ?? $this->factor_attribution_json ?? []),
                'thresholds' => (array) ($this->thresholds_json ?? []),
                'portfolio_state' => (array) ($trace['portfolio_state'] ?? []),
                'timestamps' => [
                    'logical_bar_close' => $logicalBar?->toIso8601String(),
                    'as_of' => $this->as_of?->toIso8601String(),
                    'suppressed_at' => $this->action_suppressed_at?->toIso8601String(),
                ],
                'lineage' => [
                    'strategy_version_id' => $this->strategy_version_id,
                    'universe_version_id' => $this->universe_version_id,
                    'decision_trace_hash' => $this->decision_trace_hash,
                    'evidence_hash' => $this->evidence_hash,
                    'parameter_hash' => $this->parameter_hash,
                    'candle_evidence' => $this->candle_evidence_json,
                ],
                'decision' => $decision ? [
                    'id' => $decision->id,
                    'status' => $decision->status?->value ?? $decision->status,
                    'risk' => $decision->risk_context_json,
                    'policy' => $decision->policy_result_json,
                    'policy_checks' => $decision->policyChecks->map(fn ($check): array => [
                        'name' => $check->policy_name,
                        'passed' => (bool) $check->result,
                        'message' => $check->message,
                        'checked_at' => $check->checked_at?->toIso8601String(),
                    ])->values()->all(),
                ] : null,
                'execution' => $order ? [
                    'id' => $order->id,
                    'status' => $order->status?->value ?? $order->status,
                    'requested_notional' => $order->requested_notional !== null ? (float) $order->requested_notional : null,
                    'filled_notional' => $order->filled_notional !== null ? (float) $order->filled_notional : null,
                    'fee_amount' => $order->fee_amount !== null ? (float) $order->fee_amount : null,
                    'filled_at' => $order->filled_at?->toIso8601String(),
                ] : null,
                'cycle_steps' => $this->cycle?->steps?->map(fn ($step): array => [
                    'key' => $step->step_key,
                    'status' => $step->status,
                    'reason' => $step->reason,
                ])->values()->all() ?? [],
            ],
        ];
    }
}
