<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Monitoring\Monitor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class McpSyncCommand extends Command
{
    protected $signature = 'mcp:sync {--account= : Local connection ID} {--key= : Stable deduplication key}';

    protected $description = 'One manual read-only account sync; does not enable schedules';

    public function handle(Monitor $monitor): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->first();
        if (! $connection) {
            $this->error('Select a local connection owned by an allowed operator.');

            return self::FAILURE;
        }
        try {
            $run = $monitor->sync($connection->id, $this->option('key') ?? 'manual:'.Str::uuid());
            $this->line(json_encode(['run_id' => $run->id, 'status' => $run->status, 'error_code' => $run->error_code], JSON_THROW_ON_ERROR));

            return $run->status === 'complete' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Sync unavailable. Check binding, integration and database configuration.');

            return self::FAILURE;
        }
    }
}
