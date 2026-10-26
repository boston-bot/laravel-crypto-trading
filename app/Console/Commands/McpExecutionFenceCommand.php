<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Execution\ExecutionRecoveryFence;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

class McpExecutionFenceCommand extends Command
{
    protected $signature = 'mcp:execution-fence {--account= : Local operator-owned connection ID} {--initialize : First-use provisioning only, before any intents}';

    protected $description = 'Initialize a private execution recovery checkpoint with writes disabled; never resets or enables trading';

    public function handle(ExecutionRecoveryFence $fence): int
    {
        $c = McpConnection::whereKey($this->option('account'))->whereIn('user_id', User::whereIn('email', config('mcp.operator_emails'))->select('id'))->first();
        if (! $c || ! $this->option('initialize')) {
            $this->error('Select an operator-owned connection and --initialize for first-use provisioning.');

            return self::FAILURE;
        }
        try {
            $fence->initialize($c, $c->user_id);
        } catch (HttpException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
        $this->info('Execution recovery checkpoint initialized. Brokerage writes remain disabled.');

        return self::SUCCESS;
    }
}
