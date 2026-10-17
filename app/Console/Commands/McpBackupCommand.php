<?php

namespace App\Console\Commands;

use App\Models\Operations\ComponentStatus;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\Backups;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class McpBackupCommand extends Command
{
    protected $signature = 'mcp:backup {--init-key : Create the backup key file once; an existing key is never replaced}';

    protected $description = 'Encrypted, verified backup of the application schema with retention and an off-Mac copy';

    public function handle(Backups $backups): int
    {
        if ($this->option('init-key')) {
            try {
                BackupCipher::createKey(config('operations.backup.key_file'));
            } catch (RuntimeException $error) {
                $this->error($error->getMessage());

                return self::FAILURE;
            }
            $this->info('Backup key created at '.config('operations.backup.key_file').'. Store a copy in your password manager, separately from APP_KEY and never beside the backups.');

            return self::SUCCESS;
        }
        try {
            $result = $backups->run();
        } catch (Throwable $error) {
            ComponentStatus::record('backup', 'failed', ['error' => class_basename($error)]);
            $this->error('Backup failed: '.($error instanceof RuntimeException ? $error->getMessage() : class_basename($error)));

            return self::FAILURE;
        }
        $this->line(json_encode(array_intersect_key($result, array_flip(['file', 'bytes', 'sha256', 'pruned', 'offsite'])), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
