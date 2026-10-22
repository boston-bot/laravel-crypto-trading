<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketReferenceRead;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\MarketData\Alpaca\DailyBars;
use App\Services\MarketData\Alpaca\Listings;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/**
 * Immutable listing and daily-history evidence for the selected symbol universe. Market data only:
 * no broker call, policy change, eligibility grant or order.
 */
final class PaperMarketReferences
{
    public const KINDS = ['daily_history', 'listing'];

    public const DOLLAR_VOLUME_SESSIONS = 20;

    public function __construct(private DailyBars $bars, private Listings $listings) {}

    public function collect(McpConnection $connection, int $actor, array $symbols, string $requestId, bool $includeBenchmark = true): Collection
    {
        Validator::make(['symbols' => $symbols, 'request_id' => $requestId], ['symbols' => ['present', 'array', 'max:100'],
            'symbols.*' => ['string', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'request_id' => ['required', 'uuid']])->validate();
        abort_unless(config('alpaca_data.enabled') && config('alpaca_data.reference_enabled'), 409, 'Market reference reads are disabled.');
        $this->authorize($connection, $actor);
        $scope = AccountScope::key($connection);
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', $scope)->first();
        abort_unless($portfolio && ExecutionPolicies::current($portfolio), 409, 'Applied execution settings are required.');
        $allowed = app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now());
        $symbols = $symbols === [] ? $allowed : array_values(array_unique($symbols));
        abort_unless(array_diff($symbols, $allowed) === [], 422, 'Read only symbols in the selected universe.');
        $requests = [];
        foreach ($symbols as $symbol) {
            foreach (self::KINDS as $kind) {
                $requests[$symbol][$kind] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'market-reference:'.$requestId.':'.$kind.':'.$symbol)->toString();
            }
        }
        // The strategy benchmark's history is always read with the universe; it needs no listing of its own here.
        $benchmark = StrategyParameters::DEFAULTS['benchmark_symbol'];
        if ($includeBenchmark && ! isset($requests[$benchmark])) {
            $requests[$benchmark]['daily_history'] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'market-reference:'.$requestId.':daily_history:'.$benchmark)->toString();
        }
        $ids = $requests === [] ? [] : array_merge(...array_map('array_values', array_values($requests)));
        $existing = MarketReferenceRead::where('mcp_connection_id', $connection->id)->where('scope', $scope)->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $missingHistory = array_values(array_filter(array_keys($requests), fn ($s) => ! isset($existing[$requests[$s]['daily_history']])));
        // Network reads happen outside any transaction; each result is recorded under a fresh authorization check.
        $history = $missingHistory === [] ? [] : $this->bars->readMany($missingHistory, CarbonImmutable::now());
        $rows = collect();
        foreach ($requests as $symbol => $kinds) {
            $rows->push($existing[$kinds['daily_history']] ?? $this->record($connection, $actor, (string) $symbol, 'daily_history', $kinds['daily_history'], $history[$symbol]));
            if (isset($kinds['listing'])) {
                $rows->push($existing[$kinds['listing']] ?? $this->record($connection, $actor, (string) $symbol, 'listing', $kinds['listing'], $this->listings->read((string) $symbol)));
            }
        }

        return $rows;
    }

    /** Daily idempotent refresh for each bound operator connection with applied settings. */
    public function tick(): int
    {
        if (! config('alpaca_data.enabled') || ! config('alpaca_data.reference_enabled')) {
            return 0;
        }
        $count = 0;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')->get() as $connection) {
            $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();
            if (! $portfolio || ! ExecutionPolicies::current($portfolio) || app(SymbolUniverse::class)->reads($connection, CarbonImmutable::now()) === []) {
                continue;
            }
            $day = CarbonImmutable::now('America/New_York')->toDateString();
            $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'market-reference-daily:'.$connection->id.':'.AccountScope::key($connection).':'.$day)->toString();
            $count += $this->collect($connection, $connection->user_id, [], $request)->count();
        }

        return $count;
    }

    /**
     * Verified history facts for the rule evaluator, or the reason they are unknown. Only complete reads that were
     * available locally by the cutoff and are recent enough to include the latest completed sessions count.
     */
    public function history(McpConnection $connection, string $symbol, CarbonImmutable $cutoff): array
    {
        $verified = $this->verifiedHistory($connection, $symbol, $cutoff);
        $read = $verified['read'];
        if (! $read) {
            return ['verified' => false, 'reason' => $verified['reason']] + ($verified['read_id'] ? ['read_id' => $verified['read_id']] : []);
        }
        $facts = $read->payload['facts'];

        return ['verified' => true, 'sessions' => $facts['sessions'], 'average_dollar_volume_20' => $facts['average_dollar_volume_20'],
            'last_session' => $facts['last_session'], 'read_id' => $read->id, 'contract' => $read->contract];
    }

    /** @return array{read: ?MarketReferenceRead, reason: ?string, read_id: ?int} the stored history read the rules may use */
    public function verifiedHistory(McpConnection $connection, string $symbol, CarbonImmutable $cutoff): array
    {
        $read = $this->latest($connection, $symbol, 'daily_history', $cutoff);
        if (! $read) {
            return ['read' => null, 'reason' => 'daily_history_missing', 'read_id' => null];
        }
        if ($read->retrieved_at->lt($cutoff->subHours(max(1, (int) config('alpaca_data.history_max_age_hours'))))) {
            return ['read' => null, 'reason' => 'daily_history_stale', 'read_id' => $read->id];
        }
        // A symbol with no recent bars (halted, delisted or remapped) cannot use an old window as current history.
        if (CarbonImmutable::parse($read->payload['facts']['last_session'])->lt(CarbonImmutable::parse($read->payload['end'])->subDays(10))) {
            return ['read' => null, 'reason' => 'daily_history_not_current', 'read_id' => $read->id];
        }

        return ['read' => $read, 'reason' => null, 'read_id' => $read->id];
    }

    /** Listing evidence: pass, a known failure (OTC, inactive, non-equity) or unknown. */
    public function listing(McpConnection $connection, string $symbol, CarbonImmutable $cutoff): array
    {
        $read = $this->latest($connection, $symbol, 'listing', $cutoff);
        if (! $read) {
            return ['status' => 'unavailable', 'reason' => 'us_exchange_listing_unverified'];
        }
        if ($read->retrieved_at->lt($cutoff->subDays(max(1, (int) config('alpaca_data.listing_max_age_days'))))) {
            return ['status' => 'unavailable', 'reason' => 'us_exchange_listing_stale', 'read_id' => $read->id];
        }
        $listed = $read->payload['us_exchange_listed'];

        return ['status' => $listed === true ? 'pass' : ($listed === false ? 'ineligible' : 'unavailable'), 'reason' => $read->reason,
            'exchange' => $read->payload['asset']['exchange'] ?? null, 'read_id' => $read->id];
    }

    private function latest(McpConnection $connection, string $symbol, string $kind, CarbonImmutable $cutoff): ?MarketReferenceRead
    {
        $at = $cutoff->utc()->format('Y-m-d H:i:s.u');

        return MarketReferenceRead::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('symbol', $symbol)
            ->where('kind', $kind)->where('status', 'complete')->where('retrieved_at', '<=', $at)->where('recorded_at', '<=', $at)
            ->latest('recorded_at')->latest('id')->first();
    }

    private function authorize(McpConnection $connection, int $actor): McpConnection
    {
        $current = McpConnection::findOrFail($connection->id);
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) &&
            $current->auth_generation === $connection->auth_generation, 409, 'Account authorization or binding changed.');

        return $current;
    }

    private function record(McpConnection $connection, int $actor, string $symbol, string $kind, string $requestId, array $read): MarketReferenceRead
    {
        [$complete, $reason, $source] = [$read['complete'] === true, $read['error'] ?? null, null];
        if ($kind === 'daily_history') {
            $read['facts'] = null;
            if ($complete && $read['bars'] === []) {
                [$complete, $reason] = [false, 'no_bars_returned'];
            } elseif ($complete) {
                $read['facts'] = self::facts($read['bars']);
                $reason = 'daily_history_recorded';
                $source = CarbonImmutable::parse($read['facts']['last_session'], 'America/New_York');
            }
        } else {
            $reason = $complete ? $read['reason'] : $reason;
        }

        return DB::transaction(function () use ($connection, $actor, $symbol, $kind, $requestId, $read, $complete, $reason, $source) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->authorize($connection, $actor);
            $scope = AccountScope::key($connection);
            if ($repeat = MarketReferenceRead::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $requestId)->first()) {
                return $repeat;
            }

            return MarketReferenceRead::create(['mcp_connection_id' => $connection->id, 'scope' => $scope, 'request_id' => $requestId,
                'actor_id' => $actor, 'symbol' => $symbol, 'kind' => $kind, 'provider' => $read['provider'], 'contract' => $read['contract'],
                'status' => $complete ? 'complete' : 'incomplete', 'reason' => $reason ?? 'incomplete',
                'payload' => $read + ['mode' => 'paper_reference_evidence', 'broker_executable' => false],
                'source_at' => $source?->utc(), 'retrieved_at' => CarbonImmutable::parse($read['retrieved_at']), 'recorded_at' => now()]);
        });
    }

    /** Exact decimal facts. Dollar volume is split-adjusted close × volume, which a split leaves unchanged. */
    public static function facts(array $bars): array
    {
        $recent = array_slice($bars, -self::DOLLAR_VOLUME_SESSIONS);
        $average = null;
        if (count($recent) === self::DOLLAR_VOLUME_SESSIONS) {
            $sum = Decimal::from('0');
            foreach ($recent as $bar) {
                $sum = $sum->plus(Decimal::from($bar[4])->multipliedBy(Decimal::from($bar[5])));
            }
            // Rounded down so a minimum-liquidity threshold is never met by rounding.
            $average = (string) $sum->dividedBy(Decimal::from((string) self::DOLLAR_VOLUME_SESSIONS), 2, RoundingMode::DOWN);
        }

        return ['sessions' => count($bars), 'first_session' => $bars[0][0], 'last_session' => $bars[array_key_last($bars)][0],
            'average_dollar_volume_20' => $average, 'dollar_volume_basis' => 'mean_close_times_volume_20_sessions_split_adjusted_sip'];
    }
}
