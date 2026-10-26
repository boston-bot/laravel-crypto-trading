<?php

namespace App\Console\Commands;

use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoStrategyEvaluation;
use Illuminate\Console\Command;
use Throwable;

class CryptoPaperCommand extends Command
{
    protected $signature = 'mcp:crypto-paper {--once} {--scheduled}';

    protected $description = 'Crypto C3: one 24/7 paper cycle (public market read, shared-rule evaluation, simulated fills); no venue order';

    public function handle(CryptoMarketCollector $collector, CryptoStrategyEvaluation $evaluation): int
    {
        if (! $this->option('once')) {
            $this->error('Use --once; long-running work belongs to the scheduler.');

            return self::FAILURE;
        }
        if (! config('crypto.paper_enabled')) {
            $this->line(json_encode(['status' => 'disabled'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        // Evaluation still runs after a failed read: stale inputs yield unavailable entries, and fills need newer quotes.
        $market = $collector->tick((bool) $this->option('scheduled'));
        $results = [];
        $failed = false;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (Portfolio::where(['asset_class' => 'crypto', 'venue' => 'coinbase'])->whereNull('mcp_connection_id')->whereIn('actor_id', $operators)->orderBy('id')->get() as $portfolio) {
            try {
                $report = $evaluation->evaluate($portfolio->actor_id, $portfolio->id);
                $results[$portfolio->id] = ['status' => $report['status'], 'cycle_id' => $report['cycle_id'] ?? null, 'entry_pause' => $report['entry_pause'] ?? null,
                    'equity' => $report['equity'] ?? null, 'signals' => count(array_filter($report['signals'] ?? [], fn ($s) => $s['status'] === 'signal')),
                    'orders' => count(array_filter($report['signals'] ?? [], fn ($s) => ($s['handoff']['status'] ?? null) === 'order_created'))];
            } catch (Throwable $error) {
                report($error);
                $failed = true;
                $results[$portfolio->id] = ['status' => 'failed'];
            }
        }
        $this->line(json_encode(['status' => $failed ? 'failed' : 'complete', 'market' => $market, 'portfolios' => $results], JSON_THROW_ON_ERROR));

        return $failed || ! in_array($market['status'], ['complete', 'busy'], true) ? self::FAILURE : self::SUCCESS;
    }
}
