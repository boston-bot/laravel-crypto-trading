<?php

namespace App\Http\Controllers;

use App\Jobs\ResearchJob;
use App\Models\McpConnection;
use App\Models\Monitoring\ThesisVersion;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\VersionLedger;
use App\Services\Research\ResearchEvents;
use App\Services\Research\ResearchReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Operator research actions. None of them creates an order intent, a broker request or a policy change. */
class ResearchController extends Controller
{
    public function evidence(Request $request, ResearchEvents $events)
    {
        $data = $request->validate(['symbol' => ['required', 'string', 'max:16'], 'kind' => ['required', Rule::in(['article', 'filing', 'transcript', 'note'])],
            'label' => ['required', 'string', 'max:160'], 'url' => ['nullable', 'string', 'max:500'], 'published_at' => ['required', 'date'],
            'excerpt' => ['required', 'string', 'min:20', 'max:'.config('research.max_excerpt_chars')]]);
        $data['published_at'] = CarbonImmutable::parse($data['published_at'], 'America/Chicago')->utc()->toIso8601String();

        return $this->attempt(fn () => $events->addEvidence($this->connection($request), $request->user()->id, $data), 'Evidence recorded as untrusted data.');
    }

    public function request(Request $request, ResearchEvents $events)
    {
        $data = $request->validate(['symbol' => ['required', 'string', 'max:16'], 'thesis_key' => ['nullable', 'uuid'], 'request_id' => ['required', 'uuid']]);

        return $this->attempt(function () use ($request, $events, $data) {
            $event = $events->request($this->connection($request), $request->user()->id, $data['symbol'], $data['request_id'], $data['thesis_key'] ?? null);
            if ($reason = ResearchReadiness::reason()) {
                return 'Research request recorded; research unavailable: '.$reason->value.'.';
            }
            if ($event->status !== 'pending' || $event->dispatched_at) {
                return 'Research request already recorded.';
            }
            ResearchJob::dispatch($event->id, $event->scope);
            $event->update(['dispatched_at' => now()]);

            return 'Research request queued for the research worker.';
        }, 'Research request recorded.');
    }

