<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Enums\BrokerType;
use App\Models\PaperTrading\CryptoFeeObservation;
use App\Services\Broker\BrokerCredentialResolver;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Venue cost study: pairs Robinhood Crypto's spread-inclusive quotes with Coinbase's book at the same moment and appends
 * one line per product to a private file. Costs are in basis points of the Coinbase mid price. Read-only; no orders.
 */
final class CryptoSpreadStudy
{
    public const VERSION = 'crypto_venue_cost_sample_v1';

    public function __construct(private RobinhoodCryptoQuotes $robinhood, private CoinbasePublicMarketData $coinbase, private BrokerCredentialResolver $credentials) {}

    public function sample(): array
    {
        if (! config('crypto.spread_study_enabled')) {
            return ['status' => 'disabled'];
        }
        $symbols = config('crypto.spread_study_symbols');
        $now = CarbonImmutable::now();
        try {
            $credential = $this->credentials->resolve(BrokerType::ROBINHOOD) ?? throw new \RuntimeException;
            $quotes = $this->robinhood->read($credential, $symbols);
        } catch (Throwable) {
            $this->append(['version' => self::VERSION, 'at' => $now->toISOString(), 'status' => 'robinhood_unavailable']);

            return ['status' => 'robinhood_unavailable'];
        }
        $fees = CryptoFeeObservation::where(['venue' => 'coinbase', 'status' => 'complete'])->latest('retrieved_at')->first();
        $bps = fn (Decimal $part, Decimal $mid) => (string) $part->multipliedBy(Decimal::from('10000'))->dividedBy($mid, 2);
        $rows = [];
        foreach ($symbols as $symbol) {
            try {
                $book = $this->coinbase->bestBidAsk($symbol, CarbonImmutable::now());
            } catch (Throwable) {
                $rows[] = ['symbol' => $symbol, 'status' => 'coinbase_unavailable'];

                continue;
            }
            $quote = $quotes[$symbol] ?? null;
            if ($quote === null) {
                $rows[] = ['symbol' => $symbol, 'status' => 'robinhood_symbol_missing'];

                continue;
            }
            $bid = Decimal::from($book['bid']);
            $ask = Decimal::from($book['ask']);
            $mid = $bid->plus($ask)->dividedBy(Decimal::from('2'), 18);
            $half = $ask->minus($mid);
            $taker = $fees === null ? null : Decimal::from(CryptoMarketCollector::plain((string) $fees->taker_rate))->multipliedBy($mid);
            $maker = $fees === null ? null : Decimal::from(CryptoMarketCollector::plain((string) $fees->maker_rate))->multipliedBy($mid);
            $rows[] = ['symbol' => $symbol, 'status' => 'complete', 'coinbase_bid' => $book['bid'], 'coinbase_ask' => $book['ask'],
                'coinbase_source_at' => $book['source_at']->toISOString(), 'robinhood_bid' => $quote['bid'], 'robinhood_ask' => $quote['ask'],
                'robinhood_source_at' => $quote['source_at'],
                // Cost of one side relative to the Coinbase mid: crossing the spread plus any fee.
                'coinbase_taker_side_bps' => $taker === null ? null : $bps($half->plus($taker), $mid),
                'coinbase_maker_side_bps' => $maker === null ? null : $bps($maker->minus($half), $mid),
                'robinhood_buy_side_bps' => $bps(Decimal::from($quote['ask'])->minus($mid), $mid),
                'robinhood_sell_side_bps' => $bps($mid->minus(Decimal::from($quote['bid'])), $mid)];
        }
        $this->append(['version' => self::VERSION, 'at' => $now->toISOString(), 'status' => 'complete', 'coinbase_fee_observation_id' => $fees?->id, 'rows' => $rows]);

        return ['status' => 'complete', 'rows' => $rows];
    }

    private function append(array $line): void
    {
        $path = config('crypto.spread_study_path');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        chmod($path, 0600);
    }
}
