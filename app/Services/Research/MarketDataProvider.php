<?php

namespace App\Services\Research;

use App\Models\McpConnection;
use App\Services\Broker\Agentic\ReadFailure;

/** Timestamped market/news reads. A provider may cover only part of this contract; unsupported fields are unknown. */
interface MarketDataProvider
{
    /** @return array{provider: string, coverage: array<string, array{supported: bool, timestamp_quality: string, scope: string}>} */
    public function coverage(): array;

    /**
     * @return array{field: string, symbol: string, source: string, source_as_of: ?string, retrieved_at: string, freshness: string, data: array}
     *
     * @throws ReadFailure
     */
    public function read(McpConnection $connection, string $field, string $symbol): array;
}
