<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\BacktestRun;
use App\Models\EngineJob;
use App\Models\StrategyVersion;
use App\Models\UniverseVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunResearchBacktestCommand extends Command
{
    protected $signature = 'research:backtest {--start=} {--end=} {--capital=100000} {--seed=7}';

    protected $description = 'Enqueue an immutable walk-forward portfolio backtest';

    public function handle(): int
    {
        $start = $this->option('start') ? now('UTC')->parse((string) $this->option('start')) : now('UTC')->subYears(5)->startOfDay();
        $end = $this->option('end') ? now('UTC')->parse((string) $this->option('end')) : now('UTC')->startOfDay();
        $definition = ['trading' => config('trading'), 'research_gate' => config('research.backtest_gate')];
        $strategyHash = hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
        $strategy = StrategyVersion::query()->firstOrCreate(
            ['content_hash' => $strategyHash],
            ['name' => (string) config('trading.strategy_name'), 'version' => 'python-v1', 'engine_version' => (string) config('research.engine.version'), 'status' => 'research', 'definition_json' => $definition],
        );
        $symbols = (array) config('research.universe');
        $universeHash = hash('sha256', json_encode($symbols, JSON_THROW_ON_ERROR));
        $universe = UniverseVersion::query()->firstOrCreate(
            ['content_hash' => $universeHash],
            ['name' => 'coinbase-core-spot', 'version' => 'v1', 'status' => 'research', 'symbols_json' => $symbols, 'rules_json' => ['long_only' => true, 'quote' => 'USD']],
        );
        $assets = Asset::query()->where('broker', 'coinbase')->whereIn('symbol', $symbols)->orderBy('symbol')->get(['id', 'symbol']);
        if ($assets->count() < 3) {
            $this->error('At least three Coinbase universe assets must exist before backtesting.');

            return self::FAILURE;
        }
        $spec = [
            'strategy_version' => $strategy->version, 'universe_version' => $universe->version,
            'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(),
            'initial_capital' => (float) $this->option('capital'), 'random_seed' => (int) $this->option('seed'),
            'train_months' => 24, 'validation_months' => 6, 'test_months' => 6, 'step_months' => 6,
            'embargo_days' => 30, 'holdout_months' => 12, 'fee_bps' => 60, 'spread_bps' => 10, 'slippage_bps' => 8,
        ];
        $run = BacktestRun::query()->create([
            'strategy_name' => $strategy->name, 'strategy_version_id' => $strategy->id, 'universe_version_id' => $universe->id,
            'run_started_at' => now(), 'timeframe_start' => $start, 'timeframe_end' => $end,
            'status' => 'queued', 'trigger' => 'artisan', 'spec_json' => $spec, 'holdout_locked' => true,
        ]);
        $payload = ['backtest_run_id' => $run->id, 'assets' => $assets->toArray(), 'spec' => $spec];
        $key = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $job = EngineJob::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'id' => (string) Str::uuid(), 'schema_version' => (string) config('research.engine.schema_version'),
                'kind' => 'backtest', 'strategy_version_id' => $strategy->id, 'universe_version_id' => $universe->id,
                'as_of' => $end, 'payload_json' => $payload, 'status' => 'pending',
                'max_attempts' => (int) config('research.engine.max_attempts', 3),
            ],
        );
        $run->update(['metadata_json' => ['engine_job_id' => $job->id]]);
        $this->info('Backtest '.$run->id.' queued as engine job '.$job->id.'.');

        return self::SUCCESS;
    }
}
