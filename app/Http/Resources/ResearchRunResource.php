<?php

namespace App\Http\Resources;

use App\Models\BacktestRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BacktestRun */
class ResearchRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metrics = $this->metrics->groupBy('metric_group')->map(fn ($group) => $group->map(fn ($metric): array => [
            'name' => $metric->metric_name,
            'dimension' => $metric->dimension_key,
            'value' => $metric->metric_value !== null ? (float) $metric->metric_value : null,
            'context' => $metric->context_json,
        ])->values()->all())->all();
        $state = match ($this->status) {
            'completed' => 'measured',
            'failed' => 'incomplete',
            default => 'not_measured',
        };

        return [
            'id' => $this->id,
            'experiment_id' => $this->strategy_experiment_id,
            'candidate_id' => $this->strategy_experiment_candidate_id,
            'candidate_key' => $this->candidate?->candidate_key,
            'family' => $this->candidate?->family,
            'stage' => $this->evaluation_stage?->value ?? $this->evaluation_stage,
            'status' => $this->status,
            'window' => [
                'start' => $this->timeframe_start?->toIso8601String(),
                'end' => $this->timeframe_end?->toIso8601String(),
            ],
            'performance' => [
                'measurement_state' => $state,
                'measured_at' => $this->run_completed_at?->toIso8601String(),
                'costs_included' => true,
                'source' => 'canonical_backtest_result',
                'stale' => false,
            ],
            'metrics' => [
                'aggregate' => $metrics['aggregate'] ?? [],
                'stressed' => $metrics['stressed'] ?? [],
                'folds' => $metrics['fold'] ?? [],
                'robustness' => $metrics['robustness'] ?? [],
                'costs' => $metrics['cost'] ?? [],
                'benchmarks' => $metrics['benchmark'] ?? [],
                'attribution' => [
                    'asset' => $metrics['asset'] ?? [],
                    'regime' => $metrics['regime'] ?? [],
                    'holding_period' => $metrics['holding_period'] ?? [],
                    'exit' => $metrics['exit'] ?? [],
                ],
            ],
            'gate' => data_get($this->result_json, 'gate', ['status' => $state === 'measured' ? 'unknown' : $state, 'reasons' => []]),
            'lineage' => [
                'strategy_version_id' => $this->strategy_version_id,
                'universe_version_id' => $this->universe_version_id,
                'research_manifest_id' => $this->research_manifest_id,
                'result_manifest_hash' => $this->result_manifest_hash,
                'execution_policy_hash' => $this->execution_policy_hash,
            ],
        ];
    }
}
