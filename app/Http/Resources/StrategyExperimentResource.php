<?php

namespace App\Http\Resources;

use App\Enums\HoldoutStatus;
use App\Models\StrategyExperiment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StrategyExperiment */
class StrategyExperimentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $holdout = $this->holdoutInterval;
        $terminal = $holdout?->status instanceof HoldoutStatus && $holdout->status->isTerminal();
        $runs = $this->runs;
        $measured = $runs->where('status', 'completed')->count();
        $state = $runs->isEmpty() ? 'not_measured' : ($measured === $runs->count() ? 'measured' : 'incomplete');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status?->value ?? $this->status,
            'objective' => $this->objective,
            'evidence_level' => $holdout?->status?->value ?? 'development',
            'performance' => [
                'measurement_state' => $state,
                'measured_runs' => $measured,
                'total_runs' => $runs->count(),
                'costs_included' => true,
                'source' => 'preregistered_experiment',
                'stale' => false,
            ],
            'window' => [
                'development_start' => $this->development_start?->toIso8601String(),
                'development_end' => $this->development_end?->toIso8601String(),
                'holdout_start' => $this->holdout_start?->toIso8601String(),
                'holdout_end' => $this->holdout_end?->toIso8601String(),
            ],
            'universe' => $this->universeVersion ? [
                'id' => $this->universeVersion->id,
                'name' => $this->universeVersion->name,
                'version' => $this->universeVersion->version,
                'symbols' => $this->universeVersion->symbols_json,
            ] : null,
            'constraints' => $this->constraints_json,
            'policies' => [
                'cost' => $this->cost_policy_json,
                'attribution' => $this->attribution_policy_json,
                'benchmark' => $this->benchmark_policy_json,
                'execution_version' => $this->execution_policy_version,
                'execution_hash' => $this->execution_policy_hash,
            ],
            'candidates' => $this->candidates->map(fn ($candidate): array => [
                'id' => $candidate->id,
                'key' => $candidate->candidate_key,
                'family' => $candidate->family,
                'status' => $candidate->status,
                'role' => $candidate->strategyVersions->contains(fn ($version): bool => $version->version_role === 'final') ? 'champion' : 'challenger',
                'search_order' => $candidate->search_order,
                'search_budget' => $candidate->search_budget,
                'content_hash' => $candidate->content_hash,
                'rejection_reasons' => $candidate->runs->flatMap(fn ($run) => (array) data_get($run->result_json, 'gate.reasons', []))->unique()->values()->all(),
                'runs' => ResearchRunResource::collection($candidate->runs)->resolve($request),
            ])->values()->all(),
            'holdout' => $holdout ? [
                'status' => $holdout->status?->value ?? $holdout->status,
                'authorized_strategy_version_id' => $holdout->authorized_strategy_version_id,
                'authorized_at' => $holdout->authorized_at?->toIso8601String(),
                'revealed_at' => $holdout->revealed_at?->toIso8601String(),
                'terminal_at' => $holdout->terminal_at?->toIso8601String(),
                'result' => $terminal ? $holdout->terminal_result_json : null,
                'values_revealed' => $terminal,
            ] : ['status' => 'not_registered', 'result' => null, 'values_revealed' => false],
            'lineage' => [
                'schema_version' => $this->schema_version,
                'content_hash' => $this->content_hash,
            ],
        ];
    }
}
