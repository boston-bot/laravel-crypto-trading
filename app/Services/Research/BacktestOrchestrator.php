<?php

namespace App\Services\Research;

use App\Enums\EvaluationStage;
use App\Models\BacktestRun;
use App\Models\BacktestRunMetric;
use App\Models\StrategyParameter;

class BacktestOrchestrator
{
    /**
     * @param  array<string, float|int|string>  $metrics
     */
    public function recordRun(
        string $strategyName,
        array $metrics = [],
        ?StrategyParameter $parameter = null,
        EvaluationStage $stage = EvaluationStage::Development,
    ): BacktestRun {
        $run = BacktestRun::query()->create([
            'strategy_name' => $strategyName,
            'strategy_parameter_id' => $parameter?->id,
            'run_started_at' => now(),
            'run_completed_at' => now(),
            'status' => 'completed',
            'trigger' => 'manual',
            'evaluation_stage' => $stage,
            'metadata_json' => [
                'source' => 'strategy-v1',
            ],
        ]);

        foreach ($metrics as $metricName => $metricValue) {
            if (! is_numeric($metricValue)) {
                continue;
            }

            BacktestRunMetric::query()->create([
                'backtest_run_id' => $run->id,
                'metric_name' => (string) $metricName,
                'metric_group' => 'portfolio',
                'dimension_key' => 'aggregate',
                'metric_value' => (float) $metricValue,
            ]);
        }

        return $run;
    }
}
