<?php

namespace App\Console\Commands;

use App\Services\Crypto\CryptoReplays;
use Illuminate\Console\Command;
use Throwable;

class CryptoReplayCommand extends Command
{
    protected $signature = 'mcp:crypto-replay {--actor= : Configured local operator ID} {--portfolio= : Crypto virtual portfolio ID} {--request= : Stable UUID for this replay} {--start= : Optional first replay date (YYYY-MM-DD)} {--variants= : Local JSON list of research variants (at most 6)} {--playbook= : Replay another named playbook for research; the applied policy is unchanged}';

    protected $description = 'Crypto C3: retrospective daily replay of the current crypto policy over stored candles; labeled separately from forward paper; no orders';

    public function handle(CryptoReplays $replays): int
    {
        try {
            if (! ctype_digit((string) $this->option('actor')) || ! ctype_digit((string) $this->option('portfolio'))) {
                throw new \InvalidArgumentException;
            }
            $path = $this->option('variants');
            if ($path !== null && (! is_file($path) || ! is_readable($path) || filesize($path) > 65536)) {
                throw new \InvalidArgumentException;
            }
            $variants = $path === null ? [] : json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $run = $replays->run((int) $this->option('actor'), (int) $this->option('portfolio'), (string) $this->option('request'), $this->option('start'),
                is_array($variants) ? $variants : [null], $this->option('playbook'));
            $result = $run->payload['result'];
            $comparison = $run->payload['comparison'] ?? null;
            // One line per run and window: return, drawdown, trades, profit factor and fees, for side-by-side reading.
            $line = fn (array $s) => ['return_pct' => $s['stats']['total_return_pct'], 'max_drawdown_pct' => $s['stats']['max_drawdown_pct'],
                'trades' => $s['stats']['closed_trades'], 'hit_rate_pct' => $s['stats']['hit_rate_pct'], 'profit_factor' => $s['stats']['profit_factor'],
                'fees' => $s['fees'], 'btc_pct' => $s['benchmarks']['benchmark_buy_and_hold_pct']];
            $table = $comparison === null ? null : ['windows' => $comparison['windows'], 'baseline' => array_map($line, $comparison['baseline'])]
                + ['variants' => array_map(fn ($v) => ['id' => $v['id']] + array_map($line, $v['result']), $comparison['variants'])];
            $this->line(json_encode(['replay_id' => $run->id, 'playbook' => $run->payload['input']['playbook'], 'status' => $run->status, 'reason' => $run->reason, 'start' => $result['start'] ?? null,
                'end' => $result['end'] ?? null, 'days' => $result['sessions'] ?? 0, 'final_equity' => $result['final_equity'] ?? null, 'stats' => $result['stats'] ?? null,
                'benchmarks' => $result['benchmarks'] ?? null, 'excluded' => $run->payload['excluded'], 'comparison' => $table, 'label' => $run->payload['label']], JSON_THROW_ON_ERROR));

            return $run->status === 'complete' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Crypto replay refused; check operator, crypto portfolio, request UUID, policy version and stored history.');

            return self::FAILURE;
        }
    }
}
