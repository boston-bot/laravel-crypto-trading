<?php

namespace App\Services\Monitoring;

use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use Illuminate\Support\Str;

/**
 * Incident episodes: a new or reopened episode queues one notification intent; recurrences within an ongoing episode
 * only refresh last_seen_at, so an acknowledgement keeps covering it. Call with the owning connection row locked.
 */
final class IncidentRecorder
{
    public function record(McpConnection $connection, string $key, string $severity = 'warning', string $source = 'monitoring'): void
    {
        $identity = ['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection), 'incident_key' => $key];
        $incident = Incident::where($identity)->first();
        if (! $incident) {
            $this->notify(Incident::create($identity + ['severity' => $severity, 'source' => $source, 'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]));

            return;
        }
        if ($incident->resolved_at) {
            $incident->update(['state' => 'open', 'severity' => $severity, 'resolved_at' => null, 'acknowledged_by' => null, 'acknowledged_at' => null,
                'reopen_count' => $incident->reopen_count + 1, 'last_seen_at' => now()]);
            $this->notify($incident);

            return;
        }
        $incident->update(['last_seen_at' => now()]);
    }

    public function resolve(McpConnection $connection, string $key): void
    {
        Incident::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('incident_key', $key)
            ->whereNull('resolved_at')->update(['state' => 'resolved', 'resolved_at' => now()]);
    }

    /** Resolves this source's ongoing incidents that the latest complete observation no longer asserts. */
    public function resolveAbsent(McpConnection $connection, string $source, array $asserted): void
    {
        Incident::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('source', $source)
            ->whereNull('resolved_at')->whereNotIn('incident_key', $asserted)->update(['state' => 'resolved', 'resolved_at' => now()]);
    }

    private function notify(Incident $incident): void
    {
        $event = new Outbox;
        $event->forceFill(['id' => (string) Str::uuid(), 'event_key' => 'incident:'.$incident->id.($incident->reopen_count ? ':'.$incident->reopen_count : ''),
            'kind' => 'notification', 'payload' => ['incident_id' => $incident->id]])->save();
    }
}
