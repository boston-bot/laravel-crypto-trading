<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use InvalidArgumentException;

/** Pure versioned thresholds. Facts are supplied by reviewed adapters, never watchlist/model fields. */
final class EligibilityRules
{
    public const VERSION = 'equity_eligibility_v1';

    public function thresholds(?array $quote, ?array $history, array $parameters): array
    {
        $checks = ['price' => $this->unknown('fresh_quote_unavailable'), 'spread' => $this->unknown('fresh_quote_unavailable'),
            'history' => $this->unknown('daily_history_source_unconfigured'), 'liquidity' => $this->unknown('daily_history_source_unconfigured')];
        if ($quote !== null) {
            try {
                $bid = Decimal::from($quote['bid'] ?? null);
                $ask = Decimal::from($quote['ask'] ?? null);
                if ($bid->compareTo(Decimal::from('0')) <= 0 || $ask->compareTo($bid) < 0) {
                    throw new InvalidArgumentException;
                }
                $checks['price'] = $this->check($bid->compareTo(Decimal::from($parameters['minimum_price'])) >= 0, 'minimum_price', ['bid' => (string) $bid]);
                // (ask - bid) / midpoint * 100 <= maximum spread; cross-multiply without rounding.
                $checks['spread'] = $this->check($ask->minus($bid)->multipliedBy(Decimal::from('200'))->compareTo(
                    Decimal::from($parameters['maximum_spread_pct'])->multipliedBy($ask->plus($bid))) <= 0, 'maximum_spread', ['bid' => (string) $bid, 'ask' => (string) $ask]);
            } catch (InvalidArgumentException) {
                $checks['price'] = $checks['spread'] = $this->unknown('quote_prices_invalid');
            }
        }
        if ($history !== null) {
            // Reserved adapter contract. No production provider currently supplies these facts.
            $checks['history'] = $this->unknown('daily_history_unverified');
            $checks['liquidity'] = $this->unknown('daily_history_unverified');
            if (($history['verified'] ?? false) === true && is_int($history['sessions'] ?? null)) {
                $checks['history'] = $this->check($history['sessions'] >= 252, 'minimum_history', ['sessions' => $history['sessions']]);
                try {
                    $volume = Decimal::from($history['average_dollar_volume_20'] ?? null);
                    if ($volume->compareTo(Decimal::from('0')) < 0) {
                        throw new InvalidArgumentException;
                    }
                    $checks['liquidity'] = $this->check($volume->compareTo(Decimal::from($parameters['minimum_dollar_volume'])) >= 0, 'minimum_dollar_volume', ['average_dollar_volume_20' => (string) $volume]);
                } catch (InvalidArgumentException) {
                    $checks['liquidity'] = $this->unknown('dollar_volume_invalid');
                }
            }
        }

        return $checks;
    }

    private function check(bool $passed, string $rule, array $values): array
    {
        return ['status' => $passed ? 'pass' : 'ineligible', 'reason' => $rule.($passed ? '_passed' : '_failed')] + $values;
    }

    private function unknown(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason];
    }
}
