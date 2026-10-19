<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use Illuminate\Support\Str;

/** Hypothetical verified reference coverage for testing the engine; not available from the production collector. */
final class BrokerInputFixture
{
    public static function record(McpConnection $connection, array $changes = []): MarketInput
    {
        $now = now()->toIso8601String();
        $payload = ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'coverage' => 'controlled_future_reference_contract',
            'quote' => ['symbol' => 'DEMO', 'bid' => '99', 'ask' => '100', 'bid_as_of' => $now, 'ask_as_of' => $now, 'retrieved_at' => $now,
                'bid_size' => '100', 'ask_size' => '100', 'eligible' => true, 'asset_type' => 'stock', 'leveraged' => false, 'inverse' => false, 'corporate_actions_clear' => true],
            'execution_reads' => ['price_book' => ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'source_as_of' => $now, 'retrieved_at' => $now,
                'semantic_fingerprint' => config('robinhood_mcp.operations.price_book.semantic_fingerprint'),
                'fields' => ['symbol' => 'DEMO', 'book_bid' => '99', 'book_ask' => '100', 'book_bid_quantity' => '100', 'book_ask_quantity' => '4', 'book_quantity_unit' => 'shares']],
                'tradability' => ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'retrieved_at' => $now,
                    'fields' => ['symbol' => 'DEMO', 'broker_tradeable' => true]]]];
        foreach ($changes as $key => $value) {
            data_set($payload, $key, $value);
        }
        $scope = AccountScope::key($connection);

        return MarketInput::create(['mcp_connection_id' => $connection->id, 'scope' => $scope, 'request_id' => (string) Str::uuid(),
            'symbol' => 'DEMO', 'origin' => 'broker_read', 'status' => 'incomplete_coverage', 'reason' => null, 'payload' => $payload,
            'source_at' => now(), 'retrieved_at' => now(), 'recorded_at' => now()]);
    }
}
