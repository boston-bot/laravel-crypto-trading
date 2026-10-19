<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SymbolProfile;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/**
 * Immutable daily profiles and playbook assignments from stored, verified history. Local database work only:
 * no network, model call, order or policy change. A profile is never an entry permission.
 */
final class SymbolProfiles
{
    public function __construct(private PaperMarketReferences $references, private ProfileCalculator $calculator, private EarningsCalendar $earnings) {}

    public function record(McpConnection $connection, int $actor, array $symbols, string $requestId, ?CarbonImmutable $cutoff = null): Collection
    {
        Validator::make(['symbols' => $symbols, 'request_id' => $requestId], ['symbols' => ['present', 'array', 'max:100'],
            'symbols.*' => ['string', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'request_id' => ['required', 'uuid']])->validate();
        abort_unless(config('paper.symbol_profiles_enabled'), 409, 'Symbol profiles are disabled.');
        $cutoff ??= CarbonImmutable::now();
        abort_if($cutoff->isFuture(), 422, 'Future cutoffs are not allowed.');
        $this->authorize($connection, $actor);
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
        abort_unless($portfolio && ExecutionPolicies::current($portfolio), 409, 'Applied execution settings are required.');
        $allowed = app(SymbolUniverse::class)->reads($connection, $cutoff);
        $symbols = $symbols === [] ? $allowed : array_values(array_unique($symbols));
        abort_unless(array_diff($symbols, $allowed) === [], 422, 'Profile only symbols in the selected universe.');
        $rows = collect();
        foreach ($symbols as $symbol) {
            $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'symbol-profile:'.$requestId.':'.$symbol)->toString();
            $rows->push($this->store($connection, $actor, $symbol, $request, $cutoff));
        }

        return $rows;
    }

    /** Daily idempotent run after the reference refresh, for each bound operator connection with applied settings. */
    public function tick(): int
    {
        if (! config('paper.symbol_profiles_enabled')) {
            return 0;
        }
        $count = 0;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')->get() as $connection) {
            $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
            if (! $portfolio || ! ExecutionPolicies::current($portfolio)) {
                continue;
            }
            $day = CarbonImmutable::now('America/New_York')->toDateString();
            $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'symbol-profile-daily:'.$connection->id.':'.AccountScope::key($connection).':'.$day)->toString();
            $count += $this->record($connection, $connection->user_id, [], $request)->count();
        }

        return $count;
    }

    public function latest(McpConnection $connection, string $symbol, CarbonImmutable $cutoff): ?SymbolProfile
    {
        $at = $cutoff->utc()->format('Y-m-d H:i:s.u');

        return SymbolProfile::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('symbol', $symbol)
            ->where('cutoff', '<=', $at)->where('recorded_at', '<=', $at)->latest('recorded_at')->latest('id')->first();
    }

    private function store(McpConnection $connection, int $actor, string $symbol, string $request, CarbonImmutable $cutoff): SymbolProfile
    {
        return DB::transaction(function () use ($connection, $actor, $symbol, $request, $cutoff) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->authorize($connection, $actor);
            $scope = AccountScope::key($connection);
            if ($repeat = SymbolProfile::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $request)->first()) {
                abort_unless($repeat->cutoff->eq($cutoff), 409, 'Request already used with a different cutoff.');

                return $repeat;
            }
            $parameters = StrategyParameters::current();
            $history = $this->references->verifiedHistory($connection, $symbol, $cutoff);
            $benchmark = $this->references->verifiedHistory($connection, $parameters['values']['benchmark_symbol'], $cutoff);
            if (! $history['read']) {
                $profile = ['status' => 'unavailable', 'reason' => $history['reason'], 'setup' => null, 'session_date' => null];
            } elseif (! $benchmark['read']) {
                $profile = ['status' => 'unavailable', 'reason' => 'benchmark_'.$benchmark['reason'], 'setup' => null, 'session_date' => null];
            } else {
                $earnings = config('robinhood_mcp.earnings_enabled')
                    ? $this->earnings->status($connection, $symbol, $cutoff, $parameters['values']['earnings_blackout_sessions']) : null;
                $profile = $this->calculator->compute($history['read']->payload['bars'], $benchmark['read']->payload['bars'], $parameters['values'], $earnings);
            }
            $evidence = ['profile_version' => ProfileCalculator::VERSION, 'parameters_version' => $parameters['version'], 'parameters_hash' => $parameters['hash'],
                'history_read_id' => $history['read_id'], 'benchmark_read_id' => $benchmark['read_id'], 'earnings_read_id' => $profile['earnings']['read_id'] ?? null,
                'cutoff' => $cutoff->toISOString()];
            $fingerprint = hash('sha256', json_encode(['evidence' => $evidence, 'profile' => $profile], JSON_THROW_ON_ERROR));

            return SymbolProfile::create(['mcp_connection_id' => $connection->id, 'scope' => $scope, 'request_id' => $request, 'actor_id' => $actor,
                'symbol' => $symbol, 'session_date' => $profile['session_date'], 'profile_version' => ProfileCalculator::VERSION,
                'parameters_version' => $parameters['version'], 'history_read_id' => $history['read']?->id, 'benchmark_read_id' => $benchmark['read']?->id,
                'status' => $profile['status'], 'setup' => $profile['status'] === 'complete' ? $profile['setup'] : null, 'reason' => $profile['reason'],
                'input_fingerprint' => $fingerprint, 'payload' => $profile + ['evidence' => $evidence, 'parameters' => $parameters['values'],
                    'mode' => 'paper_strategy_evidence', 'broker_executable' => false],
                'cutoff' => $cutoff, 'recorded_at' => now()]);
        });
    }

    private function authorize(McpConnection $connection, int $actor): void
    {
        $current = McpConnection::findOrFail($connection->id);
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) &&
            $current->auth_generation === $connection->auth_generation, 409, 'Account authorization or binding changed.');
    }
}
