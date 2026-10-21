<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Manual research only, into the existing encrypted replay archive. Never uses the forward paper ledger. */
final class HistoricalQuoteReplays
{
    public function __construct(private IntradayReplayInputs $inputs, private StrategyReplay $engine) {}

    public function run(McpConnection $connection, int $actor, string $request, string $date, array $symbols, string $equity = '500', int $minutes = 60, ?array $sessions = null): StrategyReplayRun
    {
        Validator::make(compact('request', 'equity'), ['request' => ['required', 'uuid'], 'equity' => ['required', 'regex:/^[1-9]\d{0,8}(?:\.\d{1,2})?$/D']])->validate();
        abort_unless(config('alpaca_data.historical_quote_replay_enabled'), 409, 'Historical quote replay is disabled.');
        $this->authorize($connection, $actor);
        sort($symbols);
        if ($sessions !== null) {
            abort_unless($date === '' && $minutes === 390, 422, 'Use --session without --date, with --minutes=390.');
            $date = $sessions[0] ?? '';
        }
        $version = $sessions === null ? StrategyReplay::INTRADAY_VERSION : StrategyReplay::MULTISESSION_VERSION;
        $endDate = $sessions === null ? $date : ($sessions[array_key_last($sessions)] ?? $date);
        $arguments = compact('date', 'symbols', 'equity', 'minutes');
        if ($sessions !== null) {
            $arguments['sessions'] = $sessions;
        }
        $scope = AccountScope::key($connection);
        $find = fn () => StrategyReplayRun::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $request)->first();
        $repeat = function () use ($find, $arguments, $version) {
            $row = $find();
            abort_if($row && ($row->replay_version !== $version || ($row->payload['arguments'] ?? null) !== $arguments), 409, 'Replay request already identifies different inputs.');

            return $row;
        };
        if ($row = $repeat()) {
            return $row;
        }
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', $scope)->firstOrFail();
        $policy = PolicyApplication::where('paper_portfolio_id', $portfolio->id)->where('revision', $portfolio->policy_revision)->firstOrFail();
        $signalPolicy = SignalExecutionPolicies::current($portfolio);
        $settings = ($signalPolicy?->payload ?? SignalExecutionPolicies::DEFAULTS) + ['cost_schedule' => TradingCosts::reviewed()];
        $settings += array_intersect_key($policy->snapshot['rules'], array_flip(['cash_pct', 'active_pct', 'daily_loss_pause_pct']));
        $parameters = SignalParameters::current();
        $data = $sessions === null ? $this->inputs->acquire($date, $symbols, $minutes) : $this->inputs->acquireSessions($sessions, $symbols);
        $result = ! $data['complete'] ? null : ($sessions === null
            ? $this->engine->runQuotes($data, $parameters['values'], $settings, $equity)
            : $this->engine->runQuoteSessions($data['sessions'], $parameters['values'], $settings, $equity));
        $input = ['version' => $version, 'parameters' => $parameters, 'settings' => $settings, 'arguments' => $arguments,
            'profile_version' => ProfileCalculator::VERSION, 'rule_version' => SignalRules::VERSION, 'eligibility_version' => EligibilityRules::VERSION,
            'policy_application_id' => $policy->id, 'signal_policy_id' => $signalPolicy?->id, 'auth_generation' => $connection->auth_generation];
        $fingerprint = hash('sha256', json_encode(['input' => $input, 'data' => $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($connection, $actor, $repeat, $request, $date, $scope, $parameters, $data, $result, $input, $fingerprint, $arguments, $version, $endDate) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->authorize($connection, $actor);
            if ($row = $repeat()) {
                return $row;
            }

            return StrategyReplayRun::create(['mcp_connection_id' => $connection->id, 'scope' => $scope, 'request_id' => $request, 'actor_id' => $actor,
                'replay_version' => $version, 'profile_version' => ProfileCalculator::VERSION, 'rule_version' => SignalRules::VERSION,
                'parameters_hash' => $parameters['hash'], 'status' => $result === null ? 'incomplete' : 'complete', 'reason' => $result === null ? $data['reason'] : 'retrospective_intraday_scenario_recorded',
                'start_date' => $result === null ? null : $date, 'end_date' => $result === null ? null : $endDate, 'input_fingerprint' => $fingerprint,
                'payload' => ['label' => 'retrospective_sampled_quote_scenario_not_forward_or_point_in_time', 'arguments' => $arguments, 'input' => $input,
                    'data' => $data, 'result' => $result, 'broker_executable' => false, 'forward_ledger_changed' => false], 'recorded_at' => now()]);
        });
    }

    private function authorize(McpConnection $connection, int $actor): void
    {
        $current = $connection->fresh();
        abort_unless($current && $current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
            $current->auth_generation === $connection->auth_generation && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account authorization changed.');
    }
}
