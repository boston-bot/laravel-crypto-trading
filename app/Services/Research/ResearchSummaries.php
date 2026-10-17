<?php

namespace App\Services\Research;

use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Run as MonitoringRun;
use App\Models\Monitoring\Snapshot;
use App\Models\Research\Event;
use App\Models\Research\Run;
use App\Models\Research\Summary;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Services\Monitoring\RiskObservations;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Deterministic summaries from stored records. No model call; unavailable measures stay unavailable. */
final class ResearchSummaries
{
    public const LIMITATIONS = [
        'Returns and drawdown need verified flow history and a baseline; unavailable values are not zero and deposits are not gains.',
        'No benchmark comparison: a like-for-like VTI price-return or total-return series has not been verified.',
        'Short samples are not annualized, and model statements are not calibrated probabilities.',
        'Broker observations only; no simulated or paper results are included.',
        'Generated from stored records without an AI call.',
    ];

    public function __construct(private MarketCalendar $calendar, private ResearchBudget $budget) {}

    public function generate(McpConnection $connection, string $kind, CarbonImmutable $at): Summary
    {
        if (! in_array($kind, ['daily', 'weekly'], true) || ! $connection->bound_account_id) {
            throw new InvalidArgumentException('Daily or weekly summary for a bound account required.');
        }
        $local = $at->setTimezone('America/New_York');
        [$period, $from, $end] = $kind === 'daily'
            ? [$local->toDateString(), $local->startOfDay(), $local->endOfDay()]
            : [$local->format('o-\WW'), $local->startOfWeek(), $local->endOfWeek()];
        if ($end->isFuture()) {
            // Summaries are immutable, so an open period would freeze an incomplete record.
            throw new InvalidArgumentException('Only closed periods can be summarized.');
        }
        $until = $end;

        return DB::transaction(function () use ($connection, $kind, $period, $from, $until, $local): Summary {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $scope = AccountScope::key($current);
            $identity = ['mcp_connection_id' => $current->id, 'scope' => $scope, 'kind' => $kind, 'period' => $period];
            if ($existing = Summary::where($identity)->first()) {
                return $existing;
            }
            $window = [$from->utc(), $until->utc()];
            $snapshot = Snapshot::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('status', 'complete')
                ->where('published_at', '<=', $window[1])->latest('publication_version')->first();
            $observations = $snapshot?->observations ?? [];
            $nonFresh = collect($observations['freshness'] ?? [])->filter(fn ($s) => RiskObservations::freshness($s['source_as_of'], $s['retrieved_at']) !== 'fresh')->keys()->values()->all();
            $runs = MonitoringRun::where('mcp_connection_id', $current->id)->where('scope', $scope)->whereBetween('scheduled_at', $window)->get();
            $incidents = Incident::where('mcp_connection_id', $current->id)->where('scope', $scope)->get();
            $research = Run::where('mcp_connection_id', $current->id)->where('scope', $scope)->whereBetween('created_at', $window)->get();
            $cost = $research->reduce(fn ($sum, $run) => $sum->plus(Decimal::from($run->actual_cost)), Decimal::from('0'));
            $theses = collect($observations['theses'] ?? [])->countBy('result')->all();

            return Summary::create($identity + ['generated_at' => now(), 'payload' => [
                'kind' => $kind, 'period' => $period, 'timezone' => 'America/New_York', 'from' => $window[0]->toIso8601String(), 'until' => $window[1]->toIso8601String(),
                'session' => $kind === 'daily' ? $this->calendar->at($local)['status'] : 'see_daily_summaries',
                'snapshot' => $snapshot ? ['published_at' => $snapshot->published_at->toIso8601String(), 'holdings' => count($observations['holdings'] ?? []),
                    'unresolved_orders' => $observations['unresolved_orders'] ?? null, 'pause_reasons' => $observations['pause_reasons'] ?? [], 'non_fresh_reads' => $nonFresh] : null,
                'observations' => ['complete' => $runs->where('status', 'complete')->count(), 'failed' => $runs->where('status', 'failed')->count()],
                'incidents' => ['opened' => $incidents->filter(fn ($i) => $i->first_seen_at->between(...$window))->count(),
                    'open' => $incidents->where('state', 'open')->count(), 'critical_open' => $incidents->where('state', 'open')->where('severity', 'critical')->count()],
                'theses' => $theses,
                'research' => ['runs' => $research->countBy('status')->all(), 'actual_cost' => (string) $cost,
                    'pending_events' => Event::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('status', 'pending')->count()],
                'budget' => $this->budget->status(ResearchBudget::period($window[1])),
                'performance' => $observations['performance'] ?? ['available' => false, 'reason' => 'no_complete_snapshot', 'return' => null, 'drawdown' => null],
                'benchmark' => ['available' => false, 'reason' => 'no_verified_benchmark_series', 'basis' => 'VTI price return and total return are not computed'],
                'limitations' => self::LIMITATIONS,
            ]]);
        });
    }
}
