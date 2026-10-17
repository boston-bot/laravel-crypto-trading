<?php

namespace App\Services\Research;

use App\Models\McpConnection;
use App\Models\Monitoring\Snapshot;
use App\Models\Monitoring\ThesisVersion;
use App\Models\Research\Event;
use App\Models\Research\Source;
use App\Models\Research\WatchlistItem;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ResearchEvents
{
    public static function symbol(mixed $value): string
    {
        $symbol = is_string($value) ? strtoupper(trim($value)) : '';
        if (! preg_match('/^[A-Z][A-Z0-9.]{0,9}$/D', $symbol)) {
            throw new InvalidArgumentException('Invalid equity symbol.');
        }

        return $symbol;
    }

    public static function latestSnapshot(McpConnection $connection, string $scope): ?Snapshot
    {
        return Snapshot::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'complete')->latest('publication_version')->first();
    }

    /** Latest version of each thesis in the account scope, keyed by thesis key. */
    public static function theses(McpConnection $connection, string $scope): Collection
    {
        return ThesisVersion::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('effective_at', '<=', now())
            ->orderBy('id')->get()->groupBy(fn ($t) => $t->payload['thesis_key'])->map->last();
    }

    /**
     * The thesis an event concerns: the referenced thesis (latest version of its key) or, for symbol-level events, the most
     * recently revised open thesis for the symbol. Closed theses are never researched.
     *
     * @return array{0: ?ThesisVersion, 1: ?string} thesis and, when the event should be skipped, the reason
     */
    public static function thesisFor(McpConnection $connection, string $scope, Event $event): array
    {
        $theses = self::theses($connection, $scope);
        if ($event->thesis_version_id) {
            $key = ThesisVersion::where('mcp_connection_id', $connection->id)->where('scope', $scope)->whereKey($event->thesis_version_id)->first()?->payload['thesis_key'];
            $latest = $key ? $theses->get($key) : null;

            return $latest && ! self::closed($latest) ? [$latest, null] : [null, 'thesis_closed_or_missing'];
        }

        return [$theses->filter(fn ($t) => $t->payload['symbol'] === $event->subject && ! self::closed($t))->sortByDesc('id')->first(), null];
    }

    public static function closed(ThesisVersion $thesis): bool
    {
        return ($thesis->payload['status'] ?? 'active') === 'closed';
    }

    /** Held, locally watched or thesis symbols. Research is never requested for symbols supplied by sources or models. */
    public function subjects(McpConnection $connection): array
    {
        $scope = AccountScope::key($connection);
        $held = collect(self::latestSnapshot($connection, $scope)?->observations['holdings'] ?? [])->pluck('symbol')->filter();
        $watched = WatchlistItem::where('mcp_connection_id', $connection->id)->where('scope', $scope)->whereNotNull('active_symbol')->pluck('symbol');
        $theses = self::theses($connection, $scope)->map(fn ($t) => $t->payload['symbol']);

        return $held->merge($watched)->merge($theses)->unique()->sort()->values()->all();
    }

    public function addEvidence(McpConnection $connection, int $actorId, array $input): Event
    {
        $symbol = self::symbol($input['symbol'] ?? null);
        $kind = $input['kind'] ?? null;
        $label = trim((string) ($input['label'] ?? ''));
        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        $url = ($input['url'] ?? '') === '' ? null : (string) $input['url'];
        $parts = $url === null ? null : parse_url($url);
        if (! in_array($kind, ['article', 'filing', 'transcript', 'note'], true) || $label === '' || mb_strlen($label) > 160 ||
            mb_strlen($excerpt) < 20 || mb_strlen($excerpt) > (int) config('research.max_excerpt_chars') ||
            ($url !== null && (strlen($url) > 500 || ! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])))) {
            throw new InvalidArgumentException('Invalid evidence.');
        }
        if (! is_string($input['published_at'] ?? null) || trim($input['published_at']) === '') {
            throw new InvalidArgumentException('A publication time is required.');
        }
        $published = CarbonImmutable::parse($input['published_at'])->utc();
        if ($published->isFuture()) {
            throw new InvalidArgumentException('Publication time cannot be in the future.');
        }
        if (! in_array($symbol, $this->subjects($connection), true)) {
            throw new InvalidArgumentException('Add the symbol to the local watchlist first.');
        }
        $payload = ['label' => $label, 'url' => $url, 'excerpt' => $excerpt];
        $hash = hash('sha256', json_encode([$symbol, $kind, $payload, $published->format(DATE_ATOM)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($connection, $actorId, $symbol, $kind, $payload, $published, $hash): Event {
            $current = $this->lockScope($connection, $actorId);
            $source = $this->storeSource($current, $symbol, 'operator_supplied', $kind, $payload, $published, CarbonImmutable::now(), $actorId, $hash);

            // The same evidence resolves to the same source hash and event, so it cannot trigger research again.
            return $this->record($current, $symbol, 'evidence', 'material', 'evidence:'.$source->content_hash, $source->id);
        });
    }

    public function request(McpConnection $connection, int $actorId, string $symbol, string $requestId, ?string $thesisKey = null): Event
    {
        $symbol = self::symbol($symbol);
        if (! Str::isUuid($requestId) || ! in_array($symbol, $this->subjects($connection), true)) {
            throw new InvalidArgumentException('Research can be requested only for held, watched or thesis symbols.');
        }
        $thesis = $thesisKey === null ? null : self::theses($connection, AccountScope::key($connection))->get($thesisKey);
        if ($thesisKey !== null && (! $thesis || $thesis->payload['symbol'] !== $symbol || self::closed($thesis))) {
            throw new InvalidArgumentException('A targeted request needs an open thesis for the same symbol.');
        }

        return DB::transaction(fn () => $this->record($this->lockScope($connection, $actorId), $symbol, 'operator_request', 'material', 'request:'.$requestId, null, $thesis?->id));
    }

    /** Deterministic triggers from stored observations. Repeated detection coalesces into existing events. */
    public function detect(McpConnection $connection): int
    {
        return DB::transaction(function () use ($connection): int {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'connected' || ! $current->bound_account_id) {
                return 0;
            }
            $scope = AccountScope::key($current);
            $before = Event::where('mcp_connection_id', $current->id)->where('scope', $scope)->count();
            $snapshot = self::latestSnapshot($current, $scope);
            $theses = self::theses($current, $scope);
            foreach ($snapshot?->observations['theses'] ?? [] as $result) {
                if ($result['result'] === 'breached') {
                    $this->record($current, $result['symbol'], 'invalidation_breached', 'material', 'breach:'.$result['version_id'], null, $result['version_id']);
                }
            }
            foreach ($theses as $thesis) {
                if (! self::closed($thesis) && self::reviewDue($thesis->payload['next_review_at'])) {
                    $this->record($current, $thesis->payload['symbol'], 'review_due', 'routine', 'review:'.$thesis->id.':'.$thesis->payload['next_review_at'], null, $thesis->id);
                }
            }
            $covered = $theses->reject(fn ($t) => self::closed($t))->map(fn ($t) => $t->payload['symbol'])->all();
            foreach ($snapshot?->observations['holdings'] ?? [] as $holding) {
                if (is_string($holding['symbol']) && preg_match('/^[A-Z][A-Z0-9.]{0,9}$/D', $holding['symbol']) && ! in_array($holding['symbol'], $covered, true)) {
                    $this->record($current, $holding['symbol'], 'thesis_missing', 'routine', 'missing:'.$holding['symbol']);
                }
            }
            // Missed work is recorded, not replayed later as stale research.
            Event::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('status', 'pending')
                ->where('first_seen_at', '<', now()->subHours((int) config('research.event_expiry_hours')))->update(['status' => 'expired', 'status_reason' => 'stale_event']);

            return Event::where('mcp_connection_id', $current->id)->where('scope', $scope)->count() - $before;
        });
    }

    /** An unreadable review time is treated as due rather than silently never reviewed. */
    private static function reviewDue(mixed $time): bool
    {
        try {
            return CarbonImmutable::parse((string) $time)->lte(now());
        } catch (\Throwable) {
            return true;
        }
    }

    /** Call inside a transaction holding the connection row lock. */
    public function record(McpConnection $current, string $subject, string $kind, string $priority, string $fingerprint, ?int $sourceId = null, ?int $thesisVersionId = null): Event
    {
        $scope = AccountScope::key($current);
        $key = hash('sha256', $subject."\n".$kind."\n".$fingerprint);
        $event = Event::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('event_key', $key)->first();
        if ($event) {
            $event->update(['occurrences' => $event->occurrences + 1, 'last_seen_at' => now()]);

            return $event;
        }

        return Event::create(['mcp_connection_id' => $current->id, 'scope' => $scope, 'event_key' => $key, 'subject' => $subject, 'kind' => $kind,
            'priority' => $priority, 'status' => 'pending', 'source_id' => $sourceId, 'thesis_version_id' => $thesisVersionId, 'occurrences' => 1,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    /** Call inside a transaction holding the connection row lock. Identical content is stored once. */
    public function storeSource(McpConnection $current, string $symbol, string $provider, string $kind, array $payload, CarbonImmutable $asOf, CarbonImmutable $retrievedAt, ?int $actorId, string $hash): Source
    {
        return Source::firstOrCreate(['mcp_connection_id' => $current->id, 'scope' => AccountScope::key($current), 'content_hash' => $hash],
            ['symbol' => $symbol, 'provider' => mb_substr($provider, 0, 64), 'kind' => $kind, 'payload' => $payload,
                'source_as_of' => $asOf, 'retrieved_at' => $retrievedAt, 'actor_id' => $actorId]);
    }

    public function watch(McpConnection $connection, int $actorId, string $symbol, ?string $note): WatchlistItem
    {
        $symbol = self::symbol($symbol);
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Note is too long.');
        }

        return DB::transaction(function () use ($connection, $actorId, $symbol, $note): WatchlistItem {
            $current = $this->lockScope($connection, $actorId);
            $scope = AccountScope::key($current);

            return WatchlistItem::where('mcp_connection_id', $current->id)->where('scope', $scope)->where('active_symbol', $symbol)->first()
                ?? WatchlistItem::create(['mcp_connection_id' => $current->id, 'scope' => $scope, 'symbol' => $symbol, 'active_symbol' => $symbol,
                    'note' => $note, 'added_by' => $actorId, 'added_at' => now()]);
        });
    }

    public function unwatch(McpConnection $connection, int $actorId, int $itemId): void
    {
        DB::transaction(function () use ($connection, $actorId, $itemId): void {
            $current = $this->lockScope($connection, $actorId);
            WatchlistItem::whereKey($itemId)->where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))
                ->whereNotNull('active_symbol')->lockForUpdate()->firstOrFail()
                ->update(['active_symbol' => null, 'removed_by' => $actorId, 'removed_at' => now()]);
        });
    }

    private function lockScope(McpConnection $connection, int $actorId): McpConnection
    {
        $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        if ($current->user_id !== $actorId || AccountScope::key($current) !== AccountScope::key($connection)) {
            throw new InvalidArgumentException('Account scope or actor changed.');
        }

        return $current;
    }
}
