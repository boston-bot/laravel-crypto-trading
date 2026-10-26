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
            Operation::Classification => ['id', 'symbol'],
            Operation::Dividends => ['symbol'],
            Operation::Quotes => [],
            Operation::Tradability => ['account_id', 'symbol', 'broker_tradeable'],
            Operation::PriceBook => ['symbol', 'book_quantity_unit'],
            Operation::Watchlists => ['id', 'name', 'owner_type', 'item_count'],
            Operation::WatchlistItems => ['id', 'object_type'],
            Operation::EarningsCalendar => ['id', 'symbol', 'fiscal_year', 'fiscal_quarter'],
            Operation::CashFlows => ['id', 'account_id', 'amount'],
            Operation::History => ['account_id'],
            Operation::Calendar => ['date', 'session_status'],
            Operation::TradeApprovalSetting => ['account_id', 'setting_account'],
        };
        foreach ($required as $key) {
            if (! isset($fields[$key]) || $fields[$key] === '') {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        if (in_array($operation, [Operation::Positions, Operation::Quotes], true) && empty($fields['instrument_id']) && empty($fields['symbol'])) {
            throw new ReadFailure(ReadError::Malformed);
        }
        $decimals = ['dividend_per_share', 'book_bid', 'book_ask', 'book_bid_quantity', 'book_ask_quantity', 'quantity', 'filled_quantity', 'price', 'bid', 'ask', 'last', 'cash', 'equity', 'buying_power', 'market_value', 'amount', 'unleveraged_buying_power', 'average_buy_price', 'stop_price', 'average_price', 'fees', 'regular_last', 'extended_last', 'equity_value', 'options_value', 'crypto_value', 'futures_value', 'event_contracts_value', 'mutual_funds_value', 'fixed_income_value', 'pending_deposits', 'eps_estimate', 'eps_actual'];
        $strings = ['asset_type', 'market_date', 'ex_dividend_date', 'record_date', 'payable_date', 'dividend_schedule_status', 'id', 'account_id', 'instrument_id', 'account_kind', 'symbol', 'status', 'side', 'order_type', 'time_in_force', 'currency', 'date', 'session_status', 'nickname', 'account_type', 'brokerage_account_type', 'management_type', 'account_scope', 'asset_class', 'position_type', 'name', 'bid_as_of', 'ask_as_of', 'last_as_of', 'regular_last_as_of', 'extended_last_as_of', 'last_session', 'report_date', 'report_timing', 'ref_id', 'market_hours', 'created_at', 'last_transaction_at', 'setting_account', 'setting_updated_at'];
        foreach ($fields as $key => $value) {
            if ($key === 'executions') {
                $fields[$key] = $value === null ? null : self::executions($value);
            } elseif (in_array($key, ['item_count', 'fiscal_year', 'fiscal_quarter'], true)) {
                if (! is_int($value) || $value < 0) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            } elseif (in_array($key, $decimals, true)) {
                try {
                    $fields[$key] = $value === null ? null : Decimal::from($value);
                } catch (\InvalidArgumentException) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            } elseif (in_array($key, ['agentic_allowed', 'deactivated', 'permanently_deactivated', 'broker_tradeable', 'extended_hours_fractional_tradability', 'report_verified', 'human_must_approve_trades'], true)) {
                if (! is_bool($value) && ! ($key === 'report_verified' && $value === null)) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            } elseif (! in_array($key, [...$strings, 'owner_type', 'object_type', 'list_id', 'watchlist_scope', 'fractional_tradability', 'internal_halt_reason', 'book_quantity_unit'], true) || (! is_string($value) && $value !== null)) {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        foreach (['dividend_per_share', 'quantity', 'filled_quantity', 'price', 'bid', 'ask', 'last', 'market_value', 'book_bid', 'book_ask', 'book_bid_quantity', 'book_ask_quantity'] as $key) {
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
        if ($operation === Operation::TradeApprovalSetting && (! is_bool($fields['human_must_approve_trades'] ?? null) || $fields['setting_account'] !== $fields['account_id'])) {
            throw new ReadFailure(ReadError::Malformed);
        }
        if ($operation === Operation::Accounts && ! in_array($fields['account_kind'], ['agentic', 'personal', 'unknown'], true)) {
            throw new ReadFailure(ReadError::Malformed);
        }

        return new self($operation, $fields, $sourceAsOf);
    }

    /** Plain decimal strings stay strings so stored fill evidence keeps the broker's exact text. */
    private static function executions(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new ReadFailure(ReadError::Malformed);
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_keys($row) !== ['id', 'quantity', 'price', 'fees', 'timestamp'] || ! is_string($row['id']) || $row['id'] === '' ||
                ! is_string($row['timestamp']) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $row['timestamp'])) {
                throw new ReadFailure(ReadError::Malformed);
            }
            foreach (['quantity', 'price', 'fees'] as $key) {
                try {
                    $value = Decimal::from($row[$key]);
                } catch (\InvalidArgumentException) {
                    throw new ReadFailure(ReadError::Malformed);
                }
                if ($value->compareTo(Decimal::from('0')) < 0) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            }
        }

        return $rows;
    }

    public function jsonSerialize(): array
    {
        return ['data' => $this->fields, 'source_as_of' => $this->sourceAsOf?->format(DATE_ATOM)];
    }
}
