<?php

namespace App\Console\Commands;

use App\Models\Operations\ComponentStatus;
use App\Services\Operations\Backups;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class McpRestoreDrillCommand extends Command
{
    protected $signature = 'mcp:restore-drill {--file= : Encrypted backup to restore; defaults to the newest}';

    protected $description = 'Restore a backup into a scratch database, verify it is usable with execution disabled, then drop it';

    public function handle(Backups $backups): int
    {
        try {
            $result = $backups->restoreDrill($this->option('file'));
        } catch (Throwable $error) {
            ComponentStatus::record('restore_drill', 'failed', ['error' => class_basename($error)]);
            $this->error('Restore drill failed: '.($error instanceof RuntimeException ? $error->getMessage() : class_basename($error)));

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
