<?php

namespace App\Console\Commands;

use App\Services\Research\HoldoutEvaluationService;
use Illuminate\Console\Command;
use Throwable;

class OpenResearchHoldoutCommand extends Command
{
    protected $signature = 'research:holdout {finalist_hash} {manifest_hash} {--actor=local-operator} {--purpose=single final holdout evaluation}';

    protected $description = 'Authorize and enqueue the sole holdout evaluation for an exact frozen finalist hash';

    public function handle(HoldoutEvaluationService $holdouts): int
    {
        try {
            $job = $holdouts->authorizeAndEnqueue(
                (string) $this->argument('finalist_hash'),
                (string) $this->argument('manifest_hash'),
                (string) $this->option('actor'),
                (string) $this->option('purpose'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->warn('The locked holdout is now authorized for exactly one frozen finalist.');
        $this->info('Holdout engine job '.$job->id.' is queued.');

        return self::SUCCESS;
    }
}
