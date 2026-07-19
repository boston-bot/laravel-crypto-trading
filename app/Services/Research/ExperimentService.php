<?php

namespace App\Services\Research;

use App\Enums\EvaluationStage;
use App\Enums\ExperimentStatus;
use App\Models\BacktestRun;
use App\Models\EngineJob;
use App\Models\HoldoutInterval;
use App\Models\StrategyExperiment;
use App\Models\UniverseVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ExperimentService
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): StrategyExperiment
    {
        $universe = UniverseVersion::query()->findOrFail((int) $input['universe_version_id']);
        $developmentStart = CarbonImmutable::parse((string) $input['development_start'])->utc();
        $developmentEnd = CarbonImmutable::parse((string) $input['development_end'])->utc();
        $holdoutStart = CarbonImmutable::parse((string) $input['holdout_start'])->utc();
        $holdoutEnd = CarbonImmutable::parse((string) $input['holdout_end'])->utc();
        if (! ($developmentStart->lt($developmentEnd) && $developmentEnd->lte($holdoutStart) && $holdoutStart->lt($holdoutEnd))) {
            throw new InvalidArgumentException('Development and holdout intervals must be ordered, non-overlapping half-open intervals.');
        }

        $families = array_values((array) ($input['families'] ?? config('research.experiments.families')));
        $seeds = array_values(array_map('intval', (array) ($input['seeds'] ?? [7])));
        $executionPolicy = (array) config('research.experiments.execution_policy');
        $executionPolicyHash = $this->canonicalHash($executionPolicy);
        $searchBudgetPerFamily = (int) ($input['search_budget_per_family'] ?? config('research.experiments.search_budget_per_family', 24));
        $experimentDefinition = [
            'schema_version' => '1.0',
            'name' => (string) $input['name'],
            'universe_hash' => $universe->content_hash,
            'objective' => (string) config('research.experiments.objective'),
            'constraints' => $this->constraints(),
            'search_budget' => $searchBudgetPerFamily * count($families),
            'seeds' => $seeds,
            'regimes' => (array) config('research.experiments.regimes'),
            'cost_policy' => (array) config('research.experiments.cost_policy'),
            'attribution_policy' => (array) config('research.experiments.attribution_policy'),
            'benchmark_policy' => (array) config('research.experiments.benchmark_policy'),
            'execution_policy' => $executionPolicy,
            'development_interval' => [$developmentStart->toIso8601String(), $developmentEnd->toIso8601String()],
            'holdout_interval' => [$holdoutStart->toIso8601String(), $holdoutEnd->toIso8601String()],
            'families' => $families,
        ];
        $experimentHash = $this->canonicalHash($experimentDefinition);

        return DB::transaction(function () use ($input, $universe, $experimentDefinition, $experimentHash, $executionPolicy, $executionPolicyHash, $developmentStart, $developmentEnd, $holdoutStart, $holdoutEnd, $seeds, $families, $searchBudgetPerFamily): StrategyExperiment {
            $existing = StrategyExperiment::query()->where('content_hash', $experimentHash)->first();
            if ($existing !== null) {
                if (! $existing->holdoutInterval()->exists()) {
                    HoldoutInterval::query()->create([
                        'strategy_experiment_id' => $existing->id,
                        'holdout_start' => $existing->holdout_start,
                        'holdout_end' => $existing->holdout_end,
                        'status' => 'locked',
                        'content_hash' => $this->canonicalHash([
                            'schema_version' => '1.0',
                            'experiment_hash' => $existing->content_hash,
                            'holdout_start' => $existing->holdout_start->toIso8601String(),
                            'holdout_end' => $existing->holdout_end->toIso8601String(),
                        ]),
                    ]);
                }

                return $existing->load(['candidates', 'runs.engineJob', 'holdoutInterval']);
            }

            $experiment = StrategyExperiment::query()->create([
                'schema_version' => '1.0',
                'name' => (string) $input['name'],
                'status' => ExperimentStatus::Queued,
                'universe_version_id' => $universe->id,
                'objective' => $experimentDefinition['objective'],
                'constraints_json' => $experimentDefinition['constraints'],
                'search_budget' => $experimentDefinition['search_budget'],
                'seeds_json' => $seeds,
                'regimes_json' => $experimentDefinition['regimes'],
                'cost_policy_json' => $experimentDefinition['cost_policy'],
                'attribution_policy_json' => $experimentDefinition['attribution_policy'],
                'benchmark_policy_json' => $experimentDefinition['benchmark_policy'],
                'execution_policy_version' => (string) $executionPolicy['version'],
                'execution_policy_hash' => $executionPolicyHash,
                'development_start' => $developmentStart,
                'development_end' => $developmentEnd,
                'holdout_start' => $holdoutStart,
                'holdout_end' => $holdoutEnd,
                'content_hash' => $experimentHash,
            ]);
            HoldoutInterval::query()->create([
                'strategy_experiment_id' => $experiment->id,
                'holdout_start' => $holdoutStart,
                'holdout_end' => $holdoutEnd,
                'status' => 'locked',
                'content_hash' => $this->canonicalHash([
                    'schema_version' => '1.0',
                    'experiment_hash' => $experimentHash,
                    'holdout_start' => $holdoutStart->toIso8601String(),
                    'holdout_end' => $holdoutEnd->toIso8601String(),
                ]),
            ]);

            foreach ($families as $index => $family) {
                $specification = $this->candidateSpecification((string) $family, $searchBudgetPerFamily, $seeds);
                $candidate = $experiment->candidates()->create([
                    'candidate_key' => sprintf('%02d-%s', $index + 1, $family),
                    'family' => $family,
                    'search_order' => $index + 1,
                    'search_budget' => $searchBudgetPerFamily,
                    'status' => 'queued',
                    'specification_json' => $specification,
                    'content_hash' => $this->canonicalHash($specification),
                ]);
                $run = BacktestRun::query()->create([
                    'strategy_name' => (string) $family,
                    'universe_version_id' => $universe->id,
                    'strategy_experiment_id' => $experiment->id,
                    'strategy_experiment_candidate_id' => $candidate->id,
                    'evaluation_stage' => EvaluationStage::Development,
                    'execution_policy_hash' => $executionPolicyHash,
                    'run_started_at' => now(),
                    'timeframe_start' => $developmentStart,
                    'timeframe_end' => $developmentEnd,
                    'status' => 'queued',
                    'trigger' => 'experiment',
                    'spec_json' => [
                        'candidate_specification' => $specification,
                        'strategy_version' => sprintf('%s-candidate-v1', $family),
                        'universe_version' => $universe->version,
                        'strategy_definition' => $specification['resolved_baseline'],
                        'start' => $developmentStart->toIso8601String(),
                        'end' => $developmentEnd->toIso8601String(),
                        'initial_capital' => (float) ($input['initial_capital'] ?? 100_000),
                        'random_seed' => $seeds[0] ?? 7,
                        'train_months' => 24,
                        'validation_months' => 6,
                        'test_months' => 6,
                        'step_months' => 6,
                        'embargo_days' => 30,
                        'holdout_months' => 12,
                        'fee_bps' => 60,
                        'spread_bps' => 10,
                        'slippage_bps' => 8,
                    ],
                    'holdout_locked' => true,
                ]);
                $lineage = [
                    'engine_version' => (string) config('research.engine.version'),
                    'strategy_hash' => $candidate->content_hash,
                    'universe_hash' => $universe->content_hash,
                    'experiment_hash' => $experiment->content_hash,
                    'execution_policy_hash' => $executionPolicyHash,
                    'code_hash' => hash('sha256', (string) config('research.engine.version')),
                ];
                $payload = [
                    'backtest_run_id' => $run->id,
                    'experiment_id' => $experiment->id,
                    'candidate_id' => $candidate->id,
                    'assets' => array_values((array) $input['assets']),
                    'spec' => $run->spec_json,
                    'lineage' => $lineage,
                ];
                EngineJob::query()->create([
                    'id' => (string) Str::uuid(),
                    'schema_version' => (string) config('research.engine.schema_version'),
                    'kind' => 'backtest',
                    'universe_version_id' => $universe->id,
                    'backtest_run_id' => $run->id,
                    'strategy_experiment_id' => $experiment->id,
                    'strategy_experiment_candidate_id' => $candidate->id,
                    'idempotency_key' => $this->canonicalHash($payload),
                    'as_of' => $developmentEnd,
                    'payload_json' => $payload,
                    'status' => 'pending',
                    'max_attempts' => (int) config('research.engine.max_attempts', 3),
                ]);
            }

            return $experiment->load(['candidates', 'runs.engineJob', 'holdoutInterval']);
        });
    }

    /** @return array<string, mixed> */
    private function constraints(): array
    {
        $gate = (array) config('research.backtest_gate');

        return [
            'maximum_drawdown_pct' => 15.0,
            'minimum_complete_folds' => max(4, (int) ($gate['minimum_complete_folds'] ?? 4)),
            'minimum_oos_trades' => (int) ($gate['min_oos_trades'] ?? 100),
            'minimum_assets' => (int) ($gate['min_assets'] ?? 3),
            'holdout_months' => 12,
        ];
    }

    /** @return array<string, mixed> */
    private function candidateSpecification(string $family, int $searchBudget, array $seeds): array
    {
        $baselineParameters = [
            'entry_score' => 0.25,
            'exit_score' => -0.10,
            'minimum_net_edge_bps' => 12.0,
            'cost_estimate_bps' => 8.0,
            'max_positions' => 3,
            'max_position_weight' => 0.40,
            'max_gross_weight' => 1.0,
            'entry_confirmation_bars' => 2,
            'exit_confirmation_bars' => 1,
            'cooldown_bars' => 2,
            'minimum_hold_days' => 2,
            'maximum_hold_days' => 21,
        ];

        return [
            'schema_version' => '1.0',
            'family' => $family,
            'feature_set' => 'canonical-point-in-time-v2',
            'calibration' => 'nested-anchored-walk-forward-v1',
            'search_budget' => $searchBudget,
            'seeds' => $seeds,
            'parameter_space' => [
                'entry_score' => [0.15, 0.35],
                'exit_score' => [-0.20, 0.0],
                'minimum_net_edge_bps' => [8, 20],
                'maximum_hold_days' => [14, 21],
            ],
            'resolved_baseline' => [
                'schema_version' => '2.0',
                'version' => '2.0.0',
                'family' => $family,
                'parameters' => $baselineParameters,
            ],
            'selection_rule' => 'maximum_validation_compounded_net_return_subject_to_all_constraints',
        ];
    }

    private function canonicalHash(array $value): string
    {
        $this->sortRecursively($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursively(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursively($child);
            }
        }
    }
}
