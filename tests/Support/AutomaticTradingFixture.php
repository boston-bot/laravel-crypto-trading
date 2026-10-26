<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\SymbolUniverse;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/** Synthetic memoized collection inputs for the existing batch collector. No provider or order calls. */
final class AutomaticTradingFixture
{
    public static function configure(): void
    {
        config(['mcp.live_automation_enabled' => true, 'mcp.brokerage_writes_enabled' => true, 'mcp.live_exit_signals_enabled' => true,
            'paper.batch_collection_enabled' => true, 'paper.market_collection_enabled' => true, 'paper.market_inputs_enabled' => true]);
    }

    public static function batch(McpConnection $c, string $bid = '101.50', string $ask = '101.60'): void
    {
        $source = SignalExecutionFixture::quote($c, $bid, $ask);
        $now = CarbonImmutable::now();
        $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'batch-paper-market:'.$c->id.':'.AccountScope::key($c).':'.$c->auth_generation.':'.intdiv($now->getTimestamp(), 300))->toString();
        foreach (app(SymbolUniverse::class)->reads($c, $now) as $symbol) {
            $payload = $source->payload;
            if ($symbol !== 'DEMO') {
                $payload = ['complete' => false, 'auth_generation' => $c->auth_generation];
            }
            $source->replicate()->fill(['request_id' => Uuid::uuid5($request, $symbol)->toString(), 'symbol' => $symbol, 'payload' => $payload])->save();
        }
    }
}
