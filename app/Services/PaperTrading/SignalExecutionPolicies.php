<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SignalExecutionPolicy;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Explicit owner settings, binding existing allocations and execution permissions; never enables a service. */
final class SignalExecutionPolicies
{
    public const DEFAULTS = ['risk_per_trade_pct' => '1', 'max_position_pct' => '20', 'max_open_positions' => 8,
        'slippage_bps' => '1', 'limit_offset_bps' => '5', 'quote_max_age_seconds' => 20, 'allow_fractional' => false];

    public static function current(Portfolio $portfolio): ?SignalExecutionPolicy
    {
        $policy = SignalExecutionPolicy::where('paper_portfolio_id', $portfolio->id)->latest('version')->first();
        $execution = ExecutionPolicies::current($portfolio);

        return $policy && $execution && $policy->execution_version_id === $execution->id && $policy->policy_application_id === $execution->policy_application_id ? $policy : null;
    }

    public function apply(McpConnection $connection, int $actor, int $base, string $request, array $input, ?int $expectedExecutionId = null): SignalExecutionPolicy
    {
        abort_if(array_diff(array_keys($input), array_keys(self::DEFAULTS)), 422, 'Only signal execution settings may be supplied.');
        $payload = Validator::make($input, ['risk_per_trade_pct' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/D'],
            'max_position_pct' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/D'], 'max_open_positions' => ['required', 'integer', 'min:1', 'max:8'],
            'slippage_bps' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/D'], 'limit_offset_bps' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,8})?$/D'],
            'quote_max_age_seconds' => ['required', 'integer', 'min:1', 'max:60'], 'allow_fractional' => ['present']])->validate();
        foreach (['risk_per_trade_pct' => '1', 'max_position_pct' => '20', 'slippage_bps' => '100', 'limit_offset_bps' => '100'] as $key => $max) {
            $v = Decimal::from($payload[$key]);
            abort_unless($v->compareTo(Decimal::from('0')) >= 0 && $v->compareTo(Decimal::from($max)) <= 0 &&
                (! in_array($key, ['risk_per_trade_pct', 'max_position_pct']) || $v->compareTo(Decimal::from('0')) > 0), 422, 'Signal setting outside code bounds.');
        }
        abort_unless($payload['allow_fractional'] === false, 422, 'Fractional limit-order support remains unverified; select whole shares.');
        Validator::make(['request' => $request, 'base' => $base], ['request' => ['required', 'uuid'], 'base' => ['integer', 'min:0']])->validate();
        $payload['max_open_positions'] = (int) $payload['max_open_positions'];
        $payload['quote_max_age_seconds'] = (int) $payload['quote_max_age_seconds'];
        $payload['cost_schedule'] = TradingCosts::reviewed();

        return DB::transaction(function () use ($connection, $actor, $base, $request, $payload, $expectedExecutionId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->auth_generation === $connection->auth_generation &&
                $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $execution = ExecutionPolicies::current($portfolio);
            abort_unless($expectedExecutionId === null || $execution?->id === $expectedExecutionId, 409, 'Execution settings changed since review.');
            abort_unless($execution && RuleEligibilityPolicies::current($portfolio), 409, 'Apply allocation, execution and eligibility rules first.');
            if ($prior = SignalExecutionPolicy::where('paper_portfolio_id', $portfolio->id)->where('request_id', $request)->first()) {
                abort_unless($prior->payload === $payload && $prior->execution_version_id === $execution->id, 409, 'Request already used for different settings.');

                return $prior;
            }
            abort_unless((SignalExecutionPolicy::where('paper_portfolio_id', $portfolio->id)->max('version') ?? 0) === $base, 409, 'Settings changed. Reload.');

            return SignalExecutionPolicy::create(['paper_portfolio_id' => $portfolio->id, 'policy_application_id' => $execution->policy_application_id,
                'execution_version_id' => $execution->id, 'actor_id' => $actor, 'version' => $base + 1, 'request_id' => $request, 'payload' => $payload, 'applied_at' => now()]);
        });
    }
}
