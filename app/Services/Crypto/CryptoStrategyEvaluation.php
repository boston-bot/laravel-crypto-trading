<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SignalOrderHandoff;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\PaperTrading\SymbolProfile;
use App\Models\User;
use App\Services\PaperTrading\InstrumentSizing;
use App\Services\PaperTrading\PaperFillPrice;
use App\Services\PaperTrading\PortfolioEntryLimits;
use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\RegimeRules;
use App\Services\PaperTrading\RiskSizer;
use App\Services\PaperTrading\SignalPositionFacts;
use App\Services\PaperTrading\SignalRules;
use App\Services\PaperTrading\StrategySignals;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crypto adapter/orchestrator for the shared rule engine and paper ledger. One frozen cycle per five-minute UTC window:
 * advance open simulated orders, value the sleeve, evaluate exits for held products and entries for the collection list,
 * then (when the paper cycle is enabled) hand signals to simulated limit orders. No network, model call or venue order.
 */
final class CryptoStrategyEvaluation
{
    public const VERSION = 'crypto_stored_evaluation_v3';

    private const NOT_APPLICABLE = ['status' => 'pass', 'reason' => 'not_applicable_to_crypto'];

    public function evaluate(int $actor, int $portfolioId, ?CarbonImmutable $cutoff = null): array
    {
        if (! config('crypto.strategy_evaluation_enabled')) {
            return ['status' => 'disabled'];
        }
        $cutoff ??= CarbonImmutable::now();
        abort_if($cutoff->gt(CarbonImmutable::now()), 422, 'Future cutoff is not allowed.');
        $products = config('crypto.coinbase_products');
        abort_unless(is_array($products) && count($products) > 0 && count($products) <= 25 && count(array_unique($products)) === count($products), 422, 'Configure 1–25 unique collection products.');
        foreach ($products as $id) {
            abort_unless(is_string($id) && preg_match(CoinbasePublicMarketData::PRODUCT_PATTERN, $id), 422, 'Invalid product ID.');
        }
        sort($products);

        return DB::transaction(function () use ($actor, $portfolioId, $cutoff, $products) {
            $user = User::whereKey($actor)->firstOrFail();
            abort_unless(in_array($user->email, config('mcp.operator_emails'), true), 403);
            $portfolio = Portfolio::whereKey($portfolioId)->where(['actor_id' => $actor, 'asset_class' => 'crypto', 'venue' => 'coinbase'])
                ->whereNull('mcp_connection_id')->lockForUpdate()->firstOrFail();
            $policy = InstrumentPolicy::where('paper_portfolio_id', $portfolio->id)->latest('version')->firstOrFail();
            abort_unless($policy->actor_id === $actor && $policy->applied_at->lte($cutoff) && CryptoPolicies::compatible($policy), 409, 'Policy version is unavailable or incompatible.');
            $window = $cutoff->utc()->startOfMinute()->subMinutes((int) $cutoff->utc()->format('i') % 5);
            $cycle = StrategyCycle::where(['paper_portfolio_id' => $portfolio->id, 'instrument_policy_id' => $policy->id, 'rule_version' => self::VERSION])
                ->where('cycle_at', $window->format('Y-m-d H:i:s.u'))->first();
            if ($cycle) {
                abort_unless($cycle->payload['products'] === $products, 409, 'This window already froze a different collection list.');

                return $this->report($cycle);
            }
            $paper = (bool) config('crypto.paper_enabled');
            $parameters = $policy->payload['parameters'];
            $p = $parameters['values'];
            $regime = CryptoParameters::regime($parameters);
            $orders = app(CryptoPaperOrders::class);
            $valuation = app(CryptoPaperValuation::class);
            // Existing orders see newer observations before any new intent, so restarts never skip an exit fill.
            $advanced = $paper ? $orders->advance($portfolio, $policy, $cutoff) : null;
            $held = $valuation->held($portfolio);
            // Exits never wait on the collection list; a held product stays evaluated after removal.
            $symbols = array_values(array_unique([...($regime ? [$p['product']] : $products), ...array_keys($held)]));
            sort($symbols);
            $openOrders = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->whereIn('status', ['open', 'partial'])->get(['symbol', 'side']);
            $value = $valuation->value($portfolio, $cutoff, $p['quote_max_age_seconds']);
            $baseline = $valuation->baseline($portfolio, $cutoff);
            $occupied = count($held) + $openOrders->where('side', 'BUY')->whereNotIn('symbol', array_keys($held))->count();
            // Adding to the regime product is a resize, not a new position.
            $occupied -= $regime && isset($held[$p['product']]) ? 1 : 0;
            $latched = $valuation->latched($portfolio, $cutoff);
            $entryPause = PortfolioEntryLimits::reason($value['equity'], $baseline['equity'] ?? null, $occupied, $policy->payload['owner'], $latched);
            $cycle = StrategyCycle::create(['mcp_connection_id' => null, 'asset_class' => 'crypto', 'venue' => 'coinbase',
                'paper_portfolio_id' => $portfolio->id, 'instrument_policy_id' => $policy->id, 'scope' => $portfolio->scope,
                'auth_generation' => 0, 'actor_id' => $actor, 'rule_version' => self::VERSION, 'cycle_at' => $window, 'cutoff' => $cutoff,
                'recorded_at' => now(), 'payload' => ['products' => $products, 'held' => $held, 'symbols' => $symbols,
                    'parameters_version' => $parameters['version'], 'parameters_hash' => $parameters['hash'], 'policy_hash' => $policy->fingerprint,
                    'ledger_version' => $portfolio->ledger_version, 'valuation' => $value, 'baseline' => $baseline, 'occupied_positions' => $occupied,
                    'loss_pause_latched' => $latched, 'entry_pause' => $entryPause, 'advanced_orders' => $advanced, 'paper_orders' => $paper,
                    'mode' => 'paper_signal_evidence', 'broker_executable' => false]]);
            $signals = [];
            foreach ($symbols as $product) {
                $inputs = app(CryptoStrategyInputs::class)->read($product, $cutoff, $p);
                $cost = app(CryptoFeeObservations::class)->schedule($actor, $policy->payload, $product, $cutoff);
                $quote = $inputs['quote'];
                [$result, $report, $profile] = $regime ? [...$this->regime($portfolio, $product, $inputs, $p, $value, $entryPause, $openOrders, $cost, $cutoff), null]
                    : $this->swing($portfolio, $policy, $actor, $product, $inputs, $parameters, $value, $entryPause, $openOrders, $cost, $cutoff);
                $evidence = ['asset_class' => 'crypto', 'venue' => 'coinbase', 'mode' => 'paper_signal_evidence', 'broker_executable' => false,
                    'rule_version' => $regime ? RegimeRules::VERSION : SignalRules::VERSION, 'adapter_version' => self::VERSION, 'parameters_version' => $parameters['version'],
                    'parameters_hash' => $parameters['hash'], 'policy_id' => $policy->id, 'policy_hash' => $policy->fingerprint,
                    'cycle_id' => $cycle->id, 'profile_id' => $profile?->id, 'profile_fingerprint' => $profile?->input_fingerprint, 'cutoff' => $cutoff->toISOString(),
                    'eligibility' => ['status' => $inputs['status'], 'reasons' => $inputs['reasons']], 'product' => $inputs['product'], 'quote' => $quote,
                    'input_ids' => array_diff_key($inputs['evidence'], array_flip(['history_ids', 'benchmark_ids'])), 'cost_schedule' => $cost,
                    'ledger_version' => $portfolio->ledger_version, 'valuation' => ['equity' => $value['equity'], 'cash' => $value['cash'], 'reserved_cash' => $value['reserved_cash']],
                    'baseline_equity' => $baseline['equity'] ?? null, 'entry_pause' => $entryPause, 'watchlist_revision_id' => null, 'report' => $report];
                $signals[] = StrategySignal::create(['mcp_connection_id' => null, 'asset_class' => 'crypto', 'venue' => 'coinbase', 'paper_portfolio_id' => $portfolio->id,
                    'cycle_id' => $cycle->id, 'symbol' => $product, 'profile_id' => $profile?->id, 'status' => $result['status'], 'side' => $result['side'],
                    'reason' => $result['reason'], 'rule_version' => $regime ? RegimeRules::VERSION : SignalRules::VERSION, 'parameters_version' => $parameters['version'],
                    'input_fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
                    // Full parameters accompany actionable signals only; the policy pins them for every other row.
                    'payload' => ['evidence' => $evidence, 'parameters' => $result['status'] === 'signal' ? $parameters : ['version' => $parameters['version'], 'hash' => $parameters['hash']],
                        'result' => $result, 'mode' => 'paper_signal_evidence', 'broker_executable' => false],
                    'cutoff' => $cutoff, 'recorded_at' => now()]);
            }
            if ($paper) {
                // Exits first so a freed slot or cash is visible to entries; then entries in product order.
                usort($signals, fn ($a, $b) => [$a->side === 'SELL' ? 0 : 1, $a->symbol] <=> [$b->side === 'SELL' ? 0 : 1, $b->symbol]);
                foreach ($signals as $signal) {
                    if ($signal->status === 'signal') {
                        $orders->submit($portfolio, $policy, $signal, $cycle->id, $cutoff);
                        $portfolio->refresh();
                    }
                }
            }

            return $this->report($cycle);
        });
    }

