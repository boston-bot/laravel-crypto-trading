<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Models\User;
use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\SignalRules;
use App\Services\PaperTrading\StrategyReplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * C3 retrospective daily replay of the crypto sleeve's current policy over stored settled Coinbase daily candles, using the
 * shared replay engine in crypto limit mode. Labeled separately from forward paper results. No network, order or ledger change.
 */
final class CryptoReplays
{
    public const ADAPTER_VERSION = 'crypto_daily_replay_v1';

    public function __construct(private StrategyReplay $engine) {}

    public function run(int $actor, int $portfolioId, string $requestId, ?string $start = null): StrategyReplayRun
    {
        Validator::make(['request' => $requestId, 'start' => $start], ['request' => ['required', 'uuid'], 'start' => ['nullable', 'date_format:Y-m-d']])->validate();
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
            'equity' => $owner['initial_cash'], 'bars' => $fingerprints, 'as_of' => $today->toDateString(), 'start' => $start];
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

        return DB::transaction(function () use ($actor, $portfolioId, $requestId, $parameters, $complete, $reason, $result, $input, $products, $excluded) {
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
                    'result' => $result, 'mode' => 'crypto_retrospective_replay', 'broker_executable' => false],
                'recorded_at' => now()]);
        });
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
