<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Monitoring\ArchiveImporter;
use Illuminate\Console\Command;
use Throwable;

class McpImportCommand extends Command
{
    protected $signature = 'mcp:import {--file=} {--dry-run} {--commit} {--account=}';

    protected $description = 'Preview a bounded JSON archive; explicit --commit archives evidence without activating imported state';

    public function handle(ArchiveImporter $importer): int
    {
        $file = $this->option('file');
        if (! is_string($file) || ! is_file($file) || ! is_readable($file) || filesize($file) > 5 * 1024 * 1024 || ($this->option('dry-run') && $this->option('commit'))) {
            $this->error('Provide a readable JSON file <=5 MiB and choose preview or commit.');

            return self::FAILURE;
        }
        try {
            $bytes = file_get_contents($file);
            $report = $importer->preview($bytes);
            if ($this->option('commit')) {
                $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
                $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->firstOrFail();
                $importer->commit($connection, $connection->user_id, $bytes);
            }
            // Report row numbers/reasons, never echo private free text or source identifiers to logs.
            $report['rows'] = array_map(fn ($row) => array_intersect_key($row, array_flip(['source_row', 'status', 'reason', 'mapping'])), $report['rows']);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Archive invalid or account unavailable; no partial import committed.');

            return self::FAILURE;
        }
    }
}
