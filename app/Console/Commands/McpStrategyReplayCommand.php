<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\ResearchVariants;
use App\Services\PaperTrading\StrategyReplays;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class McpStrategyReplayCommand extends Command
{
    protected $signature = 'mcp:strategy-replay {--account= : Local operator-owned connection ID} {--years=3 : Replay window, 1-5 years}
        {--equity=500 : Starting virtual equity} {--symbol=* : Symbols; default the nominating watchlist symbols}
        {--variant=* : Research variant beside the baseline, as id:key=value,key=value (e.g. fractional:order_mode=fractional_market)}';

    protected $description = 'Record a retrospective daily replay of the live strategy rules over split-adjusted history; never forward results, no order or ledger change';

    public function handle(StrategyReplays $replays): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection) {
            $this->error('Select an operator-owned bound connection.');

            return self::FAILURE;
        }
        $variants = [];
        foreach ($this->option('variant') as $text) {
            if (! preg_match('/^([a-z][a-z0-9_]{0,31}):((?:[a-z_]+=[A-Za-z0-9_.]+)(?:,[a-z_]+=[A-Za-z0-9_.]+)*)$/D', $text, $m)) {
                $this->error('Use --variant=id:key=value,key=value.');

                return self::FAILURE;
            }
            $variant = ['id' => $m[1], 'parameters' => [], 'execution' => [], 'risk' => []];
            foreach (explode(',', $m[2]) as $pair) {
                [$key, $value] = explode('=', $pair, 2);
                if (in_array($key, ['order_mode', 'market_slippage_bps'], true)) {
                    $variant['execution'][$key] = $value;
                } elseif (isset(ResearchVariants::RISK[$key])) {
                    $variant['risk'][$key] = ResearchVariants::typed($key, $value);
                } else {
                    $variant['parameters'][$key] = ResearchVariants::typed($key, $value);
                }
            }
            $variants[] = $variant;
        }
        $run = $replays->run($connection, $connection->user_id, (string) Str::uuid(), (int) $this->option('years'), (string) $this->option('equity'), $this->option('symbol'), $variants);
        $result = $run->payload['result'];
        $summary = fn (array $r) => ['final_equity' => $r['final_equity'], 'execution' => $r['execution'], 'stats' => $r['stats'], 'counts' => $r['counts']];
        $this->line(json_encode(['replay_id' => $run->id, 'status' => $run->status, 'reason' => $run->reason, 'label' => $run->payload['label'],
            'window' => [$run->start_date?->toDateString(), $run->end_date?->toDateString()], 'symbols' => count($run->payload['symbols']),
            'excluded' => $run->payload['excluded'], 'settings' => $run->payload['settings_source'], 'final_equity' => $result['final_equity'] ?? null,
            'stats' => $result['stats'] ?? null, 'counts' => $result['counts'] ?? null, 'benchmarks' => $result['benchmarks'] ?? null,
            'variants' => array_map(fn ($v) => ['id' => $v['id'], 'parameters' => $v['parameters'], 'execution' => $v['execution'], 'risk' => $v['risk'] ?? []] + $summary($v['result']), $run->payload['variants'] ?? [])], JSON_THROW_ON_ERROR));

        return $run->status === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
