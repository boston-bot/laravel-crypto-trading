<?php

namespace App\Services\Monitoring;

use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use Illuminate\Support\Str;

final class IncidentRecorder
{
    // Called while the owning connection row is locked, in the publication transaction.
    public function record(McpConnection $connection, string $key, string $severity = 'warning'): void
    {
        $incident = Incident::firstOrCreate(['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection), 'incident_key' => $key],
            ['severity' => $severity, 'first_seen_at' => now(), 'last_seen_at' => now(), 'state' => 'open']);
        $incident->update(['last_seen_at' => now()]);
        if ($incident->wasRecentlyCreated) {
            $event = new Outbox;
            $event->forceFill(['id' => (string) Str::uuid(), 'event_key' => 'incident:'.$incident->id, 'kind' => 'notification',
                'payload' => ['incident_id' => $incident->id]])->save();
        }
    }
}
