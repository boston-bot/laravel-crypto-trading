<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Coinbase stored-data adapter. Availability time and UTC day continuity are checked before shared rules run. */
class CryptoStrategyInputs
{
    public function read(string $productId, CarbonImmutable $cutoff, array $p): array
    {
        $at = CryptoMarketCollector::time($cutoff);
        $reads = DB::table('mcp_crypto_market_reads')->where('venue', 'coinbase')->whereIn('status', ['complete', 'partial'])
            ->where('finished_at', '<=', $at)->select('id');
        $metadata = DB::table('mcp_crypto_product_snapshots')->where(['venue' => 'coinbase', 'product_id' => $productId])
            ->whereIn('mcp_crypto_market_read_id', $reads)->where('retrieved_at', '<=', $at)->orderByDesc('id')->first();
        $quoteRow = DB::table('mcp_crypto_quotes')->where(['venue' => 'coinbase', 'product_id' => $productId])
            ->whereIn('mcp_crypto_market_read_id', $reads)->where('retrieved_at', '<=', $at)->where('source_at', '<=', $at)
            ->orderByDesc('source_at')->orderByDesc('id')->first();
        $reasons = [];
        $product = $metadata ? (array) $metadata : null;
        if (! $metadata || CarbonImmutable::parse($metadata->retrieved_at)->lt($cutoff->subSeconds($p['metadata_max_age_seconds']))) {
            $reasons[] = 'product_metadata_unavailable_or_stale';
        } else {
            if ($metadata->product_type !== 'SPOT' || $metadata->quote_currency !== 'USD' || $metadata->status !== 'online') {
                $reasons[] = 'product_not_online_usd_spot';
            }
            if ($metadata->product_id !== $metadata->base_currency.'-'.$metadata->quote_currency) {
                $reasons[] = 'product_currency_identity_mismatch';
            }
            foreach (['trading_disabled', 'is_disabled', 'view_only', 'cancel_only', 'post_only', 'auction_mode'] as $flag) {
                if ($metadata->{$flag}) {
                    $reasons[] = 'product_'.$flag;
                }
            }
            foreach (['base_increment', 'quote_increment', 'price_increment', 'base_min_size', 'base_max_size', 'quote_min_size', 'quote_max_size'] as $field) {
                $product[$field] = CryptoMarketCollector::plain((string) $metadata->{$field});
                if (Decimal::from($product[$field])->compareTo(Decimal::from('0')) <= 0) {
                    $reasons[] = 'product_constraints_invalid';
                }
            }
            if (Decimal::from($product['base_min_size'])->compareTo(Decimal::from($product['base_max_size'])) > 0 ||
                Decimal::from($product['quote_min_size'])->compareTo(Decimal::from($product['quote_max_size'])) > 0) {
                $reasons[] = 'product_constraints_invalid';
            }
            if ($metadata->quote_volume_24h === null) {
                $reasons[] = 'quote_volume_unknown';
            } elseif (Decimal::from((string) $metadata->quote_volume_24h)->compareTo(Decimal::from($p['min_quote_volume_24h'])) < 0) {
                $reasons[] = 'quote_volume_too_low';
            }
        }
        $quote = null;
        if (! $quoteRow || CarbonImmutable::parse($quoteRow->source_at)->lt($cutoff->subSeconds($p['quote_max_age_seconds']))) {
            $reasons[] = 'quote_unavailable_or_stale';
        } else {
            $quote = array_map(fn ($v) => CryptoMarketCollector::plain((string) $v),
                ['bid' => $quoteRow->bid, 'ask' => $quoteRow->ask, 'bid_size' => $quoteRow->bid_size, 'ask_size' => $quoteRow->ask_size]);
            $bid = Decimal::from($quote['bid']);
            $ask = Decimal::from($quote['ask']);
            if ($bid->compareTo(Decimal::from('0')) <= 0 || $ask->compareTo($bid) < 0 ||
                Decimal::from($quote['bid_size'])->compareTo(Decimal::from('0')) <= 0 || Decimal::from($quote['ask_size'])->compareTo(Decimal::from('0')) <= 0) {
                $reasons[] = 'quote_invalid_or_empty';
            } elseif ($ask->minus($bid)->multipliedBy(Decimal::from('20000'))->compareTo($ask->plus($bid)->multipliedBy(Decimal::from($p['max_spread_bps']))) > 0) {
                $reasons[] = 'spread_too_wide';
            }
        }
        $history = $this->history($productId, $cutoff, $p['min_history_days']);
        $benchmark = $productId === $p['benchmark_symbol'] ? $history : $this->history($p['benchmark_symbol'], $cutoff, $p['min_history_days']);
        if (! $history['complete']) {
            $reasons[] = 'daily_history_missing_or_discontinuous';
        }
        if (! $benchmark['complete']) {
            $reasons[] = 'benchmark_history_missing_or_discontinuous';
        }
        // A reported contradictory observation is not silently treated as verified history.
        $conflicts = DB::table('mcp_crypto_market_reads')->where('venue', 'coinbase')->where('finished_at', '<=', $at)
            ->where('candle_conflicts', '>', 0)->whereIn('id', DB::table('mcp_crypto_product_snapshots')->whereIn('product_id', [$productId, $p['benchmark_symbol']])->select('mcp_crypto_market_read_id'))->exists();
        if ($conflicts) {
            $reasons[] = 'candle_conflict_requires_review';
        }

        return ['status' => $reasons === [] ? 'eligible' : 'unavailable', 'reasons' => array_values(array_unique($reasons)),
            'product' => $product, 'quote' => $quote, 'bars' => $history['bars'], 'benchmark' => $benchmark['bars'],
            'evidence' => ['metadata_id' => $metadata?->id, 'quote_id' => $quoteRow?->id,
                'quote_source_at' => $quoteRow?->source_at, 'quote_retrieved_at' => $quoteRow?->retrieved_at,
                'history_ids' => $history['ids'], 'benchmark_ids' => $benchmark['ids'], 'day_boundary' => $cutoff->utc()->startOfDay()->toISOString()]];
    }

    private function history(string $productId, CarbonImmutable $cutoff, int $days): array
    {
        $end = $cutoff->utc()->startOfDay();
        $rows = DB::table('mcp_crypto_candles')->where(['venue' => 'coinbase', 'product_id' => $productId, 'granularity' => 'ONE_DAY'])
            ->where('starts_at', '>=', CryptoMarketCollector::time($end->subDays($days)))
            ->where('ends_at', '<=', CryptoMarketCollector::time($end))->where('retrieved_at', '<=', CryptoMarketCollector::time($cutoff))
            ->whereIn('mcp_crypto_market_read_id', DB::table('mcp_crypto_market_reads')->where('venue', 'coinbase')
                ->whereIn('status', ['complete', 'partial'])->where('finished_at', '<=', CryptoMarketCollector::time($cutoff))->select('id'))
            ->orderBy('starts_at')->get();
        $next = $end->subDays($days);
        $bars = $ids = [];
        $complete = $rows->count() === $days;
        foreach ($rows as $row) {
            if (! CarbonImmutable::parse($row->starts_at)->eq($next) || ! CarbonImmutable::parse($row->ends_at)->eq($next->addDay())) {
                $complete = false;
            }
            $bars[] = [$next->toDateString(), ...array_map(fn ($v) => CryptoMarketCollector::plain((string) $v), [$row->open, $row->high, $row->low, $row->close, $row->volume])];
            $ids[] = $row->id;
            $next = $next->addDay();
        }

        return ['complete' => $complete, 'bars' => $complete ? $bars : [], 'ids' => $ids];
    }
}
