<?php

namespace App\Services\Research;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Monitoring\RiskObservations;

/** Reuses the reviewed read-only broker gateway. No vendor subscription or guessed broker tool is used. */
final class BrokerMarketData implements MarketDataProvider
{
    public function __construct(private BrokerGateway $gateway) {}

    public function coverage(): array
    {
        return ['provider' => config('research.market_data.provider'), 'coverage' => config('research.market_data.coverage')];
    }

    public function read(McpConnection $connection, string $field, string $symbol): array
    {
        $operation = match ($field) {
            'quotes' => Operation::Quotes,
            'instrument_lookup' => Operation::Instruments,
            default => throw new ReadFailure(ReadError::Unsupported),
        };
        if ((config('research.market_data.coverage.'.$field.'.supported') ?? false) !== true) {
            throw new ReadFailure(ReadError::Unsupported);
        }
        $result = $this->gateway->read($connection, $operation, ['symbol' => $symbol]);
        if (! $result->complete || $result->continuation !== null || count($result->records) !== 1 ||
            $result->records[0]->operation !== $operation || ($result->records[0]->fields['symbol'] ?? null) !== $symbol) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        $record = $result->records[0];
        $retrieved = $result->retrievedAt->format(DATE_ATOM);
        $asOf = $record->sourceAsOf?->format(DATE_ATOM);
        $keys = $field === 'quotes' ? ['bid', 'ask', 'last', 'last_session'] : ['symbol', 'name'];

        return ['field' => $field, 'symbol' => $symbol, 'source' => $result->source, 'source_as_of' => $asOf, 'retrieved_at' => $retrieved,
            'freshness' => RiskObservations::freshness($asOf, $retrieved),
            'data' => array_map(fn ($value) => $value === null ? null : (string) $value, array_intersect_key($record->fields, array_flip($keys)))];
    }
}
