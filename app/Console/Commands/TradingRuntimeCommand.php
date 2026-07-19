<?php

namespace App\Console\Commands;

use App\Models\RuntimeProcess;
use App\Services\Operations\RuntimeControlService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class TradingRuntimeCommand extends Command
{
    protected $signature = 'trading:runtime {--without= : Comma-separated allowlisted workloads to omit}';

    protected $description = 'Run the local scheduler, queue, Python engine, and public collector under one control agent';

    /** @var array<string, Process> */
    private array $children = [];

    private bool $stopping = false;

    public function handle(RuntimeControlService $controls): int
    {
        $controls->ensureRegistry();
        $this->registerSignals();
        $omitted = array_filter(array_map('trim', explode(',', (string) $this->option('without'))));
        $this->components->info('Trading runtime started. The Operations page can now control allowlisted workloads.');

        while (! $this->stopping) {
            foreach ((array) config('operations.workloads', []) as $name => $definition) {
                if (in_array($name, $omitted, true)) {
                    continue;
                }
                $record = RuntimeProcess::query()->find($name);
                if (($record?->desired_state ?? 'running') === 'running') {
                    $this->startIfNeeded($name, $definition);
                } else {
                    $this->stopIfRunning($name);
                }
                $child = $this->children[$name] ?? null;
                $state = $child?->isRunning() ? 'running' : (($record?->desired_state === 'paused') ? 'paused' : 'stopped');
                $controls->heartbeat($name, $state, $child?->isRunning() ? 'process active' : null, $child?->getPid() ? (string) $child->getPid() : null, ['label' => $definition['label'] ?? $name, 'exit_code' => $child?->getExitCode()]);
            }

            $this->applyControlRequest($controls);
            usleep(max(1, (int) config('operations.poll_seconds', 2)) * 1_000_000);
        }

        foreach (array_keys($this->children) as $name) {
            $this->stopIfRunning($name);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $definition */
    private function startIfNeeded(string $name, array $definition): void
    {
        if (($this->children[$name] ?? null)?->isRunning()) {
            return;
        }
        $command = array_values((array) ($definition['command'] ?? []));
        if ($command === [] || ! is_string($command[0]) || ! is_executable($command[0])) {
            RuntimeProcess::query()->whereKey($name)->update(['observed_state' => 'unavailable', 'last_error' => 'Configured executable is unavailable.', 'heartbeat_at' => now()]);

            return;
        }

        $environment = in_array($name, ['engine', 'collector'], true) ? ['DATABASE_URL' => $this->databaseUrl()] : [];
        $process = new Process($command, (string) ($definition['working_directory'] ?? base_path()), $environment, null, null);
        $process->disableOutput();
        $process->start();
        $this->children[$name] = $process;
        RuntimeProcess::query()->whereKey($name)->update(['observed_state' => 'running', 'last_error' => null, 'process_identity' => (string) $process->getPid(), 'heartbeat_at' => now()]);
    }

    private function stopIfRunning(string $name): void
    {
        $process = $this->children[$name] ?? null;
        if ($process?->isRunning()) {
            $process->stop((int) config('operations.drain_timeout_seconds', 15), SIGTERM);
        }
    }

    private function applyControlRequest(RuntimeControlService $controls): void
    {
        $request = $controls->claim();
        if ($request === null) {
            return;
        }

        try {
            $definition = (array) config('operations.workloads.'.$request->process_name, []);
            match ($request->requested_action) {
                'pause' => $this->pause($request->process_name),
                'start', 'resume' => $this->resume($request->process_name, $definition),
                'restart' => $this->restart($request->process_name, $definition),
                default => throw new \RuntimeException('Unsupported runtime action.'),
            };
            $controls->complete($request, ['observed_state' => RuntimeProcess::query()->find($request->process_name)?->observed_state]);
        } catch (Throwable $exception) {
            $controls->fail($request, $exception->getMessage());
        }
    }

    private function pause(string $name): void
    {
        RuntimeProcess::query()->whereKey($name)->update(['desired_state' => 'paused']);
        $this->stopIfRunning($name);
        RuntimeProcess::query()->whereKey($name)->update(['observed_state' => 'paused', 'process_identity' => null, 'heartbeat_at' => now()]);
    }

    /** @param array<string, mixed> $definition */
    private function resume(string $name, array $definition): void
    {
        RuntimeProcess::query()->whereKey($name)->update(['desired_state' => 'running']);
        $this->startIfNeeded($name, $definition);
    }

    /** @param array<string, mixed> $definition */
    private function restart(string $name, array $definition): void
    {
        $this->stopIfRunning($name);
        unset($this->children[$name]);
        RuntimeProcess::query()->whereKey($name)->update(['desired_state' => 'running', 'observed_state' => 'restarting']);
        $this->startIfNeeded($name, $definition);
    }

    private function registerSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn () => $this->stopping = true);
        pcntl_signal(SIGTERM, fn () => $this->stopping = true);
    }

    private function databaseUrl(): string
    {
        $connection = (array) config('database.connections.'.config('database.default'), []);
        $user = rawurlencode((string) ($connection['username'] ?? ''));
        $password = rawurlencode((string) ($connection['password'] ?? ''));
        $auth = $user.($password !== '' ? ':'.$password : '');

        return sprintf('postgresql://%s@%s:%s/%s', $auth, $connection['host'] ?? '127.0.0.1', $connection['port'] ?? 5432, $connection['database'] ?? 'postgres');
    }
}
