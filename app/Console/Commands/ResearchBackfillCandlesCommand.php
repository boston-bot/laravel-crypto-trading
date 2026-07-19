<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\EngineJob;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ResearchBackfillCandlesCommand extends Command
{
    protected $signature = 'research:backfill-candles {--years=5} {--symbol=*}';

    protected $description = 'Enqueue public Coinbase hourly candle backfills for the research universe';

    public function handle(): int
    {
        $symbols = array_map('strtoupper', (array) ($this->option('symbol') ?: config('research.universe')));
        $start = now('UTC')->subYears(max(1, (int) $this->option('years')))->startOfHour();
        $end = now('UTC')->startOfHour();
        $created = 0;
        foreach (Asset::query()->where('broker', 'coinbase')->whereIn('symbol', $symbols)->get() as $asset) {
            $key = hash('sha256', implode('|', ['backfill', $asset->id, $start->toIso8601String(), $end->toIso8601String(), '1h']));
            $job = EngineJob::query()->firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'id' => (string) Str::uuid(), 'schema_version' => (string) config('research.engine.schema_version', '1.0'),
                    'kind' => 'backfill', 'as_of' => $end,
                    'payload_json' => [
                        'asset_id' => $asset->id, 'symbol' => $asset->symbol,
                        'product_id' => $asset->symbol.'-USD', 'start' => $start->toIso8601String(),
                        'end' => $end->toIso8601String(), 'timeframe' => '1h', 'derive' => ['4h', '1d'],
                    ],
                    'status' => 'pending', 'max_attempts' => (int) config('research.engine.max_attempts', 3),
                ],
            );
            $created += $job->wasRecentlyCreated ? 1 : 0;
            $this->line($asset->symbol.': '.$job->id.($job->wasRecentlyCreated ? ' queued' : ' already queued'));
        }
        $this->info('Created '.$created.' idempotent backfill job(s).');

        return self::SUCCESS;
    }
}
