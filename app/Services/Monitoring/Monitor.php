<?php

namespace App\Services\Monitoring;

use App\Models\McpConnection;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\PolicyVersion;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\QuoteBatch;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class Monitor
{
    public function __construct(private BrokerGateway $gateway, private RiskObservations $risk, private IncidentRecorder $incidents) {}

    public function sync(int $connectionId, string $dedupKey, string $trigger = 'manual', ?string $runId = null): Run
    {
        if (! preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D', $dedupKey) || strtolower($dedupKey) === 'undefined' ||
            ($runId !== null && ! Str::isUuid($runId))) {
            throw new InvalidArgumentException('A valid deduplication key and UUID run identifier are required.');
        }
        [$run, $connection, $owned] = DB::transaction(function () use ($connectionId, $dedupKey, $trigger, $runId): array {
            $connection = McpConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
            if ($connection->status !== 'connected') {
                throw new ReadFailure(ReadError::Authentication);
            }
            $scope = AccountScope::key($connection);
            $lastFailure = Run::where('mcp_connection_id', $connectionId)->where('scope', $scope)->where('status', 'failed')->latest('finished_at')->first();
            if ($lastFailure) {
                $delay = Snapshot::where('run_id', $lastFailure->id)->first()?->payload['failure']['retry_after'] ?? null;
                if (is_int($delay) && $lastFailure->finished_at->addSeconds($delay)->isFuture()) {
                    return [$lastFailure, $connection, false];
                }
            }
            $run = Run::where('mcp_connection_id', $connectionId)->where('scope', $scope)->where('dedup_key', $dedupKey)->first();
            if ($run && $runId !== null && $run->id !== $runId) {
                throw new InvalidArgumentException('Run identifier does not match its deduplication key.');
            }
            if ($run && in_array($run->status, ['complete', 'superseded'], true)) {
                return [$run, $connection, false];
            }
            $active = Run::where('mcp_connection_id', $connectionId)->where('status', 'running')->where('lease_until', '>', now())->first();
            if ($active) {
                return [$active, $connection, false]; // coalesce overlap, including different triggers/queues
            }
            if (! $run) {
                $run = new Run;
                $run->forceFill(['id' => $runId ?? (string) Str::uuid(), 'mcp_connection_id' => $connectionId, 'scope' => $scope,
                    'dedup_key' => $dedupKey, 'trigger' => $trigger, 'scheduled_at' => now(), 'auth_generation' => $connection->auth_generation]);
            }
            if ($run->status === 'running') {
                $this->incidents->record($connection, 'abandoned_run');
            }
            $policy = PolicyVersion::where('mcp_connection_id', $connectionId)->where('scope', $scope)->where('effective_at', '<=', now())->latest('id')->first();
            if (! $policy) {
                $policy = app(VersionLedger::class)->policy($connection, $connection->user_id, []);
            }
            $run->forceFill(['auth_generation' => $connection->auth_generation, 'status' => 'running', 'claim' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(10),
                'started_at' => now(), 'finished_at' => null, 'step' => 'portfolio', 'error_code' => null, 'policy_version_id' => $policy?->id])->save();
            Snapshot::firstOrCreate(['run_id' => $run->id], ['mcp_connection_id' => $connectionId, 'scope' => $scope]);

            return [$run, $connection, true];
        });
        if (! $owned) {
            return $run;
        }
        try {
            // One broker session serves every read in this run.
            $parts = $this->gateway->within($connection, fn (): array => $this->readParts($connection, $run));
            $this->publish($connection, $run, $parts);
        } catch (Throwable $error) {
            $this->fail($connectionId, $run, $error);
        }

        return $run->fresh();
    }

    private function readParts(McpConnection $connection, Run $run): array
    {
        $parts = [];
        foreach ([Operation::Portfolio, Operation::Positions, Operation::Orders] as $operation) {
            $result = $this->gateway->read($connection, $operation);
            $part = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            foreach ($result->records as $record) {
                if ($record->operation !== $operation || ($record->fields['account_id'] ?? null) !== $connection->bound_account_id) {
                    throw new ReadFailure(ReadError::AccountMismatch);
                }
            }
            $parts[$operation->value] = $part;
            $this->stage($connection, $run, $parts, $operation->value);
            if (! $result->complete || $result->continuation !== null) {
                throw new ReadFailure($result->incompleteReason ?? ReadError::Incomplete);
            }
            if ($operation === Operation::Portfolio && count($result->records) !== 1) {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        if (config('robinhood_mcp.approval_setting_enabled')) {
            // Optional evidence for the live checks only: a failure leaves the setting unknown and never fails monitoring.
            try {
                $parts['trade_approval_setting'] = json_decode(json_encode($this->gateway->read($connection, Operation::TradeApprovalSetting), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            } catch (ReadFailure $failure) {
                $parts['trade_approval_setting'] = ['failure' => $failure->reason->value];
            }
        }
        $parts['quotes'] = [];
        $parts['quote_failures'] = [];
        // Bounded optional enrichment in batched reads. A quote failure makes valuation/rules unknown, never erases holdings.
        $symbols = [];
        foreach (array_slice($parts['positions']['records'], 0, 100) as $row) {
            $symbol = $row['data']['symbol'] ?? null;
            if (is_string($symbol) && ! in_array($symbol, $symbols, true)) {
                $symbols[] = $symbol;
            }
        }
        $batch = QuoteBatch::read($this->gateway, $connection, $symbols);
        foreach ($symbols as $symbol) {
            if (isset($batch['quotes'][$symbol])) {
                $parts['quotes'][$symbol] = json_decode(json_encode($batch['quotes'][$symbol], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            } else {
                // Explicit unknown below; no broker writes or fallback to an invented price.
                $parts['quote_failures'][$symbol] = ['error_code' => $batch['failures'][$symbol] ?? 'incomplete_quote', 'attempted_at' => now()->toIso8601String()];
            }
        }
        $this->stage($connection, $run, $parts, 'quotes');

        return $parts;
    }

    private function fail(int $connectionId, Run $run, Throwable $error): void
    {
        DB::transaction(function () use ($connectionId, $run, $error): void {
            $current = McpConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
            $ownedRun = Run::whereKey($run->id)->where('claim', $run->claim)->where('status', 'running')->first();
            if (! $ownedRun) {
                return;
            }
            $code = $error instanceof ReadFailure ? $error->reason->value : 'monitoring_failed';
            $staged = Snapshot::where('run_id', $run->id)->where('status', 'staged')->first();
            if ($staged) {
                $payload = $staged->payload ?? [];
                $payload['failure'] = ['error_code' => $code, 'retry_after' => $error instanceof ReadFailure ? $error->retryAfter : null];
                $staged->update(['payload' => $payload]);
            }
            $ownedRun->update(['status' => 'failed', 'error_code' => $code, 'finished_at' => now(), 'lease_until' => null]);
            if ($current->bound_account_id && AccountScope::key($current) === $run->scope) {
                $this->incidents->record($current, $code, 'critical');
            }
        });
    }

    private function stage(McpConnection $connection, Run $run, array $parts, string $step): void
    {
        DB::transaction(function () use ($connection, $run, $parts, $step): void {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $owned = Run::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($owned->claim !== $run->claim || $owned->status !== 'running' || $owned->lease_until->isPast() ||
                $current->status !== 'connected' || $current->auth_generation !== $run->auth_generation || AccountScope::key($current) !== $run->scope) {
                throw new ReadFailure(ReadError::AccountMismatch);
            }
            $owned->update(['step' => $step, 'lease_until' => now()->addMinutes(10)]);
            Snapshot::where('run_id', $run->id)->where('status', 'staged')->firstOrFail()->update(['payload' => $parts]);
            if ($step === 'orders') {
                app(ExternalOrders::class)->record($current, Snapshot::where('run_id', $run->id)->firstOrFail(), $parts['orders'], $owned->claim);
            }
        });
    }

    private function publish(McpConnection $connection, Run $run, array $parts): void
    {
        DB::transaction(function () use ($connection, $run, $parts): void {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $owned = Run::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($owned->claim !== $run->claim || $owned->status !== 'running' || $owned->lease_until->isPast() ||
                $current->status !== 'connected' || $current->auth_generation !== $run->auth_generation || AccountScope::key($current) !== $run->scope) {
                throw new ReadFailure(ReadError::AccountMismatch);
            }
            $snapshot = Snapshot::where('run_id', $run->id)->firstOrFail();
            $observations = $this->risk->evaluate($current, $parts);
            // No deletion of prior holdings: only this complete equity reconciliation replaces the visible equity set.
            foreach ($observations['holdings'] as $holding) {
                Holding::create(['snapshot_id' => $snapshot->id] + $holding);
            }
            $publication = Snapshot::where('mcp_connection_id', $connection->id)->where('scope', $run->scope)->max('publication_version') ?? 0;
            $snapshot->update(['publication_version' => $publication + 1, 'status' => 'complete', 'observations' => $observations, 'retrieved_at' => now(), 'published_at' => now()]);
            foreach ($observations['pause_reasons'] as $reason) {
                $this->incidents->record($current, $reason);
            }
            // A complete publication clears earlier failures and any pause reason it no longer asserts.
            $this->incidents->resolveAbsent($current, 'monitoring', $observations['pause_reasons']);
            $owned->update(['status' => 'complete', 'finished_at' => now(), 'lease_until' => null, 'step' => 'published']);
        });
    }
}