    /** Swing playbook for one product: shared exit rules for a held episode, otherwise the shared entry rules and gates. */
    private function swing(Portfolio $portfolio, InstrumentPolicy $policy, int $actor, string $product, array $inputs, array $parameters, array $value, ?string $entryPause, $openOrders, ?array $cost, CarbonImmutable $cutoff): array
    {
        $p = $parameters['values'];
        $quote = $inputs['quote'];
        $profile = $this->profile($portfolio, $actor, $product, $inputs, $parameters, $cutoff);
        $position = app(SignalPositionFacts::class)->inspect($portfolio, $product, $cutoff);
        $report = ['position' => $position];
        if ($position['held']) {
            $tradable = $this->tradable($inputs);
            if ($position['context'] === null) {
                $result = SignalRules::result('unavailable', $position['reason']);
            } elseif ($quote === null || ! $tradable) {
                $result = SignalRules::result('unavailable', $quote === null ? 'quote_unavailable_or_stale' : 'exit_product_unavailable');
            } else {
                $context = app(StrategySignals::class)->exitContext($portfolio, $product, $position['context'], $inputs['bars'], $cutoff, 'paper', 'UTC');
                $report['position']['context'] = $context;
                $result = app(SignalRules::class)->exit($quote['bid'], $context, $profile->status === 'complete' ? $profile->payload : null, self::NOT_APPLICABLE, $context['parameters']);
            }
        } else {
            $cooldown = $position['last_exit_at'] === null ? null :
                (int) CarbonImmutable::parse($position['last_exit_at'])->utc()->startOfDay()->diffInDays($cutoff->utc()->startOfDay());
            $result = match (true) {
                $inputs['status'] !== 'eligible' => SignalRules::result('unavailable', $inputs['reasons'][0]),
                $cost === null => SignalRules::result('unavailable', 'product_fee_schedule_unavailable_or_expired'),
                $entryPause === 'portfolio_valuation_unavailable' => SignalRules::result('unavailable', $entryPause),
                $entryPause !== null => SignalRules::result('wait', $entryPause),
                $openOrders->contains('symbol', $product) => SignalRules::result('wait', 'open_order_exists'),
                $cooldown !== null && $cooldown < $p['cooldown_sessions'] => SignalRules::result('wait', 'reentry_cooldown'),
                $profile->status !== 'complete' => SignalRules::result('unavailable', 'profile_unavailable'),
                SignalRules::marketFilterBlocks($profile->payload, $p) => SignalRules::result('wait', 'market_filter_off'),
                default => app(SignalRules::class)->entry($profile->payload, $quote['ask'], $p),
            };
            $report['cooldown_elapsed_days'] = $cooldown;
            if ($result['status'] === 'signal') {
                $result = $this->entryBounds($result, $profile->payload, $inputs, $value, $portfolio, $policy, $cost, $p);
            }
        }

        return [$result, $report, $profile];
    }

