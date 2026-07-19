<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\UniverseVersion;
use App\Services\Research\ExperimentService;
use Illuminate\Console\Command;

class RunResearchBacktestCommand extends Command
{
    protected $signature = 'research:backtest {--start=} {--end=} {--capital=100000} {--seed=7} {--name=}';

    protected $description = 'Preregister a champion/challenger experiment and enqueue its development candidates';

    public function handle(ExperimentService $experiments): int
    {
        $developmentStart = $this->option('start')
            ? now('UTC')->parse((string) $this->option('start'))
            : now('UTC')->subYears(8)->startOfDay();
        $holdoutEnd = $this->option('end')
            ? now('UTC')->parse((string) $this->option('end'))
            : now('UTC')->startOfDay();
        $holdoutStart = $holdoutEnd->copy()->subMonthsNoOverflow(12);
        $developmentEnd = $holdoutStart->copy();
        $universe = UniverseVersion::query()->whereIn('status', ['active', 'research'])->latest('id')->first();
        if ($universe === null) {
            $this->error('Create a point-in-time universe version before preregistering an experiment.');

            return self::FAILURE;
        }
        $assets = Asset::query()
            ->where('broker', 'coinbase')
            ->whereIn('symbol', (array) $universe->symbols_json)
            ->orderBy('symbol')
            ->get(['id', 'symbol']);
        if ($assets->count() < 3) {
            $this->error('At least three Coinbase universe assets must exist before backtesting.');

            return self::FAILURE;
        }

        $experiment = $experiments->create([
            'name' => (string) ($this->option('name') ?: 'experiment-'.now('UTC')->format('Ymd-His')),
            'universe_version_id' => $universe->id,
            'development_start' => $developmentStart,
            'development_end' => $developmentEnd,
            'holdout_start' => $holdoutStart,
            'holdout_end' => $holdoutEnd,
            'initial_capital' => (float) $this->option('capital'),
            'seeds' => [(int) $this->option('seed')],
            'assets' => $assets->toArray(),
        ]);

        $this->info(sprintf(
            'Experiment %d preregistered with %d immutable candidates; holdout remains locked.',
            $experiment->id,
            $experiment->candidates->count(),
        ));

        return self::SUCCESS;
    }
}
