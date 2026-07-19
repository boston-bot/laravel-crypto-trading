<?php

namespace App\Services\Operations;

use App\Models\RuntimeControlRequest;
use App\Models\RuntimeProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RuntimeControlService
{
    private const ACTIONS = ['start', 'pause', 'resume', 'restart'];

    public function __construct(private readonly OperatorActionService $actions) {}

    public function ensureRegistry(): void
    {
        foreach ((array) config('operations.workloads', []) as $name => $definition) {
            RuntimeProcess::query()->firstOrCreate(
                ['name' => $name],
                ['desired_state' => 'running', 'observed_state' => 'unknown', 'metadata_json' => ['label' => $definition['label'] ?? $name]],
            );
        }
    }

    public function request(string $processName, string $requestedAction, string $idempotencyKey): RuntimeControlRequest
    {
        $this->ensureRegistry();
        if (! array_key_exists($processName, (array) config('operations.workloads', []))) {
            throw new InvalidArgumentException('Unknown runtime process. Choose an allowlisted workload.');
        }
        if (! in_array($requestedAction, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported runtime action.');
        }

        $operatorAction = $this->actions->queue('runtime_'.$requestedAction, $idempotencyKey, ['process' => $processName], 'runtime_process', $processName);

        return RuntimeControlRequest::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'id' => (string) Str::uuid(),
                'operator_action_id' => $operatorAction->id,
                'process_name' => $processName,
                'requested_action' => $requestedAction,
                'status' => 'queued',
                'requested_at' => now(),
            ],
        );
    }

    public function claim(): ?RuntimeControlRequest
    {
        return DB::transaction(function (): ?RuntimeControlRequest {
            $query = RuntimeControlRequest::query()->where('status', 'queued')->orderBy('requested_at');
            if (DB::getDriverName() === 'pgsql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $query->lockForUpdate();
            }
            $request = $query->first();
            if ($request === null) {
                return null;
            }
            $request->update(['status' => 'running', 'started_at' => now()]);
            $request->operatorAction()->update(['status' => 'running', 'started_at' => now()]);

            return $request->fresh();
        });
    }

    /** @param array<string, mixed> $result */
    public function complete(RuntimeControlRequest $request, array $result = []): void
    {
        DB::transaction(function () use ($request, $result): void {
            $request->update(['status' => 'completed', 'result_json' => $result, 'completed_at' => now()]);
            $request->operatorAction()->update(['status' => 'completed', 'result_json' => $result, 'completed_at' => now()]);
        });
    }

    public function fail(RuntimeControlRequest $request, string $message): void
    {
        DB::transaction(function () use ($request, $message): void {
            $request->update(['status' => 'failed', 'last_error' => $message, 'completed_at' => now()]);
            $request->operatorAction()->update(['status' => 'failed', 'last_error' => $message, 'completed_at' => now()]);
        });
    }

    /** @param array<string, mixed> $metadata */
    public function heartbeat(string $name, string $state, ?string $task = null, ?string $identity = null, array $metadata = []): void
    {
        $values = [
            'observed_state' => $state,
            'current_task' => $task,
            'process_identity' => $identity,
            'heartbeat_at' => now(),
            'metadata_json' => $metadata,
        ];
        if ($state === 'running') {
            $values['last_success_at'] = now();
        }

        RuntimeProcess::query()->updateOrCreate(['name' => $name], $values);
    }
}