    /** Venue state that prevents any new order, including an exit. Volume, spread and history gates apply to entries only. */
    private function tradable(array $inputs): bool
    {
        return ! array_intersect($inputs['reasons'], ['product_metadata_unavailable_or_stale', 'product_not_online_usd_spot',
            'product_currency_identity_mismatch', 'product_trading_disabled', 'product_is_disabled', 'product_view_only', 'product_cancel_only', 'product_post_only',
            'product_auction_mode', 'product_constraints_invalid', 'quote_invalid_or_empty']);
    }

    /**
     * Regime playbook for one product: the target fraction from yesterday's settled close, compared with the current marked
     * fraction. One rebalance per UTC day mirrors the daily decision; moving to cash is never held back by entry gates.
     */
    private function regime(Portfolio $portfolio, string $product, array $inputs, array $p, array $value, ?string $entryPause, $openOrders, ?array $cost, CarbonImmutable $cutoff): array
    {
        $quote = $inputs['quote'];
        $quantity = $value['marks'][$product]['quantity'] ?? (in_array($product, $value['missing_marks'], true) ? null : '0');
        $report = ['regime' => null, 'held_quantity' => $quantity];
        $tradable = $quote !== null && $this->tradable($inputs);
        if ($product !== $p['product']) {
            // A holding outside the playbook (for example after a playbook change) is closed out.
            return [match (true) {
                ! $tradable => SignalRules::result('unavailable', $quote === null ? 'quote_unavailable_or_stale' : 'exit_product_unavailable'),
                $openOrders->contains('symbol', $product) => SignalRules::result('wait', 'open_order_exists'),
                default => SignalRules::result('signal', 'not_in_playbook', 'SELL', ['target_fraction' => '0']),
            }, $report];
        }
        $target = app(RegimeRules::class)->target($inputs['bars'], $p);
        $report['regime'] = $target;
        if ($target['status'] !== 'complete' || $quote === null || $value['equity'] === null || $quantity === null) {
            return [SignalRules::result('unavailable', $target['status'] !== 'complete' ? $target['reason'] : ($quote === null ? 'quote_unavailable_or_stale' : 'portfolio_valuation_unavailable')), $report];
        }
        $current = Decimal::from($quantity)->multipliedBy(Decimal::from($quote['bid']))->dividedBy(Decimal::from($value['equity']), 18);
        $facts = ['target_fraction' => $target['fraction'], 'current_fraction' => (string) $current, 'equity' => $value['equity'], 'regime' => $target['facts']];
        $side = RegimeRules::rebalance((string) $current, $target['fraction'], $p['rebalance_band_pct']);
        $today = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('symbol', $product)->whereIn('status', ['filled', 'partial'])
            ->where('accepted_at', '>=', $cutoff->utc()->startOfDay()->format('Y-m-d H:i:s.u'))->exists();
        $exit = $side === 'SELL' && Decimal::from($target['fraction'])->compareTo(Decimal::from('0')) === 0;
        $result = match (true) {
            $side === null => SignalRules::result('wait', $target['in_trend'] ? 'regime_target_held' : 'regime_out_of_trend', facts: $facts),
            $openOrders->contains('symbol', $product) => SignalRules::result('wait', 'open_order_exists', facts: $facts),
            $exit => $tradable ? SignalRules::result('signal', 'regime_exit', 'SELL', $facts) : SignalRules::result('unavailable', 'exit_product_unavailable', facts: $facts),
            $today => SignalRules::result('wait', 'rebalanced_today', facts: $facts),
            $side === 'SELL' => $tradable ? SignalRules::result('signal', 'regime_reduce', 'SELL', $facts) : SignalRules::result('unavailable', 'exit_product_unavailable', facts: $facts),
            $inputs['status'] !== 'eligible' => SignalRules::result('unavailable', $inputs['reasons'][0], facts: $facts),
            $cost === null => SignalRules::result('unavailable', 'product_fee_schedule_unavailable_or_expired', facts: $facts),
            $entryPause === 'portfolio_valuation_unavailable' => SignalRules::result('unavailable', $entryPause, facts: $facts),
            $entryPause !== null => SignalRules::result('wait', $entryPause, facts: $facts),
            default => SignalRules::result('signal', $current->compareTo(Decimal::from('0')) === 0 ? 'regime_entry' : 'regime_increase', 'BUY', $facts),
        };

        return [$result, $report];
    }

