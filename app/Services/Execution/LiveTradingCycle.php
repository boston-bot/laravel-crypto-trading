<?php

namespace App\Services\Execution;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\BatchMarketInputCollector;
use App\Services\PaperTrading\SignalPaperCycle;
use App\Services\PaperTrading\StrategySignals;
use App\Services\PaperTrading\SymbolUniverse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** One bounded, reconcile-first slot per broker account. No queued submissions, stale catch-up or claim replay. */
final class LiveTradingCycle
{
    public function tick(?int $account = null): array
    {
        $result = ['cycles' => 0, 'attempted' => 0, 'failed' => 0];
        if (! config('mcp.live_automation_enabled')) {
            return $result + ['disabled' => true];
        }
        $deadline = hrtime(true) + 240_000_000_000;
        $connections = McpConnection::whereIn('user_id', User::whereIn('email', config('mcp.operator_emails'))->select('id'))
            ->where('status', 'connected')->where('account_role', 'agentic_monitoring')->whereNotNull('bound_account_id')
            ->when($account !== null, fn ($q) => $q->whereKey($account))->orderBy('id')->get();
        foreach ($connections as $c) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            $scope = AccountScope::key($c);
            $lock = Cache::lock('live-trading-cycle:'.$scope, 600);
            if (! $lock->get()) {
                continue;
            }
            $run = null;
            $attempted = $evaluated = 0;
            $reason = null;
            try {
                $activation = AutomaticTrading::current($scope);
                $now = CarbonImmutable::now();
                $slot = CarbonImmutable::createFromTimestampUTC(intdiv($now->getTimestamp(), 300) * 300);
                if ($activation && app(AutomaticTrading::class)->reason($c, $activation->id) === null && app(MarketCalendar::class)->at($now)['status'] === 'regular') {
                    // Durable dedupe survives worker restarts and expired cache leases. Never resume an old run.
                    $added = DB::table('mcp_execution_cycles')->insertOrIgnore(['execution_account_id' => $activation->execution_account_id,
                        'automation_id' => $activation->id, 'slot_at' => $slot, 'status' => 'running', 'started_at' => now()]);
                    if ($added) {
                        $run = DB::table('mcp_execution_cycles')->where('execution_account_id', $activation->execution_account_id)->where('slot_at', $slot)->value('id');
                        $result['cycles']++;
                    }
                }
                app(BrokerGateway::class)->within($c, function () use ($c, $activation, $slot, $deadline, &$run, &$attempted, &$evaluated, &$reason) {
                    if ($run) {
                        $observation = app(Monitor::class)->sync($c->id, 'automatic:'.$run, 'automatic_execution');
                        if ($observation->status !== 'complete') {
                            $reason = 'reconciliation_unavailable';
                        }
                    }
                    app(BatchMarketInputCollector::class)->tick($c->id, true, function ($connection, $symbols) use ($activation, $slot, $deadline, &$run, &$attempted, &$evaluated, &$reason) {
                        // Account, policy, switch and freshness can change at any point during collection.
                        if ($run && $reason === null && $attempted === 0 && hrtime(true) < $deadline && CarbonImmutable::now()->lt($slot->addMinutes(5)) &&
                            app(AutomaticTrading::class)->reason($connection, $activation->id) === null) {
                            $universe = array_unique([...app(SymbolUniverse::class)->candidates($connection, CarbonImmutable::now()),
                                ...app(LivePositionFacts::class)->symbols($connection, CarbonImmutable::now())]);
                            $selected = array_values(array_intersect($symbols, $universe));
                            if ($selected) {
                                $signals = app(StrategySignals::class)->recordLive($connection, $connection->user_id, $selected);
                                $evaluated += $signals->count();
                                foreach ($signals->where('status', 'signal')->sortBy(fn ($s) => $s->side === 'SELL' ? 0 : 1) as $signal) {
                                    $blocking = DB::table('mcp_execution_dispatches as d')->join('mcp_execution_intents as i', 'i.id', '=', 'd.intent_id')
                                        ->where('i.execution_account_id', $activation->execution_account_id)->whereIn('d.status', ExecutionJournal::BLOCKING)->exists();
                                    if ($blocking) {
                                        $reason = 'open_order_requires_reconciliation';
                                        break;
                                    }
                                    $plan = app(SubmissionChecks::class)->plan($connection, $signal);
                                    if ($plan['reason'] !== null) {
                                        continue;
                                    }
                                    $intent = app(ExecutionJournal::class)->stage($connection, $connection->user_id, $signal->id,
                                        $plan['draft'] + ['expires_at' => $signal->cutoff->addMinutes(5)->subSecond()->toISOString()], $activation->id);
                                    $attempted++; // One claim attempt/account/slot, regardless of external outcome.
                                    $reason = app(ExecutionDispatcher::class)->dispatch($connection, $connection->user_id, $intent->id);
                                    break;
                                }
                            }
                        }
                        // The same stored quote can independently feed the visibly separate paper ledger.
                        app(SignalPaperCycle::class)->consume($connection, $symbols);
                    });
                });
                if ($run) {
                    DB::table('mcp_execution_cycles')->where('id', $run)->update(['status' => 'complete', 'reason' => $reason,
                        'evaluated' => $evaluated, 'attempted' => $attempted, 'finished_at' => now()]);
                }
            } catch (\Throwable) {
                $result['failed']++;
                if ($run) {
                    DB::table('mcp_execution_cycles')->where('id', $run)->update(['status' => 'failed', 'reason' => 'automatic_cycle_failed',
                        'evaluated' => $evaluated, 'attempted' => $attempted, 'finished_at' => now()]);
                }
            } finally {
                $result['attempted'] += $attempted;
                $lock->release();
            }
        }

        return $result;
    }
}