    public function watch(Request $request, ResearchEvents $events)
    {
        $data = $request->validate(['symbol' => ['required', 'string', 'max:16'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $events->watch($this->connection($request), $request->user()->id, $data['symbol'], $data['note'] ?? null), 'Added to the local research watchlist. No brokerage watchlist was changed.');
    }

    public function unwatch(Request $request, ResearchEvents $events, int $item)
    {
        return $this->attempt(fn () => $events->unwatch($this->connection($request), $request->user()->id, $item), 'Removed from the local research watchlist.');
    }

    public function thesis(Request $request, VersionLedger $ledger, ResearchEvents $events)
    {
        $data = $request->validate(['thesis_key' => ['nullable', 'uuid'], 'symbol' => ['required', 'string', 'max:16'], 'sleeve' => ['required', Rule::in(['long_term', 'active', 'unclassified'])],
            'status' => ['required', Rule::in(['active', 'watch', 'closed'])], 'rationale' => ['required', 'string', 'max:2000'], 'catalyst' => ['nullable', 'string', 'max:500'],
            'entry_conditions' => ['nullable', 'string', 'max:1000'], 'horizon' => ['required', Rule::in(['days', 'weeks', 'months', 'years'])], 'next_review_at' => ['required', 'date'],
            'invalidation_type' => ['required', Rule::in(['price', 'completed_session_close', 'fundamental_event', 'manual_review'])],
            'invalidation_operator' => ['nullable', Rule::in(['lte', 'gte'])], 'invalidation_threshold' => ['nullable', 'string', 'max:40'],
            'invalidation_description' => ['nullable', 'string', 'max:300'], 'sources' => ['array', 'max:20'], 'sources.*' => ['integer']]);
        $connection = $this->connection($request);

        return $this->attempt(function () use ($connection, $request, $ledger, $events, $data) {
            $scope = AccountScope::key($connection);
            $symbol = ResearchEvents::symbol($data['symbol']);
            $key = $data['thesis_key'] ?? null;
            $existing = $key ? ResearchEvents::theses($connection, $scope)->get($key) : null;
            $sources = array_values(array_unique($data['sources'] ?? []));
            if (($key && (! $existing || $existing->payload['symbol'] !== $symbol)) || ! in_array($symbol, $events->subjects($connection), true) ||
                Source::whereIn('id', $sources)->where('mcp_connection_id', $connection->id)->where('scope', $scope)->count() !== count($sources)) {
                throw new InvalidArgumentException('Thesis, symbol or sources are outside this account scope.');
            }
            $review = CarbonImmutable::parse($data['next_review_at'], 'America/Chicago');
            if ($review->isPast()) {
                throw new InvalidArgumentException('Next review must be in the future.');
            }
            $invalidation = ['type' => $data['invalidation_type'], 'description' => $data['invalidation_description'] ?? null];
            if (in_array($data['invalidation_type'], ['price', 'completed_session_close'], true)) {
                $invalidation += ['operator' => $data['invalidation_operator'] ?? null, 'threshold' => $data['invalidation_threshold'] ?? null];
            }
            $ledger->thesis($connection, $request->user()->id, ['thesis_key' => $key ?? (string) Str::uuid(), 'symbol' => $symbol,
                'sleeve' => $data['sleeve'], 'status' => $data['status'], 'rationale' => $data['rationale'], 'catalyst' => $data['catalyst'] ?? null,
                'entry_conditions' => $data['entry_conditions'] ?? null, 'sources' => $sources, 'horizon' => $data['horizon'],
                'next_review_at' => $review->utc()->toIso8601String(), 'invalidation' => $invalidation, 'origin' => 'operator']);
        }, 'Thesis version recorded.');
    }

    /** The operator, not the model, decides whether validated research becomes a new thesis version. */
    public function adopt(Request $request, VersionLedger $ledger, string $run)
    {
        abort_unless(Str::isUuid($run), 404);
        $connection = $this->connection($request);
        $scope = AccountScope::key($connection);
        $record = Run::whereKey($run)->where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'succeeded')->firstOrFail();

        return $this->attempt(fn () => DB::transaction(function () use ($connection, $request, $ledger, $record, $scope) {
            McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail(); // serializes repeat submissions
            $versions = ThesisVersion::where('mcp_connection_id', $connection->id)->where('scope', $scope)->get();
            $researched = $record->thesis_version_id ? $versions->firstWhere('id', $record->thesis_version_id) : null;
            $current = $researched ? ResearchEvents::theses($connection, $scope)->get($researched->payload['thesis_key']) : null;
            // Only current research is adoptable: never an expired result, a superseded or closed thesis, or a repeat adoption.
            if ($record->expires_at->isPast() || $versions->contains(fn ($v) => ($v->payload['origin'] ?? null) === 'research_run:'.$record->id) ||
                ($record->thesis_version_id && (! $current || $current->id !== $record->thesis_version_id || ResearchEvents::closed($current)))) {
                throw new InvalidArgumentException('Research is stale, already adopted, or its thesis has changed.');
            }
            $result = $record->result;
            $proposal = $result['proposal'];
            $ledger->thesis($connection, $request->user()->id, ['thesis_key' => $current?->payload['thesis_key'] ?? (string) Str::uuid(), 'symbol' => $record->subject,
                'sleeve' => $current?->payload['sleeve'] ?? 'unclassified', 'status' => $current?->payload['status'] ?? 'watch', 'rationale' => $result['summary'],
                'catalyst' => $proposal['catalyst'], 'entry_conditions' => $current?->payload['entry_conditions'] ?? null,
                'sources' => collect($result['material_facts'])->flatMap(fn ($f) => $f['source_record_ids'])->unique()->values()->all(), 'horizon' => $proposal['horizon'],
                'next_review_at' => $proposal['review_at'], 'invalidation' => array_filter($proposal['invalidation'], fn ($value) => $value !== null), 'origin' => 'research_run:'.$record->id]);
        }), 'Research adopted as a new thesis version by the signed-in operator.');
    }

    private function connection(Request $request): McpConnection
    {
        $connection = McpConnection::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($connection->bound_account_id, 404);

        return $connection;
    }

    private function attempt(callable $action, string $message)
    {
        try {
            $result = $action();

            return back()->with('monitor_status', is_string($result) ? $result : $message);
        } catch (InvalidArgumentException) {
            return back()->with('monitor_status', 'Not recorded: input is invalid or outside this account scope.');
        }
    }
}
