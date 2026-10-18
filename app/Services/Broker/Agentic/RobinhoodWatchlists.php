<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;

/** User-level read evidence only. Membership never grants trading eligibility or changes an allowlist. */
final class RobinhoodWatchlists
{
    public function __construct(private BrokerGateway $gateway) {}

    public function named(McpConnection $connection, string $name): array
    {
        if (! config('robinhood_mcp.watchlists_enabled')) {
            throw new ReadFailure(ReadError::Disabled);
        }
        if ($name === '' || strlen($name) > 128) {
            throw new ReadFailure(ReadError::Malformed);
        }
        $connection->refresh();
        $binding = $connection->bound_account_id;
        $generation = $connection->auth_generation;
        if (! $binding) {
            throw new ReadFailure(ReadError::AccountMismatch);
        }
        $lists = $this->gateway->read($connection, Operation::Watchlists);
        $list = $this->select($lists, $name);
        $items = $this->gateway->read($connection, Operation::WatchlistItems, ['list_id' => $list->fields['id']]);
        if (! $items->complete || $items->continuation !== null || $items->source !== TrustedHttp::RESOURCE || count($items->records) !== $list->fields['item_count']) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        foreach ($items->records as $item) {
            if ($item->operation !== Operation::WatchlistItems || ($item->fields['list_id'] ?? null) !== $list->fields['id']) {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        // Detect rename/count/identity changes across the separate tool calls. This is not an atomic broker snapshot.
        $verified = $this->gateway->read($connection, Operation::Watchlists);
        if ($this->select($verified, $name)->fields !== $list->fields) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        $current = $connection->fresh();
        if ($current->bound_account_id !== $binding || $current->auth_generation !== $generation) {
            throw new ReadFailure(ReadError::AccountMismatch);
        }

        return ['source' => TrustedHttp::RESOURCE, 'scope' => 'authorized_robinhood_user',
            'source_as_of' => null, 'retrieved_at' => $verified->retrievedAt->format(DATE_ATOM),
            'completeness' => 'complete', 'consistency' => 'count_and_identity_rechecked_not_atomic',
            'watchlist' => $list, 'items' => $items->records,
            'reads' => ['watchlists' => $lists, 'items' => $items, 'verification' => $verified],
            'execution_eligibility' => 'unverified', 'policy_changed' => false];
    }

    private function select(ReadResult $result, string $name): BrokerRecord
    {
        if (! $result->complete || $result->continuation !== null || $result->source !== TrustedHttp::RESOURCE) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        $matches = [];
        foreach ($result->records as $record) {
            if ($record->operation !== Operation::Watchlists) {
                throw new ReadFailure(ReadError::Malformed);
            }
            if (strcasecmp(trim($record->fields['name']), trim($name)) === 0) {
                $matches[] = $record;
            }
        }
        if (count($matches) !== 1) {
            // Absent and ambiguous names both refuse selection; never choose an arbitrary first match.
            throw new ReadFailure(ReadError::Incomplete);
        }

        return $matches[0];
    }
}
