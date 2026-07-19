<?php

namespace App\Jobs;

use App\Models\BacktestRun;
use App\Models\BacktestRunMetric;
use App\Models\EngineResult;
use App\Models\ResearchManifest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ConsumeBacktestResultsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        EngineResult::query()->whereNull('consumed_at')->where('result_kind', 'backtest')->orderBy('id')->limit(10)->get()
            ->each(function (EngineResult $result): void {
                DB::transaction(function () use ($result): void {
                    $locked = EngineResult::query()->whereKey($result->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->consumed_at !== null) {
                        return;
                    }
                    $job = $locked->job()->firstOrFail();
                    $run = BacktestRun::query()->find((int) data_get($job->payload_json, 'backtest_run_id'));
                    if ($run === null) {
                        $locked->update(['consumed_at' => now()]);

                        return;
                    }
                    $payload = (array) $locked->payload_json;
                    $manifest = ResearchManifest::query()->firstOrCreate(
                        ['content_hash' => (string) ($payload['manifest_hash'] ?? $locked->manifest_hash)],
                        [
                            'kind' => 'backtest', 'schema_version' => $locked->schema_version,
                            'source_window_start' => $run->timeframe_start, 'source_window_end' => $run->timeframe_end,
                            'row_count' => collect((array) data_get($payload, 'manifest.rows', []))->sum(fn ($counts) => (int) ($counts['1h'] ?? 0)),
                            'inputs_json' => (array) ($payload['manifest'] ?? []),
                            'quality_json' => ['gate' => $payload['gate'] ?? [], 'point_in_time' => true],
                            'frozen_at' => now(),
                        ],
                    );
                    $run->update([
                        'research_manifest_id' => $manifest->id, 'run_completed_at' => now(), 'status' => 'completed',
                        'result_json' => $payload,
                        'metadata_json' => array_merge((array) $run->metadata_json, ['engine_job_id' => $job->id, 'manifest_hash' => $manifest->content_hash]),
                    ]);
                    foreach ((array) ($payload['aggregate_metrics'] ?? []) as $name => $value) {
                        if (is_numeric($value)) {
                            BacktestRunMetric::query()->updateOrCreate(
                                ['backtest_run_id' => $run->id, 'metric_name' => $name, 'metric_group' => 'oos_normal'],
                                ['metric_value' => (float) $value],
                            );
                        }
                    }
                    foreach ((array) ($payload['stressed_metrics'] ?? []) as $name => $value) {
                        if (is_numeric($value)) {
                            BacktestRunMetric::query()->updateOrCreate(
                                ['backtest_run_id' => $run->id, 'metric_name' => $name, 'metric_group' => 'oos_stressed'],
                                ['metric_value' => (float) $value],
                            );
                        }
                    }
                    $locked->update(['consumed_at' => now()]);
                });
            });
    }
}
