<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Models\User;
use App\Services\MarketData\Alpaca\DailyBars;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Stores retrospective daily replays of the live rules as immutable, labeled evidence. Market-data reads only:
 * no broker call, order, paper-ledger change or policy change. Replay results are never forward paper results.
 * Optional research variants (bounded rule overrides or an order mode) replay the same downloaded history beside
 * the live-parameter baseline; they never change live parameters or owner settings.
 */
final class StrategyReplays
{
    public function __construct(private DailyBars $bars, private StrategyReplay $engine) {}

    public const MAX_VARIANTS = 6;

    /** @param list<array{id: string, parameters?: array, execution?: array}> $variants */
    public function run(McpConnection $connection, int $actor, string $requestId, int $years = 3, string $equity = '500', array $symbols = [], array $variants = []): StrategyReplayRun
    {
        Validator::make(['request' => $requestId, 'years' => $years, 'equity' => $equity, 'symbols' => $symbols], ['request' => ['required', 'uuid'],
            'years' => ['integer', 'min:1', 'max:5'], 'equity' => ['regex:/^\d{1,9}(?:\.\d{1,2})?$/D'], 'symbols' => ['array', 'max:100'],
            'symbols.*' => ['string', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D']])->validate();
        $variants = $this->variants($variants);
        abort_unless(config('alpaca_data.enabled') && config('alpaca_data.reference_enabled'), 409, 'Market reference reads are disabled.');
        $this->authorize($connection, $actor);
        $scope = AccountScope::key($connection);
        if ($repeat = StrategyReplayRun::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $requestId)->first()) {
            return $repeat;
        }
        $now = CarbonImmutable::now();
        $today = $now->setTimezone('America/New_York')->startOfDay();
        $start = $today->subYearsNoOverflow($years);
        $end = $today->subDay();
        // Default universe: today's nominating watchlist symbols, the ones the strategy could enter.
        $symbols = $symbols === [] ? app(SymbolUniverse::class)->candidates($connection, $now) : array_values(array_unique($symbols));
        $parameters = SignalParameters::current();
        $benchmark = $parameters['values']['benchmark_symbol'];
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', $scope)->first();
        $applied = $portfolio ? SignalExecutionPolicies::current($portfolio) : null;
        $settings = ($applied?->payload ?? SignalExecutionPolicies::DEFAULTS) + ['cost_schedule' => TradingCosts::reviewed()];
        $lookback = (int) $start->diffInDays($end) + 400;
        $reads = $symbols === [] ? [] : $this->bars->readMany([...array_diff($symbols, [$benchmark]), $benchmark], $now, $lookback);
        $bars = [];
        $excluded = [];
        $fingerprints = [];
        foreach ($reads as $symbol => $read) {
            if ($read['complete'] !== true || $read['bars'] === []) {
                $excluded[$symbol] = $read['error'] ?? 'no_bars_returned';

                continue;
            }
            $fingerprints[$symbol] = hash('sha256', json_encode($read['bars'], JSON_THROW_ON_ERROR));
            if ($symbol !== $benchmark || in_array($benchmark, $symbols, true)) {
                $bars[$symbol] = $read['bars'];
            }
        }
        ksort($fingerprints);
        $input = ['replay' => StrategyReplay::VERSION, 'profile' => ProfileCalculator::VERSION, 'rules' => SignalRules::VERSION,
            'parameters' => $parameters['hash'], 'settings' => $settings, 'equity' => $equity, 'bars' => $fingerprints, 'as_of' => $today->toDateString(),
            'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()], 'variants' => $variants];
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $result = null;
        $reason = match (true) {
            $symbols === [] => 'no_nominated_symbols',
            ! isset($fingerprints[$benchmark]) => 'benchmark_history_unavailable',
            $bars === [] => 'no_symbol_history',
            default => null,
        };
        $variantResults = [];
        if ($reason === null) {
            $result = $this->engine->run($bars, $reads[$benchmark]['bars'], $parameters['values'], $settings, $equity, $start->toDateString(), $end->toDateString());
            $reason = $result['sessions'] > 0 ? 'retrospective_replay_recorded' : 'no_sessions_with_sufficient_warmup_in_requested_window';
        }
        $complete = $reason === 'retrospective_replay_recorded';
        foreach ($complete ? $variants : [] as $variant) {
            $values = array_replace($parameters['values'], $variant['parameters']);
            $variantResults[] = $variant + ['parameters_hash' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
                'result' => $this->engine->run($bars, $reads[$benchmark]['bars'], $values, $variant['execution'] + $settings, $equity, $start->toDateString(), $end->toDateString())];
        }

        return DB::transaction(function () use ($connection, $actor, $requestId, $scope, $parameters, $complete, $reason, $result, $fingerprint, $input, $symbols, $excluded, $applied, $variantResults) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->authorize($connection, $actor);
            if ($repeat = StrategyReplayRun::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $requestId)->first()) {
                return $repeat;
            }

            return StrategyReplayRun::create(['mcp_connection_id' => $connection->id, 'scope' => $scope, 'request_id' => $requestId, 'actor_id' => $actor,
                'replay_version' => StrategyReplay::VERSION, 'profile_version' => ProfileCalculator::VERSION, 'rule_version' => SignalRules::VERSION,
                'parameters_hash' => $parameters['hash'], 'status' => $complete ? 'complete' : 'incomplete', 'reason' => $reason,
                'start_date' => $complete ? $result['start'] : null, 'end_date' => $complete ? $result['end'] : null, 'input_fingerprint' => $fingerprint,
                'payload' => ['label' => 'retrospective_daily_replay_not_forward_results', 'input' => $input, 'symbols' => $symbols, 'excluded' => $excluded,
                    'settings_source' => $applied ? 'applied_signal_execution_policy_'.$applied->version : 'signal_execution_defaults',
                    'result' => $result, 'variants' => $variantResults, 'broker_executable' => false],
                'recorded_at' => now()]);
        });
    }

    /** Bounded research variants; none may repeat the baseline or another variant. */
    private function variants(array $variants): array
    {
        abort_unless(array_is_list($variants) && count($variants) <= self::MAX_VARIANTS, 422, 'Use at most '.self::MAX_VARIANTS.' variants.');
        // Compared by effective values, so restating a live default cannot duplicate the baseline.
        $effective = function (array $parameters, array $execution): string {
            $values = array_replace(SignalParameters::current()['values'] + array_fill_keys(ResearchVariants::FLAGS, true), $parameters);
            ksort($values);

            return hash('sha256', json_encode([$values, $execution], JSON_THROW_ON_ERROR));
        };
        $seen = [$effective([], ['order_mode' => 'whole_share_limit']) => 'baseline'];
        $normalized = [];
        foreach ($variants as $variant) {
            abort_unless(is_array($variant) && ! array_diff(array_keys($variant), ['id', 'parameters', 'execution']) && is_string($variant['id'] ?? null) &&
                preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $variant['id']) && $variant['id'] !== 'baseline' && is_array($variant['parameters'] ?? []) && is_array($variant['execution'] ?? []), 422, 'Malformed replay variant.');
            $parameters = ResearchVariants::parameters($variant['parameters'] ?? []);
            ksort($parameters);
            $execution = ResearchVariants::execution($variant['execution'] ?? []);
            $key = $effective($parameters, $execution);
            abort_if(isset($seen[$key]) || in_array($variant['id'], $seen, true), 422, 'Duplicate replay variant.');
            $seen[$key] = $variant['id'];
            $normalized[] = ['id' => $variant['id'], 'parameters' => $parameters, 'execution' => $execution];
        }

        return $normalized;
    }

    private function authorize(McpConnection $connection, int $actor): void
    {
        $current = McpConnection::findOrFail($connection->id);
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) &&
            $current->auth_generation === $connection->auth_generation, 409, 'Account authorization or binding changed.');
    }
}
