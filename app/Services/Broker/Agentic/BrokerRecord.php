<?php

namespace App\Services\Broker\Agentic;

use App\Support\Mcp\Decimal;
use DateTimeImmutable;
use JsonSerializable;

/** Normalized records are intentionally distinct from the legacy crypto ledger. */
final readonly class BrokerRecord implements JsonSerializable
{
    public function __construct(public Operation $operation, public array $fields, public ?DateTimeImmutable $sourceAsOf) {}

    public static function from(Operation $operation, array $fields, ?DateTimeImmutable $sourceAsOf): self
    {
        $required = match ($operation) {
            Operation::Accounts => ['id', 'account_kind'],
            Operation::Portfolio => ['account_id'],
            Operation::Positions => ['account_id', 'quantity'],
            Operation::Orders => ['id', 'account_id', 'instrument_id', 'status', 'side'],
            Operation::Instruments => ['id', 'symbol'],
            Operation::Quotes => [],
            Operation::CashFlows => ['id', 'account_id', 'amount'],
            Operation::History => ['account_id'],
            Operation::Calendar => ['date', 'session_status'],
        };
        foreach ($required as $key) {
            if (! isset($fields[$key]) || $fields[$key] === '') {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        if (in_array($operation, [Operation::Positions, Operation::Quotes], true) && empty($fields['instrument_id']) && empty($fields['symbol'])) {
            throw new ReadFailure(ReadError::Malformed);
        }
        $decimals = ['quantity', 'filled_quantity', 'price', 'bid', 'ask', 'last', 'cash', 'equity', 'buying_power', 'market_value', 'amount', 'unleveraged_buying_power', 'average_buy_price', 'stop_price', 'average_price', 'fees', 'regular_last', 'extended_last', 'equity_value', 'options_value', 'crypto_value', 'futures_value', 'event_contracts_value', 'mutual_funds_value', 'fixed_income_value', 'pending_deposits'];
        $strings = ['id', 'account_id', 'instrument_id', 'account_kind', 'symbol', 'status', 'side', 'order_type', 'time_in_force', 'currency', 'date', 'session_status', 'nickname', 'account_type', 'brokerage_account_type', 'management_type', 'account_scope', 'asset_class', 'position_type', 'name', 'bid_as_of', 'ask_as_of', 'last_as_of', 'regular_last_as_of', 'extended_last_as_of', 'last_session'];
        foreach ($fields as $key => $value) {
            if (in_array($key, $decimals, true)) {
                try {
                    $fields[$key] = $value === null ? null : Decimal::from($value);
                } catch (\InvalidArgumentException) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            } elseif (in_array($key, ['agentic_allowed', 'deactivated', 'permanently_deactivated'], true)) {
                if (! is_bool($value)) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            } elseif (! in_array($key, $strings, true) || (! is_string($value) && $value !== null)) {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        foreach (['quantity', 'filled_quantity', 'price', 'bid', 'ask', 'last', 'market_value'] as $key) {
            if (isset($fields[$key]) && $fields[$key]->compareTo(Decimal::from('0')) < 0) {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        if ($operation === Operation::Quotes) {
            foreach (['bid', 'ask', 'last'] as $key) {
                if (isset($fields[$key]) && $fields[$key]->compareTo(Decimal::from('0')) <= 0) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            }
        }
        if (isset($fields['bid'], $fields['ask']) && $fields['bid']->compareTo($fields['ask']) > 0) {
            throw new ReadFailure(ReadError::Malformed);
        }
        if (isset($fields['side']) && ! in_array($fields['side'], ['buy', 'sell'], true)) {
            throw new ReadFailure(ReadError::Malformed);
        }
        if ($operation === Operation::Accounts && ! in_array($fields['account_kind'], ['agentic', 'personal', 'unknown'], true)) {
            throw new ReadFailure(ReadError::Malformed);
        }

        return new self($operation, $fields, $sourceAsOf);
    }

    public function jsonSerialize(): array
    {
        return ['data' => $this->fields, 'source_as_of' => $this->sourceAsOf?->format(DATE_ATOM)];
    }
}
