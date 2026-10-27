<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Models\User;
use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\ResearchVariants;
use App\Services\PaperTrading\SignalRules;
use App\Services\PaperTrading\StrategyReplay;
use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * C3 retrospective daily replay of the crypto sleeve's current policy over stored settled Coinbase daily candles, using the
 * shared replay engine in crypto limit mode. Labeled separately from forward paper results. No network, order or ledger change.
 */
final class CryptoReplays
{
    // v2: research variants and independent early/late halves.
    public const ADAPTER_VERSION = 'crypto_daily_replay_v2';

    public const MAX_VARIANTS = 6;

    /** Research fee bounds per side; a variant states both rates and sizing still reserves the greater. */
    public const FEE_BOUNDS = ['0', '0.02'];

    public function __construct(private StrategyReplay $engine, private TradingCosts $costs) {}

    /** @param list<array{id: string, parameters?: array, risk?: array, costs?: array{maker_rate: string, taker_rate: string}}> $variants */
    public function run(int $actor, int $portfolioId, string $requestId, ?string $start = null, array $variants = []): StrategyReplayRun
    {
        Validator::make(['request' => $requestId, 'start' => $start], ['request' => ['required', 'uuid'], 'start' => ['nullable', 'date_format:Y-m-d']])->validate();
        $variants = $this->variants($variants);
        [$portfolio, $policy] = $this->authorize($actor, $portfolioId);
        if ($repeat = $this->repeat($portfolio, $requestId)) {
            return $repeat;
        }
        $now = CarbonImmutable::now();
        $today = $now->utc()->startOfDay();
        $parameters = $policy->payload['parameters'];
        $p = $parameters['values'];
        $benchmark = $p['benchmark_symbol'];
        $products = config('crypto.coinbase_products');
        abort_unless(is_array($products) && count($products) > 0 && count($products) <= 25, 422, 'Configure 1–25 collection products.');
        $products = array_values(array_unique([...$products, $benchmark]));
        sort($products);
        $bars = $instruments = $excluded = $fingerprints = $costs = [];
        foreach ($products as $product) {
            $history = $this->history($product, $now, $today);
            $snapshot = DB::table('mcp_crypto_product_snapshots')->where(['venue' => 'coinbase', 'product_id' => $product])->where('retrieved_at', '<=', CryptoMarketCollector::time($now))
                ->orderByDesc('id')->first();
            $cost = app(CryptoFeeObservations::class)->schedule($actor, $policy->payload, $product, $now);
            $reason = match (true) {
                $history['reason'] !== null => $history['reason'],
                $snapshot === null => 'product_metadata_unavailable',
                $cost === null => 'product_fee_schedule_unavailable_or_expired',
                default => null,
            };
            if ($reason !== null) {
                $excluded[$product] = $reason;

                continue;
            }
            $bars[$product] = $history['bars'];
            $fingerprints[$product] = hash('sha256', json_encode($history['bars'], JSON_THROW_ON_ERROR));
            $instruments[$product] = array_map(fn ($field) => CryptoMarketCollector::plain((string) $snapshot->{$field}),
                array_combine($fields = ['base_increment', 'price_increment', 'base_min_size', 'base_max_size', 'quote_min_size', 'quote_max_size'], $fields));
            $costs[$product] = $cost;
        }
        // One cost schedule per run; a product priced differently from the benchmark is excluded rather than mispriced.
        $cost = $costs[$benchmark] ?? null;
        foreach ($costs as $product => $schedule) {
            if ($cost !== null && [$schedule['maker_rate'], $schedule['taker_rate']] !== [$cost['maker_rate'], $cost['taker_rate']]) {
                $excluded[$product] = 'product_fee_schedule_differs_from_benchmark';
                unset($bars[$product], $instruments[$product], $fingerprints[$product]);
            }
        }
        $owner = $policy->payload['owner'];
        $settings = ['order_mode' => 'crypto_limit', 'instruments' => $instruments, 'profile_window' => $p['min_history_days'],
            'min_daily_quote_volume' => $p['min_quote_volume_24h'], 'slippage_bps' => $p['slippage_bps'], 'cost_schedule' => $cost,
            'risk_per_trade_pct' => $owner['risk_per_trade_pct'], 'max_position_pct' => $owner['max_position_pct'], 'max_open_positions' => $owner['max_open_positions']];
        $input = ['adapter' => self::ADAPTER_VERSION, 'replay' => StrategyReplay::VERSION, 'profile' => ProfileCalculator::VERSION, 'rules' => SignalRules::VERSION,
            'parameters' => $parameters['hash'], 'policy_id' => $policy->id, 'policy_hash' => $policy->fingerprint, 'settings' => $settings,
            'equity' => $owner['initial_cash'], 'bars' => $fingerprints, 'as_of' => $today->toDateString(), 'start' => $start, 'variants' => $variants];
        $reason = match (true) {
            ! isset($bars[$benchmark]) => 'benchmark_history_unavailable',
            $cost === null => 'benchmark_fee_schedule_unavailable',
            default => null,
        };
        $result = null;
        if ($reason === null) {
            $result = $this->engine->run($bars, $bars[$benchmark], $p, $settings, $owner['initial_cash'], $start, $today->subDay()->toDateString());
            $reason = $result['sessions'] > 0 ? 'retrospective_replay_recorded' : 'no_days_with_a_full_profile_window';
        }
        $complete = $reason === 'retrospective_replay_recorded';
        $comparison = null;
        if ($complete) {
            // Two independent halves, each from the starting cash: a change should help in both, not only in aggregate.
            $mid = CarbonImmutable::parse($result['start'])->addDays(intdiv((int) CarbonImmutable::parse($result['start'])->diffInDays(CarbonImmutable::parse($result['end'])), 2));
            $windows = ['early' => [$result['start'], $mid->toDateString()], 'late' => [$mid->addDay()->toDateString(), $result['end']]];
            $replay = fn (array $values, array $with, ?string $from, ?string $to) => $this->engine->run($bars, $bars[$benchmark], $values, $with, $owner['initial_cash'], $from, $to);
            $compare = function (array $values, array $with, array $full) use ($replay, $windows) {
                $halves = [];
                foreach ($windows as $name => [$from, $to]) {
                    $halves[$name] = $this->summary($replay($values, $with, $from, $to), $with['cost_schedule']);
                }

                return ['full' => $this->summary($full, $with['cost_schedule'])] + $halves;
            };
            $comparison = ['windows' => $windows, 'baseline' => $compare($p, $settings, $result), 'variants' => []];
            foreach ($variants as $variant) {
                $values = array_replace($p, $variant['parameters']);
                $with = array_replace($settings, $variant['risk'], $variant['costs'] === null ? [] : ['cost_schedule' => array_replace($cost, $variant['costs'], ['version' => 'research_variant_'.$variant['id']])]);
                $full = $replay($values, $with, $start, $today->subDay()->toDateString());
                $comparison['variants'][] = $variant + ['parameters_hash' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)), 'result' => $compare($values, $with, $full),
                    'trades' => $full['trades']];
            }
        }

        return DB::transaction(function () use ($actor, $portfolioId, $requestId, $parameters, $complete, $reason, $result, $input, $products, $excluded, $comparison) {
            [$portfolio] = $this->authorize($actor, $portfolioId, true);
            if ($repeat = $this->repeat($portfolio, $requestId)) {
                return $repeat;
            }

            return StrategyReplayRun::create(['mcp_connection_id' => null, 'paper_portfolio_id' => $portfolio->id, 'asset_class' => 'crypto', 'venue' => 'coinbase',
                'scope' => $portfolio->scope, 'request_id' => $requestId, 'actor_id' => $actor, 'replay_version' => StrategyReplay::VERSION,
                'profile_version' => ProfileCalculator::VERSION, 'rule_version' => SignalRules::VERSION, 'parameters_hash' => $parameters['hash'],
                'status' => $complete ? 'complete' : 'incomplete', 'reason' => $reason, 'start_date' => $complete ? $result['start'] : null,
                'end_date' => $complete ? $result['end'] : null, 'input_fingerprint' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
                'payload' => ['label' => 'retrospective_daily_replay_not_forward_results', 'input' => $input, 'symbols' => $products, 'excluded' => $excluded,
                    'result' => $result, 'comparison' => $comparison, 'mode' => 'crypto_retrospective_replay', 'broker_executable' => false],
                'recorded_at' => now()]);
        });
    }

    /** Comparable figures for one replay: stats, benchmarks, exit-reason breakdown and the fees charged on its trades. */
    private function summary(array $result, array $cost): array
    {
        $reasons = [];
        $fees = Decimal::from('0');
        foreach ($result['trades'] as $trade) {
            $quantity = Decimal::from($trade['quantity']);
            $fees = $fees->plus($this->costs->fee($cost, 'BUY', $quantity, Decimal::from($trade['entry_price'])))
                ->plus($this->costs->fee($cost, 'SELL', $quantity, Decimal::from($trade['exit_price'])));
            $reasons[$trade['reason']] ??= ['trades' => 0, 'net' => Decimal::from('0')];
            $reasons[$trade['reason']]['trades']++;
            $reasons[$trade['reason']]['net'] = $reasons[$trade['reason']]['net']->plus(Decimal::from($trade['net']));
        }
        ksort($reasons);

        return ['start' => $result['start'], 'end' => $result['end'], 'days' => $result['sessions'], 'final_equity' => $result['final_equity'],
            'stats' => $result['stats'], 'benchmarks' => $result['benchmarks'], 'counts' => $result['counts'], 'fees' => (string) $fees->rounded(2, RoundingMode::HALF_EVEN),
            'exit_reasons' => array_map(fn ($r) => ['trades' => $r['trades'], 'net' => (string) $r['net']->rounded(2, RoundingMode::HALF_EVEN)], $reasons)];
    }

    /** Research-only variants within the reviewed bounds; they never change the policy, ledger or forward cycle. */
    private function variants(array $variants): array
    {
        abort_unless(array_is_list($variants) && count($variants) <= self::MAX_VARIANTS, 422, 'Use at most '.self::MAX_VARIANTS.' variants.');
        $seen = [hash('sha256', json_encode([[], [], null], JSON_THROW_ON_ERROR)) => 'baseline'];
        $normalized = [];
        foreach ($variants as $variant) {
            abort_unless(is_array($variant) && ! array_diff(array_keys($variant), ['id', 'parameters', 'risk', 'costs']) && is_string($variant['id'] ?? null) &&
                preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $variant['id']) && $variant['id'] !== 'baseline' && is_array($variant['parameters'] ?? []) &&
                is_array($variant['risk'] ?? []) && (! isset($variant['costs']) || is_array($variant['costs'])), 422, 'Malformed replay variant.');
            $parameters = ResearchVariants::parameters($variant['parameters'] ?? []);
            ksort($parameters);
            $risk = ResearchVariants::risk($variant['risk'] ?? []);
            $costs = null;
            if (isset($variant['costs'])) {
                abort_unless(array_keys($variant['costs']) == ['maker_rate', 'taker_rate'] || array_keys($variant['costs']) == ['taker_rate', 'maker_rate'], 422, 'State both research fee rates.');
                foreach ($variant['costs'] as $rate) {
                    abort_unless(is_string($rate) && preg_match('/^0(?:\.\d{1,6})?$/D', $rate) && Decimal::from($rate)->compareTo(Decimal::from(self::FEE_BOUNDS[1])) <= 0, 422, 'Research fee rate outside reviewed bounds.');
                }
                $costs = ['maker_rate' => $variant['costs']['maker_rate'], 'taker_rate' => $variant['costs']['taker_rate']];
            }
            $key = hash('sha256', json_encode([$parameters, $risk, $costs], JSON_THROW_ON_ERROR));
            abort_if(isset($seen[$key]) || in_array($variant['id'], $seen, true), 422, 'Duplicate replay variant.');
            $seen[$key] = $variant['id'];
            $normalized[] = ['id' => $variant['id'], 'parameters' => $parameters, 'risk' => $risk, 'costs' => $costs];
        }

        return $normalized;
    }

    /** Settled daily candles from successful reads, contiguous from the product's first stored day to yesterday (UTC). */
    private function history(string $product, CarbonImmutable $now, CarbonImmutable $today): array
    {
        $rows = DB::table('mcp_crypto_candles')->where(['venue' => 'coinbase', 'product_id' => $product, 'granularity' => 'ONE_DAY'])
            ->where('ends_at', '<=', CryptoMarketCollector::time($today))->where('retrieved_at', '<=', CryptoMarketCollector::time($now))
            ->whereIn('mcp_crypto_market_read_id', DB::table('mcp_crypto_market_reads')->where('venue', 'coinbase')
                ->whereIn('status', ['complete', 'partial'])->where('finished_at', '<=', CryptoMarketCollector::time($now))->select('id'))
            ->orderBy('starts_at')->get();
        if ($rows->isEmpty()) {
            return ['reason' => 'daily_history_unavailable', 'bars' => []];
        }
        if (DB::table('mcp_crypto_candle_revisions')->where(['venue' => 'coinbase', 'product_id' => $product, 'granularity' => 'ONE_DAY'])->exists()) {
            return ['reason' => 'daily_candle_revision_requires_review', 'bars' => []];
        }
        $bars = [];
        $next = CarbonImmutable::parse($rows->first()->starts_at)->utc();
        foreach ($rows as $row) {
            if (! CarbonImmutable::parse($row->starts_at)->eq($next) || ! CarbonImmutable::parse($row->ends_at)->eq($next->addDay())) {
                return ['reason' => 'daily_history_gap', 'bars' => []];
            }
            $bars[] = [$next->toDateString(), ...array_map(fn ($v) => CryptoMarketCollector::plain((string) $v), [$row->open, $row->high, $row->low, $row->close, $row->volume])];
            $next = $next->addDay();
        }

        return $next->eq($today) ? ['reason' => null, 'bars' => $bars] : ['reason' => 'daily_history_not_current', 'bars' => []];
    }

    private function authorize(int $actor, int $portfolioId, bool $lock = false): array
    {
        $user = User::findOrFail($actor);
        abort_unless(in_array($user->email, config('mcp.operator_emails'), true), 403);
        $portfolio = Portfolio::whereKey($portfolioId)->where(['actor_id' => $actor, 'asset_class' => 'crypto', 'venue' => 'coinbase'])->whereNull('mcp_connection_id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $policy = InstrumentPolicy::where('paper_portfolio_id', $portfolio->id)->latest('version')->firstOrFail();
        abort_unless(($policy->payload['version'] ?? null) === CryptoPolicies::VERSION && $policy->payload['parameters'] === CryptoParameters::current(), 409, 'Policy version is unavailable or incompatible.');

        return [$portfolio, $policy];
    }

    private function repeat(Portfolio $portfolio, string $requestId): ?StrategyReplayRun
    {
        return StrategyReplayRun::where(['paper_portfolio_id' => $portfolio->id, 'asset_class' => 'crypto', 'request_id' => $requestId])->first();
    }
}
