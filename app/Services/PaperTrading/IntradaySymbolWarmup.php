<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\MarketSession;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Bounded reference repair, independent of the latency-sensitive quote/signal loop. Never grants eligibility. */
final class IntradaySymbolWarmup
{
    public function tick(?int $account = null): array
    {
        $result = ['attempted' => 0, 'profiles' => 0, 'failed' => 0];
        if (! config('paper.intraday_warmup_enabled') || ! config('paper.market_collection_enabled') ||
            ! config('paper.symbol_profiles_enabled') || ! config('alpaca_data.enabled') || ! config('alpaca_data.reference_enabled')) {
            return $result + ['disabled' => true];
        }
        if (app(MarketCalendar::class)->at(CarbonImmutable::now())['status'] !== 'regular') {
            return $result;
        }
        $lock = Cache::lock('intraday-symbol-warmup', 600);
        if (! $lock->get()) {
            return $result;
        }
        $deadline = hrtime(true) + 120 * 1_000_000_000;
        try {
            $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
            foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->where('account_role', 'agentic_monitoring')->whereNotNull('bound_account_id')
                ->when($account !== null, fn ($q) => $q->whereKey($account))->orderBy('id')->get() as $connection) {
                $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
                if (! $portfolio || ! ExecutionPolicies::current($portfolio)) {
                    continue;
                }
                foreach (app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now()) as $symbol) {
                    if ($result['attempted'] >= 3 || hrtime(true) >= $deadline) {
                        break 2;
                    }
                    $now = CarbonImmutable::now();
                    $refs = app(PaperMarketReferences::class);
                    $history = $refs->verifiedHistory($connection, $symbol, $now);
                    $benchmark = $refs->verifiedHistory($connection, StrategyParameters::DEFAULTS['benchmark_symbol'], $now);
                    $listing = $refs->listing($connection, $symbol, $now);
                    $classification = app(PaperInstrumentClassifications::class)->latestComplete($connection->id, AccountScope::key($connection), $symbol, $now);
                    $needClassification = config('sec_edgar.enabled') && (! $classification || $classification->retrieved_at->lt($now->subDays(config('sec_edgar.classification_max_age_days', 7))));
                    $profile = app(SymbolProfiles::class)->latest($connection, $symbol, $now);
                    $previous = MarketSession::where('exchange', 'XNYS')->whereIn('status', ['regular', 'early_close'])->where('session_date', '<', $now->setTimezone('America/New_York')->toDateString())->where('retrieved_at', '<=', $now)->orderByDesc('session_date')->value('session_date');
                    $needBenchmark = ! $benchmark['read'] || ($previous && $benchmark['read']->payload['facts']['last_session'] !== $previous);
                    $needReferences = ! $history['read'] || $needBenchmark || ($previous && $history['read']->payload['facts']['last_session'] !== $previous) || $listing['status'] === 'unavailable';
                    $needProfile = ! $profile || $profile->history_read_id !== $history['read_id'] || $profile->benchmark_read_id !== $benchmark['read_id'] ||
                        $profile->profile_version !== ProfileCalculator::VERSION || ($profile->payload['evidence']['parameters_hash'] ?? null) !== StrategyParameters::current()['hash'];
                    if (! $needReferences && ! $needClassification && ! $needProfile) {
                        continue;
                    }
                    // Operational retry throttle, scoped to binding and authorization. Failed reads stay unknown.
                    $key = 'intraday-warmup:'.$connection->id.':'.AccountScope::key($connection).':'.$connection->auth_generation.':'.$symbol;
                    if (! Cache::add($key, true, 600)) {
                        continue;
                    }
                    $result['attempted']++;
                    try {
                        $request = (string) Str::uuid();
                        if ($needReferences) {
                            $refs->collect($connection, $connection->user_id, [$symbol], $request, $needBenchmark);
                        }
                        if ($needClassification && hrtime(true) < $deadline) {
                            app(PaperInstrumentClassifications::class)->collect($connection, $connection->user_id, [$symbol], $request);
                        }
                        if (hrtime(true) < $deadline) {
                            $result['profiles'] += app(SymbolProfiles::class)->record($connection, $connection->user_id, [$symbol], $request)->count();
                        }
                    } catch (\Throwable $error) {
                        // Keep batch isolation, retain immutable adapter failures, and expose unexpected failures to the error reporter.
                        report($error);
                        $result['failed']++;
                    }
                }
            }
        } finally {
            $lock->release();
        }

        return $result;
    }
}
