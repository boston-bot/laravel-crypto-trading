<?php

namespace App\Services\Research;

use App\Models\McpConnection;
use App\Models\Monitoring\Snapshot;
use App\Models\Monitoring\ThesisVersion;
use App\Models\Research\Event;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\RiskObservations;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Bounded, timestamped request context. It omits credentials, account identifiers and absolute balances. */
final class ResearchContext
{
    public function __construct(private MarketDataProvider $market, private ResearchEvents $events) {}

    /** @return array{payload: array, map: array<string, int>, validation: array} */
    public function build(McpConnection $connection, Run $run, Event $event, array $evidenceIds): array
    {
        $snapshot = ResearchEvents::latestSnapshot($connection, $run->scope);
        $observations = $snapshot?->observations ?? [];
        $holding = collect($observations['holdings'] ?? [])->firstWhere('symbol', $run->subject);
        $exposure = $observations['equity_exposure'] ?? null;
        $weight = null;
        if ($holding && $holding['market_value'] !== null && $exposure !== null && Decimal::from($exposure)->compareTo(Decimal::from('0')) > 0) {
            $weight = (string) Decimal::from($holding['market_value'])->multipliedBy(Decimal::from('100'))->dividedBy(Decimal::from($exposure), 2);
        }
        $thesis = $run->thesis_version_id ? ThesisVersion::find($run->thesis_version_id) : null;
        [$quote, $quoteStatus] = $this->quote($connection, $run, $snapshot);
        $sources = Source::whereIn('id', $evidenceIds)->where('mcp_connection_id', $connection->id)->where('scope', $run->scope)
            ->orderByDesc('source_as_of')->orderByDesc('id')->get();
        if ($quote) {
            $sources->push($quote);
        }
        $entries = [];
        $map = [];
        $times = [];
        foreach ($sources->values() as $index => $source) {
            // Opaque per-request IDs: the model can cite only these, and the application maps them back to records.
            $id = 'S'.($index + 1);
            $map[$id] = $source->id;
            $times[$id] = $source->source_as_of->utc()->format(DATE_ATOM);
            $entry = ['id' => $id, 'kind' => $source->kind, 'provider' => $source->provider, 'as_of' => $times[$id], 'retrieved_at' => $source->retrieved_at->utc()->format(DATE_ATOM)];
            if ($source->kind === 'quote') {
                $entry += ['freshness' => RiskObservations::freshness($times[$id], $entry['retrieved_at']), 'quote' => $source->payload['data']];
            } else {
                $entry += ['label' => $source->payload['label'], 'excerpt' => mb_substr($source->payload['excerpt'], 0, (int) config('research.max_excerpt_chars'))];
            }
            $entries[] = $entry;
        }
        $payload = ['task' => $thesis ? 'thesis_review' : 'candidate_screen', 'subject' => $run->subject, 'allowed_symbols' => [$run->subject],
            'requested_at' => CarbonImmutable::now()->utc()->format(DATE_ATOM),
            'trigger' => ['kind' => $event->kind, 'first_seen_at' => $event->first_seen_at->utc()->format(DATE_ATOM)],
            'thesis' => $thesis ? array_intersect_key($thesis->payload, array_flip(['sleeve', 'rationale', 'catalyst', 'entry_conditions', 'horizon', 'next_review_at', 'invalidation', 'status']))
                + ['evaluation' => collect($observations['theses'] ?? [])->firstWhere('version_id', $thesis->id)['result'] ?? 'unavailable'] : null,
            // Without a complete snapshot, holdings are unknown rather than empty.
            'portfolio' => ['snapshot_published_at' => $snapshot?->published_at?->utc()->format(DATE_ATOM), 'coverage' => 'equities_only',
                'held' => $snapshot ? $holding !== null : null, 'weight_of_observed_equity_pct' => $weight, 'holdings_count' => $snapshot ? count($observations['holdings'] ?? []) : null],
            'quote_status' => $quoteStatus, 'sources' => $entries];

        return ['payload' => $payload, 'map' => $map, 'validation' => ['allowed_symbols' => [$run->subject], 'sources' => $times, 'has_thesis' => $thesis !== null]];
    }

    /** Fresh broker quote when available; otherwise the last observed quote with its original times, labeled accordingly. */
    private function quote(McpConnection $connection, Run $run, ?Snapshot $snapshot): array
    {
        try {
            $quote = $this->market->read($connection, 'quotes', $run->subject);
        } catch (ReadFailure $failure) {
            $stored = $snapshot?->payload['quotes'][$run->subject] ?? null;
            $record = $stored['records'][0] ?? null;
            if (! $record) {
                return [null, 'unavailable:'.$failure->reason->value];
            }
            $quote = ['source' => $stored['source'], 'source_as_of' => $record['source_as_of'], 'retrieved_at' => $stored['retrieved_at'],
                'data' => array_intersect_key($record['data'], array_flip(['bid', 'ask', 'last', 'last_session']))];
        }
        if ($quote['source_as_of'] === null) {
            return [null, 'source_time_unknown']; // An undated quote cannot be cited as evidence.
        }
        $asOf = CarbonImmutable::parse($quote['source_as_of']);
        $hash = hash('sha256', json_encode([$run->subject, 'quote', $quote['source'], $asOf->utc()->format(DATE_ATOM), $quote['data']], JSON_THROW_ON_ERROR));
        $source = DB::transaction(function () use ($connection, $run, $quote, $asOf, $hash): Source {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if (! $current->bound_account_id || AccountScope::key($current) !== $run->scope) {
                throw new ResearchFailure(ResearchError::ScopeChanged);
            }

            return $this->events->storeSource($current, $run->subject, $quote['source'], 'quote', ['data' => $quote['data']], $asOf,
                CarbonImmutable::parse($quote['retrieved_at']), null, $hash);
        });

        return [$source, RiskObservations::freshness($quote['source_as_of'], $quote['retrieved_at'])];
    }
}
