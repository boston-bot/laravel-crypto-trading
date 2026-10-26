<?php

namespace App\Services\Execution;

use App\Models\Execution\ExecutionPolicy;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\SignalOrderPrice;
use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Explicit owner live settings per broker account. Applying a version never enables writes, never changes paper
 * settings and never grants an order: every intent must carry the current fingerprint and pass SubmissionChecks.
 */
final class LivePolicies
{
    public const CONTRACT = 'live_execution_policy_v1';

    public const FIELDS = ['sleeve_capital', 'max_order_notional', 'risk_per_trade_pct', 'max_position_pct', 'max_open_positions',
        'daily_loss_pause_pct', 'max_orders_per_session', 'order_type', 'allow_fractional', 'slippage_bps', 'limit_offset_bps', 'quote_max_age_seconds'];

    public static function current(string $scope): ?ExecutionPolicy
    {
        $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->value('id');

        return $account ? ExecutionPolicy::where('execution_account_id', $account)->latest('version')->first() : null;
    }

    public static function fingerprint(string $scope, int $version, array $payload): string
    {
        return hash('sha256', json_encode(['contract' => self::CONTRACT, 'scope' => $scope, 'version' => $version, 'payload' => $payload], JSON_THROW_ON_ERROR));
    }

    public function apply(McpConnection $connection, int $actor, int $base, string $request, array $input): ExecutionPolicy
    {
        abort_unless(count($input) === count(self::FIELDS) && array_diff(self::FIELDS, array_keys($input)) === [], 422, 'Every live setting must be supplied explicitly.');
        $money = ['required', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'];
        $rate = ['required', 'string', 'regex:/^\d{1,3}(?:\.\d{1,8})?$/D'];
        $payload = Validator::make($input + ['request' => $request, 'base' => $base], ['sleeve_capital' => $money, 'max_order_notional' => $money,
            'risk_per_trade_pct' => $rate, 'max_position_pct' => $rate, 'daily_loss_pause_pct' => $rate, 'slippage_bps' => $rate, 'limit_offset_bps' => $rate,
            'max_open_positions' => ['required', 'integer', 'min:1', 'max:8'], 'max_orders_per_session' => ['required', 'integer', 'min:1', 'max:20'],
            'quote_max_age_seconds' => ['required', 'integer', 'min:1', 'max:60'], 'order_type' => ['required', 'in:market,limit'],
            'allow_fractional' => ['present'], 'request' => ['required', 'uuid'], 'base' => ['integer', 'min:0']])->validate();
        unset($payload['request'], $payload['base']);
        abort_unless(is_bool($payload['allow_fractional']), 422, 'Fractional shares must be true or false.');
        $zero = Decimal::from('0');
        foreach (['risk_per_trade_pct' => '1', 'max_position_pct' => '20', 'daily_loss_pause_pct' => '10', 'slippage_bps' => '100', 'limit_offset_bps' => '100'] as $key => $max) {
            $v = Decimal::from($payload[$key]);
            $positive = ! in_array($key, ['slippage_bps', 'limit_offset_bps'], true);
            abort_unless(($positive ? $v->compareTo($zero) > 0 : true) && $v->compareTo(Decimal::from($max)) <= 0, 422, 'Live setting outside code bounds.');
        }
        abort_unless(Decimal::from($payload['sleeve_capital'])->compareTo($zero) > 0 && Decimal::from($payload['max_order_notional'])->compareTo($zero) > 0 &&
            Decimal::from($payload['max_order_notional'])->compareTo(Decimal::from($payload['sleeve_capital'])) <= 0, 422, 'Order notional must be positive and within the sleeve.');
        abort_if($payload['allow_fractional'] && ! RobinhoodOrderContract::allowsFractional($payload['order_type']), 422, 'Fractional shares require market orders.');
        abort_if($payload['order_type'] === 'market' && Decimal::from($payload['slippage_bps'])->compareTo(Decimal::from(SignalOrderPrice::MARKET_SLIPPAGE_FLOOR_BPS)) < 0,
            422, 'Market orders need at least '.SignalOrderPrice::MARKET_SLIPPAGE_FLOOR_BPS.' basis points of slippage.');
        $payload['max_open_positions'] = (int) $payload['max_open_positions'];
        $payload['max_orders_per_session'] = (int) $payload['max_orders_per_session'];
        $payload['quote_max_age_seconds'] = (int) $payload['quote_max_age_seconds'];
        $payload['cost_schedule'] = TradingCosts::reviewed();

        return DB::transaction(function () use ($connection, $actor, $base, $request, $payload) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->auth_generation === $connection->auth_generation &&
                $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
            $scope = AccountScope::key($current);
            DB::table('mcp_execution_accounts')->insertOrIgnore(['scope' => $scope]);
            $account = DB::table('mcp_execution_accounts')->where('scope', $scope)->lockForUpdate()->first();
            if ($prior = ExecutionPolicy::where('request_id', $request)->first()) {
                abort_unless($prior->execution_account_id === $account->id && $prior->payload === $payload && $prior->actor_id === $actor, 409, 'Request already used for different settings.');

                return $prior;
            }
            abort_unless((ExecutionPolicy::where('execution_account_id', $account->id)->max('version') ?? 0) === $base, 409, 'Live settings changed. Reload.');

            return ExecutionPolicy::create(['execution_account_id' => $account->id, 'version' => $base + 1, 'actor_id' => $actor, 'request_id' => $request,
                'fingerprint' => self::fingerprint($scope, $base + 1, $payload), 'payload' => $payload, 'applied_at' => now()]);
        });
    }
}
