<?php

namespace App\Services\Broker\Agentic;

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
            default => throw new ReadFailure(ReadError::Unsupported),
        };
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
