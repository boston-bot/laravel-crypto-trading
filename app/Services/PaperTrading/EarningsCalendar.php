<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\EarningsCalendarRead;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/**
 * Immutable market-wide earnings calendar reads (reviewed `get_earnings_calendar`, all names, 31 days) and the
 * deterministic entry blackout. Tentative dates count as real; unknown timing is treated as the worst case.
 * A missing, stale or too-short calendar, or an unknown trading session in the horizon, is unknown, never a pass.
 */
final class EarningsCalendar
{
    public const CONTRACT = 'robinhood_earnings_calendar_v1';

    public function __construct(private BrokerGateway $broker) {}

    public function collect(McpConnection $connection, int $actor, string $requestId): EarningsCalendarRead
    {
        Validator::make(['request_id' => $requestId], ['request_id' => ['required', 'uuid']])->validate();
        abort_unless(config('robinhood_mcp.earnings_enabled'), 409, 'Earnings calendar reads are disabled.');
        $this->authorize($connection, $actor);
        $scope = AccountScope::key($connection);
        if ($repeat = EarningsCalendarRead::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $requestId)->first()) {
            return $repeat;
        }
        $start = CarbonImmutable::now('America/New_York')->toDateString();
        $days = (int) config('robinhood_mcp.operations.earnings_calendar.arguments.days');
        $read = ['provider' => TrustedHttp::RESOURCE, 'contract' => self::CONTRACT, 'tool_fingerprint' => config('robinhood_mcp.operations.earnings_calendar.fingerprint'),
            'start_date' => $start, 'days' => $days, 'filter' => 'all_names', 'events' => [], 'complete' => false, 'error' => null, 'retrieved_at' => null];
        try {
            // Network read outside any transaction; recorded below under a fresh authorization check.
            $result = $this->broker->within($connection, fn () => $this->broker->read($connection, Operation::EarningsCalendar, ['start_date' => $start]));
            $read['retrieved_at'] = CarbonImmutable::instance($result->retrievedAt)->toISOString();
            if ($result->source !== TrustedHttp::RESOURCE || ! $result->complete || $result->continuation !== null) {
                throw new ReadFailure($result->incompleteReason ?? ReadError::Incomplete);
            }
            $groups = [];
            foreach ($result->records as $record) {
                if ($record->operation !== Operation::EarningsCalendar) {
                    throw new ReadFailure(ReadError::Malformed);
                }
                $f = $record->fields;
                $groups[$f['id']][] = [$f['symbol'], $f['fiscal_year'], $f['fiscal_quarter'], $f['report_date'], $f['report_timing'], $f['report_verified'],
                    isset($f['eps_estimate']) ? (string) $f['eps_estimate'] : null, isset($f['eps_actual']) ? (string) $f['eps_actual'] : null];
            }
            $read['rows'] = count($result->records);
            $read['repeated_events'] = 0;
            $read['conflicting_events'] = 0;
            foreach ($groups as $rows) {
                $read['events'][] = self::merge($rows);
                if (count($rows) > 1) {
                    $read['repeated_events']++;
                    $read['conflicting_events'] += count(array_unique(array_map('serialize', $rows))) > 1 ? 1 : 0;
                }
            }
            $read['complete'] = true;
        } catch (ReadFailure $failure) {
            $read['error'] = $failure->reason->value;
            $read['events'] = [];
        }
        $read['retrieved_at'] ??= CarbonImmutable::now()->toISOString();

