<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Shared stored-quote admission for signals and read-only measurement; does not grant entry or execution permission. */
final class StoredStrategyQuote
{
    public static function read(?MarketInput $input, McpConnection $connection, CarbonImmutable $cutoff, int $age): ?array
    {
        if (! $input || $input->mcp_connection_id !== $connection->id || $input->scope !== AccountScope::key($connection) || $input->origin !== 'broker_read' || $input->recorded_at->gt($cutoff) || $input->retrieved_at->gt($cutoff) ||
            ($input->payload['provider'] ?? null) !== TrustedHttp::RESOURCE || ($input->payload['auth_generation'] ?? null) !== $connection->auth_generation ||
            ($input->payload['complete'] ?? false) !== true || ($input->payload['quote']['symbol'] ?? null) !== $input->symbol) {
            return null;
        }
        $q = $input->payload['quote'];
        try {
            foreach (['retrieved_at', 'bid_as_of', 'ask_as_of'] as $timeField) {
                if (! is_string($q[$timeField] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $q[$timeField])) {
                    return null;
                }
            }
            $retrieved = CarbonImmutable::parse($q['retrieved_at']);
            if ($retrieved->gt($input->retrieved_at) || $retrieved->gt($cutoff) || $retrieved->lt($cutoff->subSeconds($age))) {
                return null;
            }
            foreach (['bid_as_of', 'ask_as_of'] as $field) {
                if (! is_string($q[$field] ?? null) || CarbonImmutable::parse($q[$field])->gt($retrieved) || CarbonImmutable::parse($q[$field])->lt($cutoff->subSeconds($age))) {
                    return null;
                }
            }
            if (Decimal::from($q['bid'] ?? null)->compareTo(Decimal::from('0')) <= 0 || Decimal::from($q['ask'] ?? null)->compareTo(Decimal::from($q['bid'] ?? null)) < 0) {
                return null;
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        return array_intersect_key($q, array_flip(['bid', 'ask', 'retrieved_at', 'bid_as_of', 'ask_as_of']));
    }
}
