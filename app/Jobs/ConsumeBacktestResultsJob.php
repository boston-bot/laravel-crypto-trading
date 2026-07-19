<?php

namespace App\Jobs;

use App\Models\BacktestRun;
use App\Models\BacktestRunMetric;
use App\Models\EngineResult;
use App\Models\ResearchManifest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConsumeBacktestResultsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        EngineResult::query()
            ->whereNull('consumed_at')
            ->where('result_kind', 'backtest')
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->each(fn (EngineResult $result) => $this->consume($result));
    }

    private function consume(EngineResult $result): void
    {
        DB::transaction(function () use ($result): void {
            $locked = EngineResult::query()->whereKey($result->id)->lockForUpdate()->first();
            if ($locked === null || $locked->consumed_at !== null) {
                return;
            }
            $job = $locked->job()->with(['experiment', 'candidate', 'universeVersion'])->firstOrFail();
            $run = $job->backtestRun()->first()
                ?? BacktestRun::query()->find((int) data_get($job->payload_json, 'backtest_run_id'));
            if ($run === null) {
                $locked->update(['consumed_at' => now()]);

                return;
            }

            $payload = (array) $locked->payload_json;
            $this->verifyLineage($job->payload_json, $payload, $locked->engine_version, $locked->manifest_hash);
            $manifest = ResearchManifest::query()->firstOrCreate(
                ['content_hash' => (string) ($payload['manifest_hash'] ?? $locked->manifest_hash)],
                [
                    'kind' => 'backtest',
                    'schema_version' => $locked->schema_version,
                    'source_window_start' => $run->timeframe_start,
                    'source_window_end' => $run->timeframe_end,
                    'row_count' => collect((array) data_get($payload, 'manifest.rows', []))
                        ->sum(fn ($counts): int => (int) ($counts['1h'] ?? 0)),
                    'inputs_json' => (array) ($payload['manifest'] ?? []),
                    'quality_json' => ['gate' => $payload['gate'] ?? [], 'point_in_time' => true],
                    'frozen_at' => now(),
                ],
            );
            $run->update([
                'research_manifest_id' => $manifest->id,
                'result_manifest_hash' => $manifest->content_hash,
                'run_completed_at' => now(),
                'status' => 'completed',
                'result_json' => $payload,
                'metadata_json' => array_merge((array) $run->metadata_json, [
                    'engine_job_id' => $job->id,
                    'manifest_hash' => $manifest->content_hash,
                    'lineage_verified' => true,
                ]),
            ]);

            $this->persistMap($run, 'oos_normal', 'aggregate', (array) ($payload['aggregate_metrics'] ?? []));
            $this->persistMap($run, 'oos_stressed', 'aggregate', (array) ($payload['stressed_metrics'] ?? []));
            foreach ((array) ($payload['folds'] ?? []) as $fold) {
                $foldNumber = (string) ($fold['fold'] ?? 'unknown');
                $this->persistMap($run, 'fold', $foldNumber.':normal', (array) ($fold['normal'] ?? []), ['fold' => $foldNumber, 'scenario' => 'normal']);
                $this->persistMap($run, 'fold', $foldNumber.':stressed', (array) ($fold['stressed'] ?? []), ['fold' => $foldNumber, 'scenario' => 'stressed']);
            }
            $this->persistDimensions($run, (array) ($payload['attribution'] ?? []));
            $this->persistDimensions($run, [
                'benchmark' => (array) ($payload['benchmarks'] ?? []),
                'cost' => (array) ($payload['cost_attribution'] ?? []),
                'holding_period' => (array) ($payload['holding_periods'] ?? []),
                'parameter_neighborhood' => (array) ($payload['parameter_neighborhood'] ?? []),
            ]);
            $locked->update(['consumed_at' => now()]);
        });
    }

    /** @param array<string, mixed> $jobPayload @param array<string, mixed> $resultPayload */
    private function verifyLineage(array $jobPayload, array $resultPayload, string $engineVersion, string $manifestHash): void
    {
        $expected = (array) ($jobPayload['lineage'] ?? []);
        $actual = (array) ($resultPayload['lineage'] ?? []);
        foreach (['strategy_hash', 'universe_hash', 'experiment_hash', 'execution_policy_hash', 'code_hash'] as $field) {
            if (! isset($expected[$field]) || ! hash_equals((string) $expected[$field], (string) ($actual[$field] ?? ''))) {
                throw new RuntimeException("Backtest result {$field} does not match its preregistered job.");
            }
        }
        if (! hash_equals((string) ($expected['engine_version'] ?? ''), $engineVersion)
            || ! hash_equals($engineVersion, (string) ($actual['engine_version'] ?? ''))) {
            throw new RuntimeException('Backtest result engine version does not match its preregistered job.');
        }
        if (! hash_equals($manifestHash, (string) ($resultPayload['manifest_hash'] ?? ''))
            || ! hash_equals($manifestHash, (string) ($actual['manifest_hash'] ?? ''))) {
            throw new RuntimeException('Backtest result manifest hash is inconsistent.');
        }
    }

    /** @param array<string, mixed> $values @param array<string, mixed> $context */
    private function persistMap(BacktestRun $run, string $group, string $dimension, array $values, array $context = []): void
    {
        foreach ($values as $name => $value) {
            if (is_numeric($value)) {
                BacktestRunMetric::query()->updateOrCreate(
                    [
                        'backtest_run_id' => $run->id,
                        'metric_name' => (string) $name,
                        'metric_group' => $group,
                        'dimension_key' => $dimension,
                    ],
                    ['metric_value' => (float) $value, 'context_json' => $context ?: null],
                );
            }
        }
    }

    /** @param array<string, mixed> $dimensions */
    private function persistDimensions(BacktestRun $run, array $dimensions): void
    {
        if (isset($dimensions['dimensions']) && is_array($dimensions['dimensions'])) {
            $dimensions = array_merge($dimensions, $dimensions['dimensions']);
            unset($dimensions['dimensions']);
        }
        foreach ($dimensions as $group => $members) {
            if (! is_array($members)) {
                continue;
            }
            foreach ($members as $member => $metrics) {
                if (is_numeric($metrics)) {
                    $this->persistMap($run, (string) $group, (string) $member, ['pnl:'.$member => $metrics], ['dimension' => $member]);
                } elseif (is_array($metrics)) {
                    foreach ($metrics as $name => $value) {
                        if (is_numeric($value)) {
                            $this->persistMap($run, (string) $group, (string) $member, [$name.':'.$member => $value], ['dimension' => $member]);
                        }
                    }
                }
            }
        }
    }
}
