<?php

declare(strict_types=1);

namespace App\Services\PaperTrading\Experiments;

use App\Jobs\RunStrategyExperiment;
use App\Models\McpConnection;
use App\Models\PaperTrading\StrategyExperiment;
use App\Models\PaperTrading\StrategyExperimentStep as Step;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\IntradayReplayInputs;
use App\Services\PaperTrading\StrategyReplay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Durable research coordinator. Broker/order gateways and the forward paper ledger are not dependencies. */
final class StrategyExperiments
{
    public function __construct(private ExperimentDefinition $definitions, private ExperimentAccess $access, private IntradayReplayInputs $inputs,
        private StrategyReplay $engine, private ReplayAccountingVerifier $verifier, private ExperimentComparisons $comparisons) {}

    private function enabled(): bool
    {
        return config('strategy_experiments.enabled') && config('alpaca_data.historical_quote_replay_enabled');
    }

    public function start(McpConnection $connection, int $actor, string $request, array $spec): StrategyExperiment
    {
        Validator::make(compact('request'), ['request' => ['required', 'uuid']])->validate();
        abort_unless($this->enabled(), 409, 'Historical experiments are disabled.');
        $spec = $this->definitions->validate($spec);

        return DB::transaction(function () use ($connection, $actor, $request, $spec) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($connection, $actor);
            $scope = AccountScope::key($connection);
            $row = StrategyExperiment::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $request)->first();
            if ($row) {
                $this->access->authorize($connection, $actor, $row);
                abort_unless(ExperimentDefinition::hash($row->definition['spec']) === ExperimentDefinition::hash($spec), 409, 'Request already identifies another specification.');

                return $row;
            }
            $definition = $this->definitions->freeze($connection, $spec);
            if (isset($spec['dataset_experiment'])) {
                $parent = $this->find($connection, $actor, $spec['dataset_experiment']);
                abort_unless($parent->status === 'complete' && $parent->definition['spec']['sessions'] === $spec['sessions'] &&
                    $parent->definition['spec']['symbols'] === $spec['symbols'] && $parent->definition['data_contract'] === $definition['data_contract'], 422, 'Dataset must be a completed experiment with identical dates, symbols and data contract.');
                $definition['reused_dataset_hash'] = $parent->report['dataset_hash'];
            }
            $row = StrategyExperiment::create(['experiment_id' => (string) Str::uuid(), 'mcp_connection_id' => $connection->id, 'actor_id' => $actor,
                'scope' => $scope, 'request_id' => $request, 'definition_hash' => ExperimentDefinition::hash($definition), 'definition' => $definition, 'status' => 'queued']);
            $this->enqueue($row->id);

            return $row;
        });
    }

    public function find(McpConnection $connection, int $actor, string $id): StrategyExperiment
    {
        $this->access->authorize($connection, $actor);
        $row = StrategyExperiment::where('experiment_id', $id)->where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->firstOrFail();
        $this->access->authorize($connection, $actor, $row);

        return $row;
    }

    public function report(McpConnection $connection, int $actor, string $id): array
    {
        $row = $this->find($connection, $actor, $id);

        $done = $this->completed($row);
        $variants = [];
        foreach ($done as $key => $payload) {
            if (str_starts_with($key, 'variant:')) {
                $variants[substr($key, 8)] = $payload['metrics'];
            }
        }

        return ['experiment_id' => $row->experiment_id, 'status' => $row->status, 'reason' => $row->reason, 'current_step' => $row->current_step,
            'step_attempt' => $row->step_attempt, 'lease_expires_at' => $row->lease_expires_at?->toISOString(), 'available_at' => $row->available_at?->toISOString(),
            'completed_steps' => Step::where('experiment_id', $row->id)->where('status', 'complete')->pluck('step_key')->all(),
            'failed_attempts' => Step::where('experiment_id', $row->id)->where('status', 'failed')->get(['step_key', 'attempt', 'reason'])->toArray(),
            'completed_variants' => $variants, 'definition_hash' => $row->definition_hash, 'specification' => $row->definition['spec'], 'report' => $row->report];
    }

    public function resume(McpConnection $connection, int $actor, string $id): StrategyExperiment
    {
        abort_unless($this->enabled(), 409, 'Historical experiments are disabled.');

        return DB::transaction(function () use ($connection, $actor, $id) {
            $row = StrategyExperiment::where('experiment_id', $id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($connection, $actor, $row);
            if ($row->status === 'complete') {
                return $row;
            }
            abort_if($row->step_attempt > 65530, 409, 'Start a new experiment after exhausting the attempt history.');
            abort_if($row->lease_expires_at?->isFuture(), 409, 'Experiment step still owns an active lease.');
            abort_unless($this->intact($row), 409, 'Frozen experiment sources or definition changed; start a new experiment.');
            $row->update(['status' => 'queued', 'reason' => null, 'lease_token' => null, 'lease_expires_at' => null, 'available_at' => null, 'attempt_limit' => $row->step_attempt + 3]);
            $this->enqueue($row->id);

            return $row;
        });
    }

    private function intact(StrategyExperiment $row): bool
    {
        return $row->definition_hash === ExperimentDefinition::hash($row->definition) && $this->definitions->compatible($row->definition['source_hashes']);
    }

    private function enqueue(int $id, int $delay = 0): void
    {
        // DatabaseQueue inserts into the same authoritative DB as this transaction, including delayed retries.
        RunStrategyExperiment::dispatch($id)->delay($delay);
    }

    private function completed(StrategyExperiment $row): array
    {
        $records = Step::where('experiment_id', $row->id)->where('status', 'complete')->orderBy('id')->get();
        $values = [];
        foreach ($records as $record) {
            abort_unless($record->fingerprint === ExperimentDefinition::hash($record->payload), 409, 'Checkpoint integrity check failed.');
            $values[$record->step_key] = $record->payload;
        }

        return $values;
    }

    private function next(StrategyExperiment $row, array $completed): string
    {
        foreach ($row->definition['spec']['sessions'] as $date) {
            if (! isset($completed['session:'.$date])) {
                return 'session:'.$date;
            }
        }
        foreach ($row->definition['variants'] as $variant) {
            if (! isset($completed['variant:'.$variant['id']])) {
                return 'variant:'.$variant['id'];
            }
        }

        return 'report';
    }

    /** One bounded step per job. Returns a delay only when another worker/clock owns availability. */
    public function work(int $id): int
    {
        $claim = DB::transaction(function () use ($id) {
            $row = StrategyExperiment::whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($row->status, ['complete', 'failed', 'paused'], true)) {
                return null;
            }
            if ($row->lease_expires_at?->isFuture()) {
                return ['delay' => max(1, (int) now()->diffInSeconds($row->lease_expires_at))];
            }
            if ($row->available_at?->isFuture()) {
                return ['delay' => max(1, (int) now()->diffInSeconds($row->available_at))];
            }
            try {
                $this->access->authorize(McpConnection::whereKey($row->mcp_connection_id)->lockForUpdate()->firstOrFail(), $row->actor_id, $row);
                abort_unless($this->enabled(), 409);
                abort_unless($this->intact($row), 409);
                $done = $this->completed($row);
            } catch (Throwable) {
                $row->update(['status' => 'paused', 'reason' => 'authorization_gate_or_source_changed', 'lease_token' => null, 'lease_expires_at' => null]);

                return null;
            }
            $step = $this->next($row, $done);
            $attempt = $row->current_step === $step ? $row->step_attempt + 1 : 1;
            $limit = $row->current_step === $step ? $row->attempt_limit : 3;
            if ($attempt > $limit) {
                $row->update(['status' => 'failed', 'reason' => 'attempt_limit', 'lease_token' => null, 'lease_expires_at' => null]);

                return null;
            }
            $token = (string) Str::uuid();
            $row->update(['status' => 'running', 'reason' => null, 'current_step' => $step, 'step_attempt' => $attempt,
                'attempt_limit' => $limit, 'lease_token' => $token, 'lease_expires_at' => now()->addSeconds(360), 'available_at' => null]);

            return ['row' => $row, 'token' => $token, 'done' => $done, 'step' => $step, 'attempt' => $attempt, 'limit' => $limit];
        });
        if ($claim === null) {
            return 0;
        }
        if (isset($claim['delay'])) {
            return $claim['delay'];
        }
        try {
            [$payload, $reason, $retry] = $this->execute($claim['row'], $claim['step'], $claim['done']);
        } catch (Throwable $e) {
            $payload = null;
            $reason = $e instanceof HttpExceptionInterface ? 'research_input_rejected' : 'step_execution_or_verification_failed';
            $retry = false;
        }
        DB::transaction(function () use ($claim, $payload, $reason, $retry) {
            $row = StrategyExperiment::whereKey($claim['row']->id)->lockForUpdate()->firstOrFail();
            // A late response from a timed-out worker cannot replace a newer checkpoint or release its lease.
            if ($row->lease_token !== $claim['token'] || ! $row->lease_expires_at?->isFuture()) {
                return;
            }
            try {
                $this->access->authorize(McpConnection::whereKey($row->mcp_connection_id)->lockForUpdate()->firstOrFail(), $row->actor_id, $row);
                abort_unless($this->enabled() && $this->intact($row), 409);
            } catch (Throwable) {
                $row->update(['status' => 'paused', 'reason' => 'authorization_gate_or_source_changed', 'lease_token' => null, 'lease_expires_at' => null]);

                return;
            }
            $success = $reason === null;
            $evidence = $success ? $payload : ['failure' => $reason];
            Step::create(['experiment_id' => $row->id, 'step_key' => $claim['step'], 'completed_key' => $success ? $claim['step'] : null,
                'attempt' => $claim['attempt'], 'status' => $success ? 'complete' : 'failed', 'reason' => $reason,
                'fingerprint' => ExperimentDefinition::hash($evidence), 'payload' => $evidence, 'recorded_at' => now()]);
            $delay = ! $success && $retry && $claim['attempt'] < $claim['limit'] ? min(180, 60 * $claim['attempt']) : 0;
            $changes = ['lease_token' => null, 'lease_expires_at' => null, 'reason' => $reason, 'available_at' => null];
            if ($success && $claim['step'] === 'report') {
                $changes += ['status' => 'complete', 'report' => $payload];
            } elseif ($success || $delay > 0) {
                $changes += ['status' => 'queued'];
                $changes['available_at'] = $delay > 0 ? now()->addSeconds($delay) : null;
                $this->enqueue($row->id, $delay);
            } else {
                $changes += ['status' => 'failed'];
            }
            $row->update($changes);
        });

        return 0;
    }

    private function execute(StrategyExperiment $row, string $step, array $done): array
    {
        $definition = $row->definition;
        $spec = $definition['spec'];
        if (str_starts_with($step, 'session:')) {
            $date = substr($step, 8);
            if (isset($spec['dataset_experiment'])) {
                $connection = McpConnection::findOrFail($row->mcp_connection_id);
                $parent = $this->find($connection, $row->actor_id, $spec['dataset_experiment']);
                $prior = $this->completed($parent);
                $data = $prior[$step]['data'];
            } else {
                $data = $this->inputs->acquire($date, $spec['symbols'], 390);
            }
            if (! $data['complete']) {
                $reason = preg_match('/^[a-z_]{1,64}$/D', $data['reason'] ?? '') ? $data['reason'] : 'acquisition_unavailable';
                $retry = str_contains($reason, 'rate_limited') || str_contains($reason, 'transport') || str_contains($reason, 'http_failure');

                return [null, $reason, $retry];
            }
            $index = array_search($date, $spec['sessions'], true);
            if ($index > 0 && $data['previous_session'] !== $spec['sessions'][$index - 1]) {
                return [null, 'nonconsecutive_sessions', false];
            }
            foreach ($data['action_reads'] as $reads) {
                foreach ($reads as $read) {
                    foreach ($read['events'] as $event) {
                        $exDate = $event['dates']['ex_date'] ?? null;
                        if ($event['type'] !== 'cash_dividends' || $exDate === null || ($exDate >= $spec['sessions'][0] && $exDate <= $spec['sessions'][array_key_last($spec['sessions'])])) {
                            return [null, 'overnight_action_requires_entitlement_support', false];
                        }
                    }
                }
            }

            return [['data' => $data], null, false];
        }
        $sessions = array_map(fn ($date) => $done['session:'.$date]['data'], $spec['sessions']);
        $datasetHash = ExperimentDefinition::hash($sessions);
        abort_if(isset($definition['reused_dataset_hash']) && $definition['reused_dataset_hash'] !== $datasetHash, 409, 'Reused dataset fingerprint changed.');
        if (str_starts_with($step, 'variant:')) {
            $id = substr($step, 8);
            $variant = array_values(array_filter($definition['variants'], fn ($v) => $v['id'] === $id))[0];
            $result = $this->engine->runQuoteWindow($sessions, $variant['values'], $definition['settings'], $variant['equity']);
            $verification = $this->verifier->verify($sessions, $result, $definition['settings'], $variant['equity']);

            return [['dataset_hash' => $datasetHash, 'variant' => $variant, 'result' => $result, 'metrics' => $this->comparisons->summarize($result, $verification)], null, false];
        }
        $variants = [];
        foreach ($definition['variants'] as $variant) {
            $variants[$variant['id']] = $done['variant:'.$variant['id']];
        }

        $report = $this->comparisons->build($definition, $sessions, $variants, $datasetHash);
        if (isset($spec['dataset_experiment'])) {
            $parent = $this->find(McpConnection::findOrFail($row->mcp_connection_id), $row->actor_id, $spec['dataset_experiment']);
            $report['reference'] = $this->comparisons->reference($report, $parent->report, $parent->experiment_id);
        }

        return [$report, null, false];
    }
}
