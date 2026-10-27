<?php

namespace App\Console\Commands;

use App\Services\Crypto\CryptoReplays;
use Illuminate\Console\Command;
use Throwable;

class CryptoReplayCommand extends Command
{
    protected $signature = 'mcp:crypto-replay {--actor= : Configured local operator ID} {--portfolio= : Crypto virtual portfolio ID} {--request= : Stable UUID for this replay} {--start= : Optional first replay date (YYYY-MM-DD)}';

    protected $description = 'Crypto C3: retrospective daily replay of the current crypto policy over stored candles; labeled separately from forward paper; no orders';

    public function handle(CryptoReplays $replays): int
    {
        try {
            if (! ctype_digit((string) $this->option('actor')) || ! ctype_digit((string) $this->option('portfolio'))) {
                throw new \InvalidArgumentException;
            }
            $run = $replays->run((int) $this->option('actor'), (int) $this->option('portfolio'), (string) $this->option('request'), $this->option('start'));
            $result = $run->payload['result'];
            $this->line(json_encode(['replay_id' => $run->id, 'status' => $run->status, 'reason' => $run->reason, 'start' => $result['start'] ?? null,
                'end' => $result['end'] ?? null, 'days' => $result['sessions'] ?? 0, 'final_equity' => $result['final_equity'] ?? null, 'stats' => $result['stats'] ?? null,
                'benchmarks' => $result['benchmarks'] ?? null, 'excluded' => $run->payload['excluded'], 'label' => $run->payload['label']], JSON_THROW_ON_ERROR));

            return $run->status === 'complete' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Crypto replay refused; check operator, crypto portfolio, request UUID, policy version and stored history.');

            return self::FAILURE;
        }
    }
}
