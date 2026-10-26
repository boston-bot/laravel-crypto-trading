<?php

namespace App\Services\Execution;

use App\Models\Execution\AutomationControl;
use App\Models\McpConnection;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Explicit account activation, pinned to the current authorization and live policy. No broker writes here. */
final class AutomaticTrading
{
    public function overview(McpConnection $c): array
    {
        $scope = AccountScope::key($c);
        $state = self::current($scope);

        return ['state' => $state, 'reason' => $this->reason($c), 'configuration_reason' => $this->configurationReason(),
            'controls' => ExecutionControls::state($scope), 'last_cycle' => $state ? DB::table('mcp_execution_cycles')
                ->where('execution_account_id', $state->execution_account_id)->latest('id')->first() : null];
    }

    public static function current(string $scope): ?AutomationControl
    {
        return AutomationControl::whereIn('execution_account_id', DB::table('mcp_execution_accounts')->where('scope', $scope)->select('id'))->latest('version')->first();
    }

    public function reason(McpConnection $c, ?int $expectedId = null): ?string
    {
        $scope = AccountScope::key($c);
        $state = self::current($scope);
        if (! $state?->enabled || ($expectedId !== null && $state->id !== $expectedId)) {
            return 'automatic_trading_off_or_changed';
        }
        if ($c->status !== 'connected' || $c->account_role !== 'agentic_monitoring' || $state->mcp_connection_id !== $c->id || $state->auth_generation !== $c->auth_generation ||
            $state->policy_fingerprint !== LivePolicies::current($scope)?->fingerprint) {
            return 'automatic_authorization_or_policy_changed';
        }

        return $this->configurationReason() ?? (ExecutionControls::state($scope)['emergency_disabled'] ? 'execution_emergency_disabled' : null)
            ?? app(ExecutionRecoveryFence::class)->reason($scope);
    }

    public function configurationReason(): ?string
    {
        foreach (['mcp.live_automation_enabled', 'mcp.brokerage_writes_enabled', 'mcp.live_exit_signals_enabled',
            'paper.batch_collection_enabled', 'paper.market_collection_enabled', 'paper.market_inputs_enabled'] as $flag) {
            if (! config($flag)) {
                return 'automatic_execution_configuration_disabled';
            }
        }

        return null;
    }

    public function set(McpConnection $connection, int $actor, string $request, bool $enabled, int $base, bool $exclusive = false): AutomationControl
    {
        abort_unless(Str::isUuid($request) && $base >= 0, 422);

        return DB::transaction(function () use ($connection, $actor, $request, $enabled, $base, $exclusive) {
            $c = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($c->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($c->bound_account_id && AccountScope::key($c) === AccountScope::key($connection), 409, 'Account binding changed.');
            $scope = AccountScope::key($c);
            DB::table('mcp_execution_accounts')->insertOrIgnore(['scope' => $scope]);
            $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->lockForUpdate()->firstOrFail();
            $current = self::current($scope);
            if ($prior = AutomationControl::where('request_id', $request)->first()) {
                abort_unless($prior->execution_account_id === $account->id && $prior->actor_id === $actor && $prior->enabled === $enabled, 409);

                // Replaying an old ON after OFF must never release the emergency stop.
                if ($enabled || ! $current?->enabled) {
                    return $prior;
                }
                // A retried OFF after a later ON must still stop, never falsely report a stopped account.
                $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'automatic-stop-retry:'.$prior->request_id.':'.$current->id)->toString();
            }
            if ($enabled) {
                abort_unless(($current?->version ?? 0) === $base, 409, 'Automatic trading changed. Reload.');
                abort_unless($exclusive, 422, 'Confirm this app is the sole executor for this account.');
                abort_unless($c->status === 'connected' && $c->account_role === 'agentic_monitoring' && $c->auth_generation === $connection->auth_generation, 409, 'Account authorization changed.');
                abort_if($reason = $this->configurationReason(), 409, $reason ?? 'Automatic trading unavailable.');
                abort_unless(LivePolicies::current($scope), 409, 'live_policy_missing');
                abort_if($reason = app(ExecutionRecoveryFence::class)->reason($scope), 409, $reason ?? 'Recovery verification required.');
                $snapshot = Snapshot::where('mcp_connection_id', $c->id)->where('scope', $scope)->where('status', 'complete')
                    ->whereIn('run_id', Run::where('mcp_connection_id', $c->id)->where('auth_generation', $c->auth_generation)->select('id'))->latest('publication_version')->first();
                abort_unless($snapshot, 409, 'broker_account_unverified');
                abort_if($reason = app(AccountEligibility::class)->reason($c, $snapshot), 409, $reason ?? 'Account eligibility required.');
                $approval = $snapshot->payload['trade_approval_setting'] ?? [];
                abort_unless(($approval['completeness'] ?? null) === 'complete' &&
                    ($approval['records'][0]['data']['human_must_approve_trades'] ?? null) === LivePolicies::current($scope)->payload['require_broker_approval'], 409, 'broker_approval_setting_differs');
            }
            $controls = ExecutionControls::state($scope);
            app(ExecutionControls::class)->set($c, $actor, (string) Str::uuid(), $controls['buy_paused'], ! $enabled,
                $enabled ? 'Owner enabled automatic trading under the current live policy.' : 'Owner stopped all new order submissions.');

            try {
                app(ExecutionRecoveryFence::class)->activation($scope, $request);
            } catch (HttpException $failure) {
                // Missing/damaged storage must never prevent OFF. It already fences dispatch; preserve the stop.
                if ($enabled) {
                    throw $failure;
                }
            }

            return AutomationControl::create(['execution_account_id' => $account->id, 'version' => ($current?->version ?? 0) + 1,
                'enabled' => $enabled, 'mcp_connection_id' => $c->id, 'auth_generation' => $c->auth_generation,
                'policy_fingerprint' => LivePolicies::current($scope)?->fingerprint, 'actor_id' => $actor, 'request_id' => $request, 'recorded_at' => now()]);
        });
    }
}
