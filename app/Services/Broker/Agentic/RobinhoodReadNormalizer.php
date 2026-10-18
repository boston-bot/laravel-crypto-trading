<?php

namespace App\Services\Broker\Agentic;

use App\Support\Mcp\Decimal;

final class RobinhoodReadNormalizer
{
    public static function fields(Operation $operation, array $row, ?string $accountId): array
    {
        $scope = ['account_id' => $accountId, 'account_scope' => 'request_binding'];

        return match ($operation) {
            Operation::Portfolio => $scope + ['equity' => $row['total_value'] ?? null, 'cash' => $row['cash'] ?? null,
                'currency' => $row['currency'] ?? null, 'buying_power' => $row['buying_power']['buying_power'] ?? null,
                'unleveraged_buying_power' => $row['buying_power']['unleveraged_buying_power'] ?? null,
                'equity_value' => $row['equity_value'] ?? null, 'options_value' => $row['options_value'] ?? null,
                'crypto_value' => $row['crypto_value'] ?? null, 'futures_value' => $row['futures_value'] ?? null,
                'event_contracts_value' => $row['event_contracts_value'] ?? null, 'mutual_funds_value' => $row['mutual_funds_value'] ?? null,
                'fixed_income_value' => $row['fixed_income_value'] ?? null, 'pending_deposits' => $row['pending_deposits'] ?? null],
            // The positions tool supplies symbols, not broker position/instrument IDs. Do not fabricate IDs.
            Operation::Positions => $scope + ['symbol' => $row['symbol'] ?? null, 'quantity' => $row['quantity'] ?? null,
                'average_buy_price' => $row['average_buy_price'] ?? null, 'position_type' => $row['type'] ?? null, 'asset_class' => 'equity'],
            Operation::Orders => $scope + ['id' => $row['id'] ?? null, 'instrument_id' => $row['instrument_id'] ?? null,
                'symbol' => $row['symbol'] ?? null, 'status' => $row['state'] ?? null, 'side' => $row['side'] ?? null,
                'order_type' => $row['type'] ?? null, 'quantity' => $row['quantity'] ?? null,
                'filled_quantity' => $row['cumulative_quantity'] ?? null, 'price' => $row['price'] ?? null,
                'stop_price' => $row['stop_price'] ?? null, 'average_price' => $row['average_price'] ?? null,
                'fees' => $row['fees'] ?? null, 'time_in_force' => $row['time_in_force'] ?? null, 'asset_class' => 'equity'],
            Operation::Instruments => ['id' => $row['instrument_id'] ?? null, 'symbol' => $row['symbol'] ?? null,
                'name' => $row['name'] ?? null, 'asset_class' => 'equity'],
            Operation::Quotes => self::quote($row),
            Operation::PriceBook => self::book($row),
            Operation::Tradability => $scope + ['symbol' => $row['symbol'] ?? null, 'broker_tradeable' => $row['tradeable'] ?? null,
                'fractional_tradability' => $row['fractional_tradability'] ?? null,
                'extended_hours_fractional_tradability' => $row['extended_hours_fractional_tradability'] ?? null,
                'internal_halt_reason' => $row['internal_halt_reason'] ?? null],
            Operation::Watchlists => ['id' => $row['id'] ?? null, 'name' => $row['display_name'] ?? null,
                'owner_type' => $row['owner_type'] ?? null, 'item_count' => $row['item_count'] ?? null],
            Operation::WatchlistItems => ['id' => $row['object_id'] ?? null, 'object_type' => $row['object_type'] ?? null,
                'symbol' => $row['symbol'] ?? null],
            default => throw new ReadFailure(ReadError::Unsupported),
        };
    }

    private static function book(array $row): array
    {
        $best = [];
        foreach (['bids' => 'bid', 'asks' => 'ask'] as $side => $key) {
            $best[$key] = null;
            $best[$key.'_quantity'] = null;
            $levels = $row[$side] ?? null;
            if ($levels === null) {
                continue;
            }
            if (! is_array($levels) || ! array_is_list($levels) || count($levels) > 10000) {
                throw new ReadFailure(ReadError::Malformed);
            }
            $seenPrices = [];
            foreach ($levels as $level) {
                if (! is_array($level) || ! is_string($level['price'] ?? null) || ! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $level['price']) ||
                    ! is_int($level['quantity'] ?? null) || $level['quantity'] < 0) {
                    throw new ReadFailure(ReadError::Malformed);
                }
                $price = Decimal::from($level['price']);
                if ($price->compareTo(Decimal::from('0')) <= 0) {
                    throw new ReadFailure(ReadError::Malformed);
                }
                $priceKey = str_contains((string) $price, '.') ? rtrim(rtrim((string) $price, '0'), '.') : (string) $price;
                if (isset($seenPrices[$priceKey])) {
                    // No venue/order IDs establish whether repeated levels are distinct liquidity.
                    throw new ReadFailure(ReadError::Incomplete);
                }
                $seenPrices[$priceKey] = true;
                $comparison = $best[$key] === null ? null : $price->compareTo(Decimal::from($best[$key]));
                if ($comparison === null || ($side === 'bids' ? $comparison > 0 : $comparison < 0)) {
                    $best[$key] = (string) $price;
                    $best[$key.'_quantity'] = (string) $level['quantity'];
                }
            }
        }
        if ($best['bid'] !== null && $best['ask'] !== null && Decimal::from($best['bid'])->compareTo(Decimal::from($best['ask'])) > 0) {
            throw new ReadFailure(ReadError::Malformed);
        }

        return ['symbol' => $row['symbol'] ?? null, 'book_bid' => $best['bid'], 'book_ask' => $best['ask'],
            'book_bid_quantity' => $best['bid_quantity'], 'book_ask_quantity' => $best['ask_quantity'],
            'book_quantity_unit' => 'broker_quantity_unverified'];
    }

    private static function quote(array $row): array
    {
        if (! is_array($row['quote'] ?? null)) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        $quote = $row['quote'];

        return ['symbol' => $quote['symbol'] ?? null, 'bid' => $quote['bid_price'] ?? null,
            'ask' => $quote['ask_price'] ?? null, 'last' => $quote['last_trade_price'] ?? null,
            'bid_as_of' => self::time($quote['venue_bid_time'] ?? null),
            'ask_as_of' => self::time($quote['venue_ask_time'] ?? null),
            'last_as_of' => self::time($quote['venue_last_trade_time'] ?? null),
            'regular_last' => $quote['last_trade_price'] ?? null, 'regular_last_as_of' => self::time($quote['venue_last_trade_time'] ?? null),
            'extended_last' => $quote['last_non_reg_trade_price'] ?? null, 'extended_last_as_of' => self::time($quote['venue_last_non_reg_trade_time'] ?? null),
            'last_session' => 'regular', 'asset_class' => 'equity'];
    }

    private static function time(mixed $value): ?string
    {
        // The broker uses empty strings when a quote component's source time is unavailable.
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new ReadFailure(ReadError::Malformed);
        }

        return $value;
    }
}