        return DB::transaction(function () use ($connection, $actor, $requestId, $read, $scope) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->authorize($connection, $actor);
            if ($repeat = EarningsCalendarRead::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('request_id', $requestId)->first()) {
                return $repeat;
            }

            return EarningsCalendarRead::create(['mcp_connection_id' => $connection->id, 'scope' => $scope, 'request_id' => $requestId, 'actor_id' => $actor,
                'start_date' => $read['start_date'], 'days' => $read['days'], 'status' => $read['complete'] ? 'complete' : 'incomplete',
                'reason' => $read['complete'] ? 'earnings_calendar_recorded' : ($read['error'] ?? 'incomplete'),
                'event_count' => $read['complete'] ? count($read['events']) : null,
                'payload' => $read + ['mode' => 'paper_reference_evidence', 'broker_executable' => false],
                'retrieved_at' => CarbonImmutable::parse($read['retrieved_at']), 'recorded_at' => now()]);
        });
    }

    /**
     * One event from repeats sharing company, quarter and report date. Disagreements resolve to the cautious side:
     * unknown timing, unconfirmed, unknown estimate, and not yet reported unless every copy agrees on the actual.
     */
    public static function merge(array $rows): array
    {
        $field = function (int $index) use ($rows) {
            $values = array_unique(array_map(fn ($row) => serialize($row[$index]), $rows));

            return count($values) === 1 ? $rows[0][$index] : null;
        };

        return [$rows[0][0], $rows[0][1], $rows[0][2], $rows[0][3], $field(4),
            ! in_array(false, array_map(fn ($row) => $row[5] === true, $rows), true), $field(6), $field(7), count($rows)];
    }

    /** Daily idempotent refresh for each bound operator connection. */
    public function tick(): int
    {
        if (! config('robinhood_mcp.earnings_enabled')) {
            return 0;
        }
        $count = 0;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')->get() as $connection) {
            $day = CarbonImmutable::now('America/New_York')->toDateString();
            $this->collect($connection, $connection->user_id, Uuid::uuid5(Uuid::NAMESPACE_URL, 'earnings-calendar-daily:'.$connection->id.':'.AccountScope::key($connection).':'.$day)->toString());
            $count++;
        }

        return $count;
    }

    /**
     * Whether a report is scheduled within the next $sessions trading sessions (counting the cutoff's own session).
     *
     * @return array{status: string, reason: string, read_id: ?int, horizon_end?: string, next_report?: ?array}
     */
    public function status(McpConnection $connection, string $symbol, CarbonImmutable $cutoff, int $sessions): array
    {
        $at = $cutoff->utc()->format('Y-m-d H:i:s.u');
        $read = EarningsCalendarRead::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('status', 'complete')
            ->where('retrieved_at', '<=', $at)->where('recorded_at', '<=', $at)->latest('recorded_at')->latest('id')->first();
        if (! $read) {
            return ['status' => 'unavailable', 'reason' => 'earnings_calendar_missing', 'read_id' => null];
        }
        if ($read->retrieved_at->lt($cutoff->subHours(max(1, (int) config('alpaca_data.history_max_age_hours'))))) {
            return ['status' => 'unavailable', 'reason' => 'earnings_calendar_stale', 'read_id' => $read->id];
        }
        $today = $cutoff->setTimezone('America/New_York')->startOfDay();
        $horizon = null;
        $found = 0;
        for ($day = $today; $found < $sessions && $day->lte($today->addDays(20)); $day = $day->addDay()) {
            $state = app(MarketCalendar::class)->at($day->setTime(12, 0))['status'];
            if ($state === 'unknown') {
                return ['status' => 'unavailable', 'reason' => 'session_calendar_unknown', 'read_id' => $read->id];
            }
            if ($state !== 'holiday') {
                $found++;
                $horizon = $day->toDateString();
            }
        }
        $windowEnd = $read->start_date->addDays($read->days - 1)->toDateString();
        if ($found < $sessions || $read->start_date->toDateString() > $today->toDateString() || $windowEnd < $horizon) {
            return ['status' => 'unavailable', 'reason' => 'earnings_window_too_short', 'read_id' => $read->id];
        }
        $next = null;
        foreach ($read->payload['events'] as [$eventSymbol, $year, $quarter, $date, $timing, $verified, , $actual]) {
            // An event is upcoming until it reports an actual EPS; today's report counts even if timed before the open.
            if ($eventSymbol !== $symbol || $date === null || $actual !== null || $date < $today->toDateString()) {
                continue;
            }
            if ($next === null || $date < $next['date']) {
                $next = ['date' => $date, 'timing' => $timing, 'verified' => $verified, 'fiscal_year' => $year, 'fiscal_quarter' => $quarter];
            }
        }
        $blocked = $next !== null && $next['date'] <= $horizon;

        return ['status' => $blocked ? 'blocked' : 'pass', 'reason' => $blocked ? 'earnings_within_blackout' : 'no_report_within_blackout',
            'read_id' => $read->id, 'horizon_end' => $horizon, 'window_end' => $windowEnd, 'next_report' => $next];
    }

    private function authorize(McpConnection $connection, int $actor): void
    {
        $current = McpConnection::findOrFail($connection->id);
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) &&
            $current->auth_generation === $connection->auth_generation, 409, 'Account authorization or binding changed.');
    }
}
