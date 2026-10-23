<?php

namespace App\Services\Operations;

use App\Jobs\QueueProbeJob;
use App\Models\McpConnection;
use App\Models\Monitoring\Snapshot;
use App\Models\Operations\ComponentStatus;
use App\Models\Operations\NotificationDelivery;
use App\Models\User;
use App\Services\Crypto\CryptoMarketData;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperationsHealth
{
    public const QUEUES = ['monitoring' => 'mcp_monitoring', 'reconciliation' => 'mcp_monitoring', 'research' => 'mcp_research'];

    public function __construct(private DiskSpace $disk, private MarketCalendar $calendar, private NtfyNotifier $ntfy, private HeartbeatPing $heartbeat, private CryptoMarketData $cryptoData) {}

    /** Queues one probe per queue unless one is still waiting; a probe left unprocessed means its worker is down. */
    public function probeQueues(): void
    {
        foreach (self::QUEUES as $queue => $connection) {
            DB::transaction(function () use ($queue, $connection): void {
                // Create the lock target before claiming, including the first tick on a new installation.
                ComponentStatus::firstOrCreate(['component' => 'probe:'.$queue], ['status' => 'idle', 'detail' => [], 'observed_at' => now()]);
                $sent = ComponentStatus::where('component', 'probe:'.$queue)->lockForUpdate()->firstOrFail();
                $done = ComponentStatus::where('component', 'queue:'.$queue)->first();
                $probeId = $sent->detail['probe_id'] ?? null;
                $waiting = $probeId !== null && ($done?->detail['probe_id'] ?? null) !== $probeId;
                if (! $waiting && ($probeId === null || $sent->observed_at->lte(now()->subMinutes((int) config('operations.thresholds.queue_probe_minutes'))))) {
                    $probeId = (string) Str::uuid();
                    // Record before dispatch: even a worker that immediately consumes it can acknowledge this exact probe.
                    $sent->update(['status' => 'dispatched', 'detail' => ['probe_id' => $probeId], 'observed_at' => now()]);
                    QueueProbeJob::dispatch($queue, $connection, $probeId);
                }
            });
        }
    }

    /** @return array<string, array{status: string, detail: array}> status is ok, warning, critical, disabled or unconfigured */
    public function evaluate(): array
    {
        $t = config('operations.thresholds');
        $tick = ComponentStatus::where('component', 'scheduler')->first();
        $checks['scheduler'] = ['status' => $tick && $tick->observed_at->gt(now()->subMinutes($t['scheduler_stale_minutes'])) ? 'ok' : 'critical',
            'detail' => ['last_tick' => $tick?->observed_at->toIso8601String()]];
        foreach (self::QUEUES as $queue => $connection) {
            $sent = ComponentStatus::where('component', 'probe:'.$queue)->first();
            $done = ComponentStatus::where('component', 'queue:'.$queue)->first();
            $stalled = $sent && ($sent->detail['probe_id'] ?? null) !== null && ($done?->detail['probe_id'] ?? null) !== $sent->detail['probe_id'] && $sent->observed_at->lt(now()->subMinutes($t['queue_stall_minutes']));
            $oldest = DB::table(config('queue.connections.'.$connection.'.table', 'jobs'))->where('queue', $queue)->whereNull('reserved_at')->min('available_at');
            $waiting = $oldest === null ? 0 : max(0, intdiv(now()->getTimestamp() - (int) $oldest, 60));
            $checks['queue_'.$queue] = ['status' => $stalled ? 'critical' : ($waiting >= $t['queue_age_warning_minutes'] ? 'warning' : 'ok'),
                'detail' => ['last_processed' => $done?->observed_at->toIso8601String(), 'oldest_waiting_minutes' => $waiting]];
        }
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $checks['failed_jobs'] = ['status' => $failed > 0 ? 'warning' : 'ok', 'detail' => ['last_24h' => $failed]];
        $checks['data_age'] = $this->dataAge();
        $checks['crypto_data'] = $this->cryptoData->health();
        $free = $this->disk->freeBytes(storage_path());
        $gib = $free === null ? null : round($free / 1024 ** 3, 1);
        $checks['disk'] = ['status' => $gib === null ? 'warning' : ($gib < $t['disk_critical_gib'] ? 'critical' : ($gib < $t['disk_warning_gib'] ? 'warning' : 'ok')), 'detail' => ['free_gib' => $gib]];
        $backup = ComponentStatus::where('component', 'backup')->first();
        $fresh = $backup && $backup->observed_at->gt(now()->subHours($t['backup_max_age_hours'])) && in_array($backup->status, ['ok', 'offsite_unconfigured', 'offsite_missing'], true);
        $checks['backup'] = ['status' => ! $fresh ? 'warning' : ($backup->status === 'ok' ? 'ok' : 'warning'),
            'detail' => ['last' => $backup?->observed_at->toIso8601String(), 'result' => $backup?->status]];
        $undelivered = NotificationDelivery::whereIn('status', ['failed', 'unknown'])->where('attempted_at', '>=', now()->subHour())->count();
        $checks['alerts'] = ['status' => ! $this->ntfy->configured() ? 'unconfigured' : ($undelivered > 0 ? 'warning' : 'ok'), 'detail' => ['failed_last_hour' => $undelivered]];
        $checks['heartbeat'] = ['status' => $this->heartbeat->configured() ? 'ok' : 'unconfigured', 'detail' => ['last_result' => ComponentStatus::where('component', 'heartbeat')->value('status')]];

        return $checks;
    }

    /** Expected cadence: five-minute slots in a verified regular session, hourly otherwise (unknown calendar included). */
    private function dataAge(): array
    {
        if (! config('mcp.monitoring_enabled')) {
            return ['status' => 'disabled', 'detail' => []];
        }
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $limit = $this->calendar->at(CarbonImmutable::now())['status'] === 'regular' ? 15 : 120;
        $ages = [];
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')->get() as $connection) {
            $latest = Snapshot::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('status', 'complete')->max('retrieved_at');
            $ages[] = $latest === null ? null : (int) CarbonImmutable::parse($latest)->diffInMinutes(now(), true);
        }
        $stale = $ages === [] || in_array(null, $ages, true) || max($ages) > $limit;

        return ['status' => $stale ? 'critical' : 'ok', 'detail' => ['age_minutes' => $ages === [] || in_array(null, $ages, true) ? null : max($ages), 'limit_minutes' => $limit]];
    }
}
