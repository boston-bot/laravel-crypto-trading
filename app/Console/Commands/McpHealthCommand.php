<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\Operations\ComponentStatus;
use App\Models\User;
use App\Services\Monitoring\IncidentRecorder;
use App\Services\Operations\HeartbeatPing;
use App\Services\Operations\OperationsHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class McpHealthCommand extends Command
{
    protected $signature = 'mcp:health {--scheduled : Record a scheduler tick (used only by the scheduler)} {--no-ping : Skip the external heartbeat}';

    protected $description = 'Operational health checks: records incidents and pings the external heartbeat; no broker or AI calls';

    public function handle(OperationsHealth $health, HeartbeatPing $heartbeat, IncidentRecorder $incidents): int
    {
        if ($this->option('scheduled')) {
            ComponentStatus::record('scheduler', 'ticked');
        }
        $health->probeQueues();
        $checks = $health->evaluate();
        $critical = array_keys(array_filter($checks, fn ($check) => $check['status'] === 'critical'));
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->whereNotNull('bound_account_id')->pluck('id') as $id) {
            DB::transaction(function () use ($id, $checks, $incidents): void {
                $connection = McpConnection::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $connection->bound_account_id) {
                    return;
                }
                foreach ($checks as $name => $check) {
                    $level = match ($check['status']) {
                        'critical' => 'critical',
                        'warning', 'unconfigured' => 'warning',
                        default => null,
                    };
                    // Separate keys per level, so a warning that becomes critical opens a new urgent episode.
                    foreach (['critical' => 'health:'.$name, 'warning' => 'health:'.$name.':warning'] as $severity => $key) {
                        $level === $severity ? $incidents->record($connection, $key, $severity, 'operations') : $incidents->resolve($connection, $key);
                    }
                }
            });
        }
        $ping = $this->option('no-ping') ? 'skipped' : $heartbeat->ping($critical);
        if ($ping !== 'skipped') {
            ComponentStatus::record('heartbeat', $ping);
        }
        ComponentStatus::record('health', $critical === [] ? 'ok' : 'critical', ['critical' => $critical]);
        $this->line(json_encode(['healthy' => $critical === [], 'checks' => array_map(fn ($check) => $check['status'], $checks), 'heartbeat' => $ping], JSON_THROW_ON_ERROR));

        return $critical === [] ? self::SUCCESS : self::FAILURE;
    }
}
