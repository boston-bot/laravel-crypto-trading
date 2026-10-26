<?php

namespace App\Services\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Local durable claim checkpoint, deliberately outside PostgreSQL schema backups. A database rollback must not
 * turn a possibly sent order back into a sendable staged intent. Missing/corrupt/ahead checkpoints never reset.
 */
final class ExecutionRecoveryFence
{
    /** First-use provisioning only. Recovery never creates/replaces a checkpoint. No broker operations. */
    public function initialize(McpConnection $connection, int $actor): void
    {
        abort_if(DB::transactionLevel() !== 0, 409, 'execution_recovery_outer_transaction');
        abort_unless(DB::connection()->getDriverName() === 'pgsql', 409, 'execution_recovery_postgresql_required');
        abort_if(config('mcp.brokerage_writes_enabled'), 409, 'execution_recovery_disable_writes_first');
        DB::transaction(function () use ($connection, $actor) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id, 409, 'execution_recovery_bound_account_required');
            $scope = AccountScope::key($current);
            DB::table('mcp_execution_accounts')->insertOrIgnore(['scope' => $scope]);
            $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('mcp_execution_intents')->where('execution_account_id', $account->id)->exists(), 409, 'execution_recovery_existing_intents');
            $dir = $this->directory();
            if (! is_dir($dir)) {
                abort_unless(@mkdir($dir, 0700, true), 409, 'execution_recovery_storage_unavailable');
            }
            $this->privatePath($dir, true);
            $path = $this->path($scope);
            $file = @fopen($path, 'x+b');
            abort_unless($file !== false, 409, 'execution_recovery_checkpoint_exists_or_unavailable');
            try {
                abort_unless(@chmod($path, 0600) && flock($file, LOCK_EX | LOCK_NB), 409, 'execution_recovery_storage_unavailable');
                $this->write($file, ['contract' => 'execution_recovery_v1', 'scope' => $scope, 'claim' => null]);
            } finally {
                fclose($file);
            }
        });
    }

    /** Read-only verification at the final boundary. The caller holds the account's PostgreSQL row lock. */
    public function reason(string $scope): ?string
    {
        try {
            $this->locked($scope, fn ($file, $state) => $this->verify($scope, $state));

            return null;
        } catch (HttpException $failure) {
            return $failure->getMessage();
        }
    }

    /**
     * Called inside the claim transaction AFTER all checks, BEFORE it commits. Disk is fsynced first: a failed
     * database commit may conservatively fence the account, but an external submission cannot outrun the file.
     */
    public function reserve(OrderIntent $intent, string $scope, string $token): void
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'execution_recovery_account_lock_required');
        $this->locked($scope, function ($file, $state) use ($intent, $scope, $token) {
            $this->verify($scope, $state);
            $state['claim'] = ['key' => $intent->idempotency_key, 'fingerprint' => $intent->fingerprint, 'token' => $token,
                'count' => ($state['claim']['count'] ?? 0) + 1];
            $this->write($file, $state);
        });
    }

    /** Persist activation/stop outside schema backups before the corresponding owner event commits. */
    public function activation(string $scope, string $request): void
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'execution_recovery_account_lock_required');
        $this->locked($scope, function ($file, $state) use ($scope, $request) {
            $this->verify($scope, $state);
            $state['automation_request'] = $request;
            $this->write($file, $state);
        });
    }

    private function verify(string $scope, array $state): void
    {
        abort_unless(($state['automation_request'] ?? null) === AutomaticTrading::current($scope)?->request_id,
            409, 'execution_recovery_automation_changed');
        $claims = DB::table('mcp_execution_dispatches as d')->join('mcp_execution_intents as i', 'i.id', '=', 'd.intent_id')
            ->join('mcp_execution_accounts as a', 'a.id', '=', 'i.execution_account_id')->where('a.scope', $scope)->whereNotNull('d.claim_token');
        if ($state['claim'] === null) {
            abort_if($claims->exists(), 409, 'execution_recovery_checkpoint_behind');

            return;
        }
        $head = $state['claim'];
        abort_unless($claims->count() === $head['count'], 409, 'execution_recovery_claim_count_differs');
        abort_unless($claims->where('i.idempotency_key', $head['key'])->where('i.fingerprint', $head['fingerprint'])
            ->where('d.claim_token', $head['token'])->exists(), 409, 'execution_recovery_database_behind');
    }

    private function locked(string $scope, callable $work): mixed
    {
        $this->privatePath($this->directory(), true);
        $path = $this->path($scope);
        $this->privatePath($path, false);
        $file = @fopen($path, 'r+b'); // Never create on a dispatch path.
        abort_unless($file !== false, 409, 'execution_recovery_checkpoint_unavailable');
        try {
            abort_unless(flock($file, LOCK_EX | LOCK_NB), 409, 'execution_recovery_checkpoint_busy');
            $json = stream_get_contents($file, 4097);
            $state = is_string($json) && strlen($json) <= 4096 ? json_decode($json, true) : null;
            abort_unless(is_array($state) && ($state['contract'] ?? null) === 'execution_recovery_v1' &&
                ($state['scope'] ?? null) === $scope && array_key_exists('claim', $state) &&
                ($state['claim'] === null || (is_array($state['claim']) &&
                    is_string($state['claim']['key'] ?? null) && is_string($state['claim']['fingerprint'] ?? null) && is_string($state['claim']['token'] ?? null) &&
                    is_int($state['claim']['count'] ?? null) && $state['claim']['count'] > 0)),
                409, 'execution_recovery_checkpoint_invalid');

            return $work($file, $state);
        } finally {
            fclose($file);
        }
    }

    private function write($file, array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR)."\n";
        abort_unless(rewind($file) && ftruncate($file, 0) && fwrite($file, $json) === strlen($json) && fflush($file) && fsync($file),
            409, 'execution_recovery_checkpoint_write_failed');
    }

    private function directory(): string
    {
        $dir = config('mcp.execution_recovery_dir');
        abort_unless(is_string($dir) && str_starts_with($dir, '/') && trim($dir, '/') !== '', 409, 'execution_recovery_not_configured');

        return rtrim($dir, '/');
    }

    private function path(string $scope): string
    {
        return $this->directory().'/'.hash('sha256', $scope).'.json';
    }

    private function privatePath(string $path, bool $directory): void
    {
        clearstatcache(true, $path);
        abort_unless(! is_link($path) && ($directory ? is_dir($path) : is_file($path)) &&
            (fileperms($path) & 0077) === 0 && (! function_exists('posix_geteuid') || fileowner($path) === posix_geteuid()),
            409, 'execution_recovery_checkpoint_unavailable');
    }
}
