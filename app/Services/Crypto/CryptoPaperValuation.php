<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategyCycle;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Crypto sleeve valuation from the simulated ledger and stored quotes. Unknown marks are unknown, never zero. */
final class CryptoPaperValuation
{
    /** @return array<string, string> positive simulated holdings by product */
    public function held(Portfolio $portfolio): array
    {
        $held = [];
        foreach (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->orderBy('symbol')->get() as $position) {
            $quantity = Decimal::from($position->quantity);
            if ($quantity->compareTo(Decimal::from('0')) > 0) {
                $held[$position->symbol] = (string) Decimal::from($held[$position->symbol] ?? '0')->plus($quantity);
            }
        }

        return $held;
    }

    /** Newest stored quote published by a successful read at the cutoff, optionally strictly after a watermark. */
    public function quote(string $product, CarbonImmutable $cutoff, int $maxAge, ?CarbonImmutable $after = null): ?object
    {
        $at = CryptoMarketCollector::time($cutoff);
        $quote = DB::table('mcp_crypto_quotes')->where(['venue' => 'coinbase', 'product_id' => $product])
            ->whereIn('mcp_crypto_market_read_id', DB::table('mcp_crypto_market_reads')->where('venue', 'coinbase')
                ->whereIn('status', ['complete', 'partial'])->where('finished_at', '<=', $at)->select('id'))
            ->where('retrieved_at', '<=', $at)->where('source_at', '<=', $at)
            ->when($after !== null, fn ($q) => $q->where('source_at', '>', CryptoMarketCollector::time($after)))
            ->orderByDesc('source_at')->orderByDesc('id')->first();

        return $quote && CarbonImmutable::parse($quote->source_at)->gte($cutoff->subSeconds($maxAge)) ? $quote : null;
    }

    public function value(Portfolio $portfolio, CarbonImmutable $cutoff, int $maxAge): array
    {
        $equity = Decimal::from($portfolio->cash);
        $marks = [];
        $missing = [];
        foreach ($this->held($portfolio) as $product => $quantity) {
            $quote = $this->quote($product, $cutoff, $maxAge);
            if (! $quote || Decimal::from(CryptoMarketCollector::plain((string) $quote->bid))->compareTo(Decimal::from('0')) <= 0) {
                $missing[] = $product;

                continue;
            }
            $bid = CryptoMarketCollector::plain((string) $quote->bid);
            $marks[$product] = ['quote_id' => $quote->id, 'bid' => $bid, 'source_at' => CarbonImmutable::parse($quote->source_at)->toISOString(), 'quantity' => $quantity];
            $equity = $equity->plus(Decimal::from($quantity)->multipliedBy(Decimal::from($bid)));
        }

        return ['equity' => $missing === [] ? (string) $equity : null, 'cash' => (string) Decimal::from($portfolio->cash),
            'reserved_cash' => (string) Decimal::from($portfolio->reserved_cash), 'marks' => $marks, 'missing_marks' => $missing];
    }

    /**
     * Equity at the UTC day boundary: ledger cash and fills recorded by then, with holdings marked at the open of the stored
     * five-minute candle that starts at the boundary. A sleeve opened during the day starts from its opening cash.
     */
    public function baseline(Portfolio $portfolio, CarbonImmutable $cutoff): array
    {
        $boundary = $cutoff->utc()->startOfDay();
        $at = $boundary->format('Y-m-d H:i:s.u');
        $entry = CashEntry::where('paper_portfolio_id', $portfolio->id)->where('recorded_at', '<=', $at)->orderByDesc('recorded_at')->orderByDesc('id')->first();
        if (! $entry) {
            $opening = CashEntry::where('paper_portfolio_id', $portfolio->id)->where('kind', 'opening_balance')->first();
            if (! $opening || $opening->recorded_at->lte($boundary)) {
                return ['status' => 'unavailable', 'reason' => 'baseline_ledger_unavailable', 'boundary' => $boundary->toISOString()];
            }

            return ['status' => 'complete', 'kind' => 'opened_during_day', 'boundary' => $boundary->toISOString(),
                'equity' => (string) Decimal::from($opening->amount), 'cash' => (string) Decimal::from($opening->amount), 'holdings' => [], 'marks' => []];
        }
        $holdings = [];
        $orders = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->get(['id', 'symbol', 'side'])->keyBy('id');
        foreach (SimulationEvent::where('kind', 'fill')->whereIn('sim_order_id', $orders->keys())->where('recorded_at', '<=', $at)->orderBy('id')->get() as $fill) {
            $order = $orders[$fill->sim_order_id];
            $signed = Decimal::from($fill->quantity)->multipliedBy(Decimal::from($order->side === 'BUY' ? '1' : '-1'));
            $holdings[$order->symbol] = (string) Decimal::from($holdings[$order->symbol] ?? '0')->plus($signed);
        }
        $equity = Decimal::from($entry->balance_after);
        $marks = [];
        foreach ($holdings as $product => $quantity) {
            if (Decimal::from($quantity)->compareTo(Decimal::from('0')) === 0) {
                unset($holdings[$product]);

                continue;
            }
            $candle = DB::table('mcp_crypto_candles')->where(['venue' => 'coinbase', 'product_id' => $product, 'granularity' => 'FIVE_MINUTE'])
                ->where('starts_at', CryptoMarketCollector::time($boundary))->where('retrieved_at', '<=', CryptoMarketCollector::time($cutoff))
                ->whereIn('mcp_crypto_market_read_id', DB::table('mcp_crypto_market_reads')->where('venue', 'coinbase')
                    ->whereIn('status', ['complete', 'partial'])->where('finished_at', '<=', CryptoMarketCollector::time($cutoff))->select('id'))
                ->orderBy('id')->first();
            if (! $candle) {
                return ['status' => 'unavailable', 'reason' => 'baseline_mark_unavailable', 'boundary' => $boundary->toISOString(), 'product' => $product];
            }
            $open = CryptoMarketCollector::plain((string) $candle->open);
            $marks[$product] = ['candle_id' => $candle->id, 'open' => $open];
            $equity = $equity->plus(Decimal::from($quantity)->multipliedBy(Decimal::from($open)));
        }

        return ['status' => 'complete', 'kind' => 'ledger_at_boundary', 'boundary' => $boundary->toISOString(), 'equity' => (string) $equity,
            'cash' => (string) Decimal::from($entry->balance_after), 'cash_entry_id' => $entry->id, 'holdings' => $holdings, 'marks' => $marks];
    }

    /** A daily loss pause recorded earlier in the same UTC day stays in force until the next boundary. */
    public function latched(Portfolio $portfolio, CarbonImmutable $cutoff): bool
    {
        $cycles = StrategyCycle::where(['paper_portfolio_id' => $portfolio->id, 'asset_class' => 'crypto'])
            ->where('cycle_at', '>=', $cutoff->utc()->startOfDay()->format('Y-m-d H:i:s.u'))->where('cycle_at', '<', $cutoff->format('Y-m-d H:i:s.u'))->get();

        return $cycles->contains(fn ($cycle) => ($cycle->payload['entry_pause'] ?? null) === 'daily_loss_pause');
    }
}
