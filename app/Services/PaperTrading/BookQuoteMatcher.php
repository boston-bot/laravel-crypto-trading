<?php

namespace App\Services\PaperTrading;

use App\Services\Broker\Agentic\TrustedHttp;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Joins independently sourced quote and book observations; does not establish instrument eligibility. */
final class BookQuoteMatcher
{
    public function match(array $payload, CarbonImmutable $cutoff, int $maxAge): array
    {
        $result = ['status' => 'unavailable', 'reason' => 'book_evidence_unverified', 'bid_size' => null, 'ask_size' => null];
        $book = $payload['execution_reads']['price_book'] ?? [];
        $q = $payload['quote'] ?? [];
        $fields = $book['fields'] ?? [];
        $expected = config('robinhood_mcp.operations.price_book.semantic_fingerprint');
        if (($payload['provider'] ?? null) !== TrustedHttp::RESOURCE || ($payload['complete'] ?? false) !== true ||
            ($book['provider'] ?? null) !== TrustedHttp::RESOURCE || ($book['complete'] ?? false) !== true ||
            ! is_string($expected) || ! is_string($book['semantic_fingerprint'] ?? null) || ! hash_equals($expected, $book['semantic_fingerprint']) ||
            ($fields['book_quantity_unit'] ?? null) !== 'shares' || ! is_string($q['symbol'] ?? null) || ($fields['symbol'] ?? null) !== $q['symbol']) {
            return $result;
        }
        try {
            $quoteRetrieved = CarbonImmutable::parse($this->time($q['retrieved_at'] ?? null));
            $bookRetrieved = CarbonImmutable::parse($this->time($book['retrieved_at'] ?? null));
            foreach ([[$q['bid_as_of'] ?? null, $quoteRetrieved], [$q['ask_as_of'] ?? null, $quoteRetrieved], [$book['source_as_of'] ?? null, $bookRetrieved]] as [$source, $retrieved]) {
                $source = CarbonImmutable::parse($this->time($source));
                if ($source->gt($retrieved) || $retrieved->gt($cutoff) || $source->lt($cutoff->subSeconds($maxAge))) {
                    return array_replace($result, ['reason' => 'book_or_quote_time_stale_or_future']);
                }
            }
            foreach (['bid', 'ask'] as $side) {
                $quotePrice = Decimal::from($q[$side] ?? null);
                $bookPrice = Decimal::from($fields['book_'.$side] ?? null);
                $size = $fields['book_'.$side.'_quantity'] ?? null;
                if (! is_string($size) || ! preg_match('/^\d{1,20}$/D', $size) || $quotePrice->compareTo(Decimal::from('0')) <= 0 ||
                    $quotePrice->compareTo($bookPrice) !== 0) {
                    return array_replace($result, ['reason' => 'book_prices_or_quantities_do_not_match_quote']);
                }
            }
            if (Decimal::from($q['bid'])->compareTo(Decimal::from($q['ask'])) > 0) {
                return array_replace($result, ['reason' => 'book_prices_or_quantities_do_not_match_quote']);
            }
        } catch (\InvalidArgumentException) {
            return array_replace($result, ['reason' => 'book_or_quote_fields_unknown']);
        }

        return ['status' => 'matched', 'reason' => null, 'bid_size' => $fields['book_bid_quantity'], 'ask_size' => $fields['book_ask_quantity']];
    }

    private function time(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new \InvalidArgumentException('Observation time is unknown.');
        }

        return $value;
    }
}
