<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\MarketSession;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SessionValuation;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Opening-minute bid valuation, not an auction-price claim. Never substitutes current marks for missing opening data. */
final class PaperSessionValuations
{
    public const VERSION = 'opening_minute_bid_unchanged_ledger_v1';

    public function capture(McpConnection $connection, int $actor): array
    {
        if (! config('paper.session_valuations_enabled')) {
            return ['status' => 'unavailable', 'reason' => 'session_valuations_disabled'];
        }

        return DB::transaction(function () use ($connection, $actor) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
                $current->auth_generation === $connection->auth_generation && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
            $cutoff = CarbonImmutable::now();
            $date = $cutoff->setTimezone('America/New_York')->toDateString();
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $prior = SessionValuation::where('paper_portfolio_id', $portfolio->id)->where('auth_generation', $current->auth_generation)->where('session_date', $date)->first();
            if ($prior) {
                return $prior->rule_version === self::VERSION && $prior->cutoff->lte($cutoff) && $prior->recorded_at->lte($cutoff)
                    ? ['status' => 'complete', 'valuation' => $prior] : ['status' => 'unavailable', 'reason' => 'opening_valuation_after_cutoff_or_unreviewed'];
            }
            $unknown = fn ($reason) => ['status' => 'unavailable', 'reason' => $reason];
            $session = MarketSession::where('exchange', 'XNYS')->where('session_date', $date)->first();
            if (! $session || $session->retrieved_at->gt($cutoff) || $session->retrieved_at->lt($cutoff->subDays(7)) || $session->status === 'holiday' ||
                $cutoff->lt($session->opens_at) || $cutoff->gte($session->closes_at)) {
                return $unknown('outside_verified_session');
            }
            $open = CarbonImmutable::instance($session->opens_at);
            $end = $open->addMinute();
            // A held portfolio must predate the session; opening cash created during a session is handled by inception policy.
            if ($portfolio->created_at->gt($open)) {
                return $unknown('portfolio_created_after_session_open');
            }
            $orders = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->select('id');
            if (CashEntry::where('paper_portfolio_id', $portfolio->id)->where('recorded_at', '>=', $open->format('Y-m-d H:i:s.u'))->exists() ||
                SimulationEvent::whereIn('sim_order_id', $orders)->where('kind', 'fill')->where('recorded_at', '>=', $open->format('Y-m-d H:i:s.u'))->exists() ||
                CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('recorded_at', '>=', $open->format('Y-m-d H:i:s.u'))->whereIn('phase', ['application', 'entitlement', 'block'])->exists()) {
                return $unknown('ledger_changed_since_session_open');
            }
            $positions = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->orderBy('id')->get();
            if ($positions->contains(fn ($p) => $p->blocked_reason !== null || Decimal::from($p->quantity)->compareTo(Decimal::from('0')) < 0) || app(PaperIncome::class)->receivable($portfolio)->compareTo(Decimal::from('0')) > 0) {
                return $unknown('opening_accounting_unverified');
            }
            $equity = Decimal::from($portfolio->cash);
            $evidence = ['session_id' => $session->id, 'opens_at' => $open->toISOString(), 'session_source' => $session->source,
                'cash' => $portfolio->cash, 'ledger_version' => $portfolio->ledger_version, 'positions' => [], 'market_input_ids' => [],
                'auth_generation' => $current->auth_generation, 'rule_version' => self::VERSION];
            foreach ($positions as $position) {
                if (Decimal::from($position->quantity)->compareTo(Decimal::from('0')) === 0) {
                    continue;
                }
                $inputs = MarketInput::where('mcp_connection_id', $current->id)->where('scope', $portfolio->scope)->where('symbol', $position->symbol)
                    ->where('origin', 'broker_read')->where('retrieved_at', '>=', $open->format('Y-m-d H:i:s.u'))
                    ->where('retrieved_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->orderBy('id')->get();
                $selected = null;
                foreach ($inputs as $input) {
                    $payload = $input->payload;
                    $q = $payload['quote'] ?? [];
                    if (($payload['provider'] ?? null) !== TrustedHttp::RESOURCE || ($payload['auth_generation'] ?? null) !== $current->auth_generation ||
                        ($payload['complete'] ?? false) !== true || ($q['symbol'] ?? null) !== $position->symbol) {
                        continue;
                    }
                    try {
                        $stamp = $q['bid_as_of'] ?? null;
                        if (! is_string($stamp) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $stamp)) {
                            continue;
                        }
                        $time = CarbonImmutable::parse($stamp);
                        $quoteRetrieved = CarbonImmutable::parse($q['retrieved_at'] ?? 'invalid');
                        $bid = Decimal::from($q['bid'] ?? null);
                        if ($time->lt($open) || $time->gte($end) || $time->gt($quoteRetrieved) || $quoteRetrieved->lt($open) || $quoteRetrieved->gte($end) || $quoteRetrieved->gt($input->retrieved_at) || $bid->compareTo(Decimal::from('0')) <= 0 ||
                            app(CorporateActionClearance::class)->inspect($input, CarbonImmutable::instance($input->recorded_at), 60)['clear'] !== true) {
                            continue;
                        }
                        $selected = $input;
                        break;
                    } catch (\InvalidArgumentException) {
                        continue;
                    }
                }
                if (! $selected) {
                    return $unknown('opening_quote_missing_or_unverified');
                }
                $q = $selected->payload['quote'];
                $value = Decimal::from($position->quantity)->multipliedBy(Decimal::from($q['bid']));
                $equity = $equity->plus($value);
                $evidence['positions'][] = $position->only(['id', 'symbol', 'sleeve', 'quantity']) + ['bid' => $q['bid'], 'bid_as_of' => $q['bid_as_of'], 'value' => (string) $value];
                $evidence['market_input_ids'][$position->symbol] = $selected->id;
            }
            $equity = $equity->rounded(18, RoundingMode::HALF_EVEN);
            $evidence['equity_rounding'] = 'half_even_18';
            if ($equity->compareTo(Decimal::from('0')) <= 0) {
                return $unknown('opening_equity_not_positive');
            }
            $valuation = SessionValuation::create(['paper_portfolio_id' => $portfolio->id, 'auth_generation' => $current->auth_generation,
                'session_date' => $date, 'actor_id' => $actor, 'rule_version' => self::VERSION, 'equity' => (string) $equity,
                'input_fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)), 'payload' => $evidence,
                'cutoff' => $cutoff, 'recorded_at' => now()]);

            return ['status' => 'complete', 'valuation' => $valuation];
        });
    }

    /** Existing cash-only inception/prior-order policy stays valid; held opening marks require explicit enablement. */
    public function resolve(McpConnection $connection, Portfolio $portfolio, array $session, ?int $excludeOrderId = null): ?array
    {
        abort_unless($portfolio->mcp_connection_id === $connection->id && $portfolio->scope === AccountScope::key($connection), 409, 'Portfolio scope changed.');
        if (config('paper.session_valuations_enabled')) {
            $captured = $this->capture($connection, $connection->user_id);
            if ($captured['status'] === 'complete') {
                $v = $captured['valuation'];

                return ['equity' => $v->equity, 'kind' => $v->rule_version, 'valuation_id' => $v->id];
            }
        }
        $prior = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('side', 'BUY')->where('accepted_at', '>=', CarbonImmutable::parse($session['opens_at']))
            ->where('accepted_at', '<=', now()->format('Y-m-d H:i:s.u'))->where('status', '!=', 'rejected')->when($excludeOrderId !== null, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->orderBy('id')->get()->first(function ($order) use ($connection) {
                $id = $order->intent['strategy_signal_id'] ?? null;

                return $id && StrategySignal::whereKey($id)->whereIn('cycle_id', StrategyCycle::where('position_source', 'paper')->where('mcp_connection_id', $connection->id)->where('auth_generation', $connection->auth_generation)->select('id'))->exists();
            });
        if ($prior) {
            return ['equity' => $prior->assumptions['session_open_equity'], 'kind' => 'prior_verified_signal_order', 'order_id' => $prior->id];
        }
        $positions = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->get();
        if (app(PaperIncome::class)->receivable($portfolio)->compareTo(Decimal::from('0')) > 0 ||
            $positions->contains(fn ($p) => $p->blocked_reason !== null || Decimal::from($p->quantity)->compareTo(Decimal::from('0')) !== 0) ||
            CashEntry::where('paper_portfolio_id', $portfolio->id)->where('recorded_at', '>=', CarbonImmutable::parse($session['opens_at']))->where('kind', '!=', 'opening_balance')->exists()) {
            return null;
        }

        return ['equity' => $portfolio->cash, 'kind' => 'unchanged_cash_only_ledger'];
    }
}
