<?php

namespace App\Services\Monitoring;

use App\Models\McpConnection;
use App\Models\Monitoring\PolicyVersion;
use App\Models\Monitoring\SleeveAllocation;
use App\Models\Monitoring\ThesisVersion;
use App\Support\Mcp\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class VersionLedger
{
    public function policy(McpConnection $connection, int $actorId, array $draft): PolicyVersion
    {
        // M2 only creates disabled drafts. A policy record can never activate execution or research.
        return $this->append(PolicyVersion::class, $connection, $actorId, ['mode' => 'disabled', 'research_enabled' => false, 'draft' => $draft]);
    }

    public function thesis(McpConnection $connection, int $actorId, array $thesis): ThesisVersion
    {
        foreach (['thesis_key', 'symbol', 'sleeve', 'rationale', 'sources', 'horizon', 'next_review_at', 'invalidation'] as $key) {
            if (! isset($thesis[$key])) {
                throw new InvalidArgumentException('Incomplete thesis version.');
            }
        }
        if (! in_array($thesis['sleeve'], ['long_term', 'active', 'unclassified'], true) || ! is_array($thesis['sources']) ||
            ! in_array($thesis['invalidation']['type'] ?? '', ['price', 'completed_session_close', 'fundamental_event', 'manual_review'], true)) {
            throw new InvalidArgumentException('Invalid thesis scope or invalidation.');
        }
        if (in_array($thesis['invalidation']['type'], ['price', 'completed_session_close'], true)) {
            if (! in_array($thesis['invalidation']['operator'] ?? '', ['lte', 'gte'], true) || Decimal::from($thesis['invalidation']['threshold'] ?? null)->compareTo(Decimal::from('0')) <= 0) {
                throw new InvalidArgumentException('A positive numeric invalidation is required.');
            }
        }

        return $this->append(ThesisVersion::class, $connection, $actorId, $thesis);
    }

    public function allocate(McpConnection $connection, int $actorId, string $instrumentKey, string $sleeve, string $quantity): SleeveAllocation
    {
        if (! in_array($sleeve, ['long_term', 'active'], true) || trim($instrumentKey) === '' || Decimal::from($quantity)->compareTo(Decimal::from('0')) < 0) {
            throw new InvalidArgumentException('Invalid sleeve attribution.');
        }

        return $this->append(SleeveAllocation::class, $connection, $actorId, ['instrument_key' => $instrumentKey, 'sleeve' => $sleeve, 'quantity' => $quantity]);
    }

    private function append(string $class, McpConnection $connection, int $actorId, array $payload): Model
    {
        return DB::transaction(function () use ($class, $connection, $actorId, $payload) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($current->user_id !== $actorId || AccountScope::key($current) !== AccountScope::key($connection)) {
                throw new InvalidArgumentException('Account scope or actor changed.');
            }

            return $class::create(['mcp_connection_id' => $current->id, 'scope' => AccountScope::key($current), 'actor_id' => $actorId,
                'record_key' => (string) Str::uuid(), 'payload' => $payload, 'effective_at' => now()]);
        });
    }
}
