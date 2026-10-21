<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\Experiments\StrategyExperiments;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class McpExperimentCommand extends Command
{
    protected $signature = 'mcp:experiment {action : start, report or resume} {--account= : Local operator-owned connection ID}
        {--spec= : JSON experiment definition file for start} {--experiment= : Experiment UUID for report/resume} {--request= : Stable request UUID for start}';

    protected $description = 'Start, inspect or resume durable historical research; no LLM, forward fills or brokerage writes';

    public function handle(StrategyExperiments $experiments): int
    {
        try {
            $action = $this->argument('action');
            abort_unless(in_array($action, ['start', 'report', 'resume'], true) && ctype_digit((string) $this->option('account')), 422, 'Use start/report/resume and a numeric --account.');
            $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
            $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->firstOrFail();
            if ($action === 'start') {
                $file = $this->option('spec');
                abort_unless(is_string($file) && is_file($file) && is_readable($file), 422, 'Supply a readable JSON --spec file.');
                $raw = file_get_contents($file, false, null, 0, 65537);
                abort_unless(is_string($raw) && strlen($raw) <= 65536, 422, 'Specification exceeds 64 KiB.');
                $spec = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                abort_unless(is_array($spec), 422, 'Use a JSON object.');
                $row = $experiments->start($connection, $connection->user_id, $this->option('request') ?? (string) Str::uuid(), $spec);
                $result = ['experiment_id' => $row->experiment_id, 'request_id' => $row->request_id, 'status' => $row->status, 'definition_hash' => $row->definition_hash];
            } else {
                $id = (string) $this->option('experiment');
                abort_unless(Str::isUuid($id), 422, 'Supply an --experiment UUID.');
                if ($action === 'resume') {
                    $experiments->resume($connection, $connection->user_id, $id);
                }
                $result = $experiments->report($connection, $connection->user_id, $id);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (ValidationException $e) {
            $this->error('Invalid experiment fields: '.implode(', ', array_keys($e->errors())));
        } catch (HttpExceptionInterface $e) {
            $this->error($e->getMessage() ?: 'Experiment command denied.');
        } catch (Throwable) {
            $this->error('Experiment command failed. Check the JSON specification, operator account and deployed schema.');
        }

        return self::FAILURE;
    }
}
