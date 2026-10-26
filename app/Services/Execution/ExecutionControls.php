<?php

namespace App\Services\Execution;

use App\Models\Execution\ExecutionControl;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Append-only owner controls per broker account. A buy pause blocks new entries; an emergency disable blocks every
 * claim and makes a claimed-but-unsent order withdraw. Engaging either never needs a connected authorization.
 */
final class ExecutionControls
{
    public function pauseBuys(McpConnection $connection, int $actor, string $request, bool $paused): ExecutionControl
    {
        return DB::transaction(function () use ($connection, $actor, $request, $paused) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed.');
            DB::table('mcp_execution_accounts')->insertOrIgnore(['scope' => AccountScope::key($current)]);
            DB::table('mcp_execution_accounts')->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();

            return $this->set($connection, $actor, $request, $paused, self::state(AccountScope::key($current))['emergency_disabled'],
                $paused ? 'Owner paused new buys from the trading controls.' : 'Owner resumed new buys from the trading controls.');
        });
    }

    /** @return array{buy_paused: bool, emergency_disabled: bool, version: int} */
    public static function state(string $scope): array
    {
        $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->value('id');
        $latest = $account ? ExecutionControl::where('execution_account_id', $account)->latest('version')->first() : null;

        return ['buy_paused' => (bool) $latest?->buy_paused, 'emergency_disabled' => (bool) $latest?->emergency_disabled, 'version' => $latest?->version ?? 0];
    }

    public function set(McpConnection $connection, int $actor, string $request, bool $buyPaused, bool $emergencyDisabled, string $reason): ExecutionControl
    {
        Validator::make(['request' => $request, 'reason' => $reason], ['request' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:200']])->validate();

        return DB::transaction(function () use ($connection, $actor, $request, $buyPaused, $emergencyDisabled, $reason) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed.');
            $scope = AccountScope::key($current);
            DB::table('mcp_execution_accounts')->insertOrIgnore(['scope' => $scope]);
            $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->lockForUpdate()->first();
            $state = self::state($scope);
            // Releasing a control needs the current authorization; making things more restrictive never does.
            $releasing = ($state['buy_paused'] && ! $buyPaused) || ($state['emergency_disabled'] && ! $emergencyDisabled);
            abort_if($releasing && ! ($current->status === 'connected' && $current->account_role === 'agentic_monitoring' &&
                $current->auth_generation === $connection->auth_generation), 409, 'Account authorization changed.');
            if ($prior = ExecutionControl::where('request_id', $request)->first()) {
                abort_unless($prior->execution_account_id === $account->id && $prior->buy_paused === $buyPaused &&
                    $prior->emergency_disabled === $emergencyDisabled && $prior->reason === $reason, 409, 'Request already used for a different control.');

                return $prior;
            }
            $version = (ExecutionControl::where('execution_account_id', $account->id)->max('version') ?? 0) + 1;

            return ExecutionControl::create(['execution_account_id' => $account->id, 'version' => $version, 'buy_paused' => $buyPaused,
                'emergency_disabled' => $emergencyDisabled, 'actor_id' => $actor, 'request_id' => $request, 'reason' => $reason, 'recorded_at' => now()]);
        });
    }
}
