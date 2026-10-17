<?php

namespace App\Jobs;

use App\Models\McpConnection;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class MonitorAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $connectionId, public string $dedupKey, public string $trigger = 'monitoring', public ?string $scope = null, public ?string $scheduledAt = null)
    {
        $this->onConnection('mcp_monitoring');
        $this->onQueue($trigger === 'reconciliation' ? 'reconciliation' : 'monitoring');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(Monitor $monitor): void
    {
        if (! config('mcp.monitoring_enabled')) {
            return;
        }
        $connection = McpConnection::find($this->connectionId);
        if (! $connection || $connection->status !== 'connected' || ! $connection->bound_account_id ||
            ! User::whereKey($connection->user_id)->whereIn('email', config('mcp.operator_emails'))->exists() ||
            ($this->scope !== null && AccountScope::key($connection) !== $this->scope) ||
            ($this->scheduledAt !== null && CarbonImmutable::parse($this->scheduledAt)->lt(now()->subMinutes(10)))) {
            return;
        }
        $run = $monitor->sync($this->connectionId, $this->dedupKey, $this->trigger);
        if ($run->status === 'failed' && in_array($run->error_code, ['timeout', 'transport_unavailable', 'rate_limited'], true)) {
            $retryAfter = Snapshot::where('run_id', $run->id)->first()?->payload['failure']['retry_after'] ?? null;
            if (is_int($retryAfter) && $retryAfter > 0) {
                $this->release(max(60, $retryAfter));

                return;
            }
            // Read-only retry, same durable run/key. Never include broker text in a queue exception.
            throw new RuntimeException('Transient monitoring read failure.');
        }
    }
}