    /** Profiles change once per UTC day; identical stored history reuses the existing immutable profile. */
    private function profile(Portfolio $portfolio, int $actor, string $product, array $inputs, array $parameters, CarbonImmutable $cutoff): SymbolProfile
    {
        $evidence = ['asset_class' => 'crypto', 'venue' => 'coinbase', 'parameters_hash' => $parameters['hash'], 'calculator_version' => ProfileCalculator::VERSION,
            'day_boundary' => $inputs['evidence']['day_boundary'], 'history_ids' => $inputs['evidence']['history_ids'],
            'benchmark_ids' => $inputs['evidence']['benchmark_ids'], 'bars' => $inputs['bars'], 'benchmark' => $inputs['benchmark']];
        $fingerprint = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
        $existing = SymbolProfile::where(['paper_portfolio_id' => $portfolio->id, 'asset_class' => 'crypto', 'symbol' => $product, 'input_fingerprint' => $fingerprint])
            ->latest('id')->first();
        if ($existing) {
            return $existing;
        }
        $profile = app(ProfileCalculator::class)->compute($inputs['bars'], $inputs['benchmark'], $parameters['values'], self::NOT_APPLICABLE);

        return SymbolProfile::create(['mcp_connection_id' => null, 'asset_class' => 'crypto', 'venue' => 'coinbase',
            'paper_portfolio_id' => $portfolio->id, 'scope' => $portfolio->scope, 'request_id' => (string) Str::uuid(), 'actor_id' => $actor,
            'symbol' => $product, 'session_date' => $profile['session_date'], 'profile_version' => ProfileCalculator::VERSION,
            'parameters_version' => $parameters['version'], 'status' => $profile['status'], 'setup' => $profile['setup'], 'reason' => $profile['reason'],
            'input_fingerprint' => $fingerprint, 'payload' => $profile + ['evidence' => $evidence], 'cutoff' => $cutoff, 'recorded_at' => now()]);
    }

