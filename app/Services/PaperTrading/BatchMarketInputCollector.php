<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerBackoff;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Execution\LivePositionFacts;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;

/** Bounded full-universe attempts; completion never implies that quotes satisfy execution freshness. */
final class BatchMarketInputCollector
{
    public function tick(?int $account = null, bool $scheduled = false, ?callable $afterRecord = null): array
    {
        $result = ['recorded' => 0, 'selected' => 0, 'stopped' => null];
        if (! config('paper.batch_collection_enabled') || ! config('paper.market_collection_enabled') || ! config('paper.market_inputs_enabled')) {
            return $result + ['disabled' => true];
        }
        $now = CarbonImmutable::now();
        if ($scheduled && app(MarketCalendar::class)->at($now)['status'] !== 'regular' && $now->minute !== 0) {
            return $result;
        }
        $lock = Cache::lock('paper-market-collector', 600);
        if (! $lock->get()) {
            return $result;
        }
        // Monotonic processing budget. One in-flight provider request can finish after this deadline.
        $deadline = hrtime(true) + max(1, min(240, (int) config('paper.market_cycle_budget_seconds', 240))) * 1_000_000_000;
        try {
            $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
            foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->where('account_role', 'agentic_monitoring')->whereNotNull('bound_account_id')
                ->when($account !== null, fn ($q) => $q->whereKey($account))->orderBy('id')->get() as $connection) {
                $beforeRead = function () use ($connection, $deadline) {
                    if (hrtime(true) >= $deadline) {
                        throw new ReadFailure(ReadError::Stale);
                    }
                    if (app(BrokerBackoff::class)->remaining($connection->id) > 0) {
                        throw new ReadFailure(ReadError::RateLimited);
                    }
                };
                try {
                    $beforeRead();
                } catch (ReadFailure $e) {
                    $result['stopped'] = $e->reason->value;

                    continue;
                }
                $universe = app(SymbolUniverse::class);
                $symbols = $universe->reads($connection, $now);
                $held = $universe->holdings($connection, $now);
                $liveHeld = config('mcp.live_automation_enabled') ? app(LivePositionFacts::class)->symbols($connection, $now) : [];
                $latest = MarketInput::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('origin', 'broker_read')
                    ->selectRaw('symbol, MAX(recorded_at) AS last_recorded')->groupBy('symbol')->pluck('last_recorded', 'symbol')->all();
                // Oldest observations rotate first within each priority group after an incomplete cycle.
                usort($symbols, fn ($a, $b) => ((int) in_array($b, $liveHeld, true) <=> (int) in_array($a, $liveHeld, true))
                    ?: ((int) in_array($b, $held, true) <=> (int) in_array($a, $held, true))
                    ?: strcmp($latest[$a] ?? '', $latest[$b] ?? '') ?: strcmp($a, $b));
                // Refuse an oversized universe; never silently drop holdings or alter watchlist policy.
                if (count($symbols) > 250) {
                    $result['stopped'] = 'collection_universe_exceeds_bound';

                    continue;
                }
                $result['selected'] += count($symbols);
                if (! $symbols) {
                    continue;
                }
                $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'batch-paper-market:'.$connection->id.':'.AccountScope::key($connection).':'.$connection->auth_generation.':'.intdiv($now->getTimestamp(), 300))->toString();
                $rows = app(PaperMarketInputs::class)->collectMany($connection, $connection->user_id, $symbols, $request, $beforeRead,
                    afterRecord: $afterRecord ? fn ($rows) => $afterRecord($connection, array_keys($rows)) : null);
                $result['recorded'] += count($rows);
                try {
                    $beforeRead();
                } catch (ReadFailure $e) {
                    $result['stopped'] = $e->reason->value;
                }
                // Capture opening evidence before any subsequent combined paper-fill cycle.
                if (config('paper.session_valuations_enabled')) {
                    app(PaperSessionValuations::class)->capture($connection, $connection->user_id);
                }
            }
        } finally {
            $lock->release();
        }

        return $result;
    }
}
