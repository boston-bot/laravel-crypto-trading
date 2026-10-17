<?php

namespace App\Services\Monitoring;

use App\Jobs\MonitorAccountJob;
use App\Models\McpConnection;
use App\Models\Monitoring\Outbox;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MonitoringScheduler
{
    public function __construct(private MarketCalendar $calendar, private IncidentRecorder $incidents) {}

    public function tick(): int
    {
        if (! config('mcp.monitoring_enabled')) {
            return 0;
        }
        $count = 0;
        $operatorIds = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operatorIds)->where('status', 'connected')->whereNotNull('bound_account_id')->get() as $connection) {
            $count += DB::transaction(function () use ($connection): int {
                $connection = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                $scope = AccountScope::key($connection);
                $session = $this->calendar->at(CarbonImmutable::now());
                $regular = $session['status'] === 'regular';
                // Unknown calendar falls back to hourly account observation, never an assumed trading session.
                $slot = $regular ? now()->startOfMinute()->subMinutes(now()->minute % 5)->format('YmdHi') : now()->startOfHour()->format('YmdH');
                $failures = Run::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'failed')->where('finished_at', '>=', now()->subMinutes(15))->count();
                if ($failures >= 3) {
                    $this->incidents->record($connection, 'integration_circuit_open', 'critical');

                    return 0; // pause until failure window expires; no authorization hammering
                }
                $latest = Snapshot::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'complete')->latest('publication_version')->first();
                if ($latest && $latest->retrieved_at->lt(now()->subHours(2))) {
                    $this->incidents->record($connection, 'missed_monitoring_window');
                }
                $keys = ['monitoring' => 'monitor:'.$slot];
                if (($latest?->observations['unresolved_orders'] ?? 0) > 0) {
                    $keys['reconciliation'] = 'reconcile:'.now()->format('YmdHi');
                }
                $added = 0;
                foreach ($keys as $trigger => $key) {
                    $eventKey = 'monitor:'.hash('sha256', $connection->id.':'.$scope.':'.$key);
                    if (Outbox::where('event_key', $eventKey)->exists()) {
                        continue;
                    }
                    $event = new Outbox;
                    $event->forceFill(['id' => (string) Str::uuid(), 'event_key' => $eventKey, 'kind' => 'monitor',
                        'payload' => ['connection_id' => $connection->id, 'scope' => $scope, 'dedup_key' => $key, 'trigger' => $trigger, 'scheduled_at' => now()->toIso8601String()]])->save();
                    $added++;
                }

                return $added;
            });
        }
        $this->dispatch();

        return $count;
    }

    public function dispatch(): void
    {
        if (! config('mcp.monitoring_enabled')) {
            return;
        }
        foreach (Outbox::where('kind', 'monitor')->whereNull('dispatched_at')->limit(100)->pluck('id') as $id) {
            DB::transaction(function () use ($id): void {
                $event = Outbox::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($event->dispatched_at) {
                    return;
                }
                $data = $event->payload;
                $connection = McpConnection::find($data['connection_id']);
                // Stale startup work is not replayed. Next tick creates a current observation.
                if ($connection && $connection->status === 'connected' && $connection->bound_account_id && AccountScope::key($connection) === $data['scope'] &&
                    CarbonImmutable::parse($data['scheduled_at'])->gte(now()->subMinutes(10))) {
                    MonitorAccountJob::dispatch($connection->id, $data['dedup_key'], $data['trigger'], $data['scope'], $data['scheduled_at']);
                }
                $event->update(['attempts' => $event->attempts + 1, 'dispatched_at' => now()]);
            });
        }
    }
}