    /** The worst modeled entry must stay inside the chase ceiling and size to a nonzero venue quantity at current equity. */
    private function entryBounds(array $result, array $profile, array $inputs, array $value, Portfolio $portfolio, InstrumentPolicy $policy, array $cost, array $p): array
    {
        $bound = InstrumentSizing::up(PaperFillPrice::calculate('BUY', $inputs['quote']['ask'], $p['slippage_bps'], 18), $inputs['product']['price_increment']);
        if ($bound->compareTo(Decimal::from($profile['levels']['max_entry'])) > 0) {
            return SignalRules::result('wait', 'modeled_entry_exceeds_maximum_chase');
        }
        $stop = $bound->minus(Decimal::from($profile['indicators']['atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
        $sizing = app(RiskSizer::class)->calculate(Decimal::from($value['equity']), Decimal::from($portfolio->cash)->minus(Decimal::from($portfolio->reserved_cash)),
            $bound, $stop, $policy->payload['owner'] + ['cost_schedule' => $cost, 'slippage_bps' => $p['slippage_bps']], instrument: $inputs['product'] + ['asset_class' => 'crypto']);
        $result['facts'] += ['modeled_price_bound' => (string) $bound, 'modeled_initial_stop' => (string) $stop, 'sizing' => $sizing];

        return $sizing['reason'] === null ? $result : SignalRules::result('wait', $sizing['reason'], facts: $result['facts']);
    }

    private function report(StrategyCycle $cycle): array
    {
        $signals = StrategySignal::where('cycle_id', $cycle->id)->orderBy('symbol')->get();
        $handoffs = SignalOrderHandoff::whereIn('signal_id', $signals->pluck('id'))->get()->keyBy('signal_id');

        return ['status' => 'complete', 'cycle_id' => $cycle->id, 'mode' => 'paper_signal_evidence', 'broker_executable' => false,
            'entry_pause' => $cycle->payload['entry_pause'], 'equity' => $cycle->payload['valuation']['equity'],
            'signals' => $signals->map(fn ($s) => $s->only(['id', 'symbol', 'status', 'side', 'reason']) +
                ($handoffs->has($s->id) ? ['handoff' => $handoffs[$s->id]->only(['status', 'reason', 'sim_order_id'])] : []))->all()];
    }
}
