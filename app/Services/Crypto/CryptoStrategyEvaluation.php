<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Models\PaperTrading\SymbolProfile;
use App\Models\User;
use App\Services\PaperTrading\InstrumentSizing;
use App\Services\PaperTrading\PaperFillPrice;
use App\Services\PaperTrading\PortfolioEntryLimits;
use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\RiskSizer;
use App\Services\PaperTrading\SignalRules;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** C2 data adapter/orchestrator for the shared rule engine and existing immutable paper evidence tables.
 * No scheduler, network, paper-order handoff or fill. C3 must supply episode/valuation integration before exposure.
 */
final class CryptoStrategyEvaluation
{
    public const VERSION = 'crypto_stored_evaluation_v2';

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
            abort_unless($policy->actor_id === $actor && $policy->applied_at->lte($cutoff) &&
                ($policy->payload['version'] ?? null) === CryptoPolicies::VERSION &&
                hash('sha256', json_encode($policy->payload, JSON_THROW_ON_ERROR)) === $policy->fingerprint &&
                $policy->payload['parameters'] === CryptoParameters::current() && $policy->payload['owner'] === CryptoPolicies::OWNER, 409, 'Policy version is unavailable or incompatible.');
            $window = $cutoff->utc()->startOfMinute()->subMinutes((int) $cutoff->utc()->format('i') % 5);
            $cycle = StrategyCycle::where(['paper_portfolio_id' => $portfolio->id, 'instrument_policy_id' => $policy->id, 'rule_version' => self::VERSION])
                ->where('cycle_at', $window->format('Y-m-d H:i:s.u'))->first();
            if ($cycle) {
                abort_unless($cycle->payload['products'] === $products, 409, 'This window already froze a different collection list.');

                return $this->report($cycle);
            }
            $parameters = $policy->payload['parameters'];
            $p = $parameters['values'];
            $cycle = StrategyCycle::create(['mcp_connection_id' => null, 'asset_class' => 'crypto', 'venue' => 'coinbase',
                'paper_portfolio_id' => $portfolio->id, 'instrument_policy_id' => $policy->id, 'scope' => $portfolio->scope,
                'auth_generation' => 0, 'actor_id' => $actor, 'rule_version' => self::VERSION, 'cycle_at' => $window, 'cutoff' => $cutoff,
                'recorded_at' => now(), 'payload' => ['products' => $products, 'parameters' => $parameters, 'policy_hash' => $policy->fingerprint,
                    'ledger_version' => $portfolio->ledger_version, 'mode' => 'paper_signal_evidence', 'broker_executable' => false]]);
            // Only a verified, unchanged opening ledger is valued in C2. No invented opening baseline after fills.
            $hasExposure = SimulationPosition::where('paper_portfolio_id', $portfolio->id)->where('quantity', '>', 0)->exists() ||
                SimulationOrder::where('paper_portfolio_id', $portfolio->id)->exists();
            $flatOpening = ! $hasExposure && $portfolio->ledger_version === 1 && Decimal::from($portfolio->cash)->compareTo(Decimal::from($portfolio->starting_cash)) === 0 &&
                Decimal::from($portfolio->reserved_cash)->compareTo(Decimal::from('0')) === 0;
            $entryPause = PortfolioEntryLimits::reason($flatOpening ? $portfolio->cash : null, $flatOpening ? $portfolio->starting_cash : null, 0, $policy->payload['owner']);
            foreach ($products as $product) {
                $inputs = app(CryptoStrategyInputs::class)->read($product, $cutoff, $p);
                $profile = app(ProfileCalculator::class)->compute($inputs['bars'], $inputs['benchmark'], $p, ['status' => 'pass', 'reason' => 'not_applicable_to_crypto']);
                $profileEvidence = ['asset_class' => 'crypto', 'venue' => 'coinbase', 'policy_id' => $policy->id, 'parameters' => $parameters,
                    'inputs' => $inputs, 'cutoff' => $cutoff->toISOString(), 'calculator_version' => ProfileCalculator::VERSION];
                $storedProfile = SymbolProfile::create(['mcp_connection_id' => null, 'asset_class' => 'crypto', 'venue' => 'coinbase',
                    'paper_portfolio_id' => $portfolio->id, 'scope' => $portfolio->scope, 'request_id' => (string) Str::uuid(), 'actor_id' => $actor,
                    'symbol' => $product, 'session_date' => $profile['session_date'], 'profile_version' => ProfileCalculator::VERSION,
                    'parameters_version' => $parameters['version'], 'status' => $profile['status'], 'setup' => $profile['setup'], 'reason' => $profile['reason'],
                    'input_fingerprint' => hash('sha256', json_encode($profileEvidence, JSON_THROW_ON_ERROR)), 'payload' => $profile + ['evidence' => $profileEvidence],
                    'cutoff' => $cutoff, 'recorded_at' => now()]);
                $cost = $policy->payload['costs'][$product] ?? null;
                $costValid = $cost !== null && CarbonImmutable::parse($cost['observed_at'])->lte($cutoff) && CarbonImmutable::parse($cost['valid_until'])->gt($cutoff);
                $result = match (true) {
                    $inputs['status'] !== 'eligible' => SignalRules::result('unavailable', $inputs['reasons'][0]),
                    ! $costValid => SignalRules::result('unavailable', 'product_fee_schedule_unavailable_or_expired'),
                    $entryPause !== null => SignalRules::result('unavailable', $entryPause),
                    $profile['status'] !== 'complete' => SignalRules::result('unavailable', 'profile_unavailable'),
                    SignalRules::marketFilterBlocks($profile, $p) => SignalRules::result('wait', 'market_filter_off'),
                    default => app(SignalRules::class)->entry($profile, $inputs['quote']['ask'], $p),
                };
                $sizing = null;
                if ($result['status'] === 'signal') {
                    $bound = InstrumentSizing::up(PaperFillPrice::calculate('BUY', $inputs['quote']['ask'], $p['slippage_bps'], 18), $inputs['product']['price_increment']);
                    // Check the worst modeled entry against the chase ceiling, not just the displayed ask.
                    if ($bound->compareTo(Decimal::from($profile['levels']['max_entry'])) > 0) {
                        $result = SignalRules::result('wait', 'modeled_entry_exceeds_maximum_chase');
                    } else {
                        $stop = $bound->minus(Decimal::from($profile['indicators']['atr'])->multipliedBy(Decimal::from($p['initial_stop_atr'])));
                        $sizing = app(RiskSizer::class)->calculate(Decimal::from($portfolio->cash), Decimal::from($portfolio->cash), $bound, $stop,
                            $policy->payload['owner'] + ['cost_schedule' => $cost, 'slippage_bps' => $p['slippage_bps']], instrument: $inputs['product'] + ['asset_class' => 'crypto']);
                        $result['facts'] += ['modeled_price_bound' => (string) $bound, 'modeled_initial_stop' => (string) $stop, 'sizing' => $sizing];
                        if ($sizing['reason'] !== null) {
                            $result = SignalRules::result('wait', $sizing['reason'], facts: $result['facts']);
                        }
                    }
                }
                $evidence = ['asset_class' => 'crypto', 'venue' => 'coinbase', 'mode' => 'paper_signal_evidence', 'broker_executable' => false,
                    'rule_version' => SignalRules::VERSION, 'adapter_version' => self::VERSION, 'parameters' => $parameters,
                    'policy_id' => $policy->id, 'policy_hash' => $policy->fingerprint, 'profile_id' => $storedProfile->id,
                    'profile_fingerprint' => $storedProfile->input_fingerprint, 'cutoff' => $cutoff->toISOString(), 'inputs' => $inputs,
                    'cost_schedule' => $cost, 'cost_valid' => $costValid, 'ledger_version' => $portfolio->ledger_version,
                    'cash' => $portfolio->cash, 'entry_pause' => $entryPause, 'watchlist_revision_id' => null, 'universe' => $products];
                StrategySignal::create(['mcp_connection_id' => null, 'asset_class' => 'crypto', 'venue' => 'coinbase', 'paper_portfolio_id' => $portfolio->id,
                    'cycle_id' => $cycle->id, 'symbol' => $product, 'profile_id' => $storedProfile->id, 'status' => $result['status'], 'side' => $result['side'],
                    'reason' => $result['reason'], 'rule_version' => SignalRules::VERSION, 'parameters_version' => $parameters['version'],
                    'input_fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
                    'payload' => ['evidence' => $evidence, 'parameters' => $parameters, 'result' => $result, 'mode' => 'paper_signal_evidence', 'broker_executable' => false],
                    'cutoff' => $cutoff, 'recorded_at' => now()]);
            }

            return $this->report($cycle);
        });
    }

    private function report(StrategyCycle $cycle): array
    {
        return ['status' => 'complete', 'cycle_id' => $cycle->id, 'mode' => 'paper_signal_evidence', 'broker_executable' => false,
            'signals' => StrategySignal::where('cycle_id', $cycle->id)->orderBy('symbol')->get()->map(fn ($s) => $s->only(['id', 'symbol', 'status', 'side', 'reason']))->all()];
    }
}
