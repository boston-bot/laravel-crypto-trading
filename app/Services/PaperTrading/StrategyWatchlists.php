<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\MarketSession;
use App\Models\PaperTrading\WatchlistRead;
use App\Models\PaperTrading\WatchlistRevision;
use App\Models\PaperTrading\WatchlistSetting;
use App\Models\User;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodWatchlists;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\IncidentRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/** Intake only. Nominations never grant execution eligibility, change policy or interfere with exits. */
final class StrategyWatchlists
{
    /** `nominate` lists put symbols up for evaluation; `data_only` lists only warm up data collection. */
    public const ROLES = ['nominate', 'data_only'];

    /** Owner cap, applied separately to the combined items of each role. Exceeding either rejects the whole read. */
    public const ROLE_CAP = 75;

    public function __construct(private RobinhoodWatchlists $reader, private IncidentRecorder $incidents) {}

    /** Configured lists; a legacy single-name version is one nominating list. */
    public static function lists(WatchlistSetting $setting): array
    {
        return $setting->payload['lists'] ?? [['name' => $setting->payload['name'], 'role' => 'nominate']];
    }

    public function setting(McpConnection $connection, ?CarbonImmutable $cutoff = null): ?WatchlistSetting
    {
        return WatchlistSetting::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))
            ->when($cutoff, fn ($q) => $q->where('recorded_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u')))->latest('version')->first();
    }

    /** @param string|list<array{name: string, role: string}> $lists one name (a nominating list) or up to five named lists with roles */
    public function configure(McpConnection $connection, int $actor, int $base, string $request, string|array $lists): WatchlistSetting
    {
        $lists = array_map(fn ($list) => ['name' => trim((string) ($list['name'] ?? '')), 'role' => $list['role'] ?? null],
            is_string($lists) ? [['name' => $lists, 'role' => 'nominate']] : array_values($lists));
        Validator::make(['lists' => $lists, 'request' => $request, 'base' => $base], ['lists' => ['required', 'array', 'min:1', 'max:5'],
            'lists.*.name' => ['required', 'string', 'max:128'], 'lists.*.role' => ['required', Rule::in(self::ROLES)],
            'request' => ['required', 'uuid'], 'base' => ['integer', 'min:0']])->validate();
        $names = array_map(fn ($list) => strtolower($list['name']), $lists);
        abort_if(in_array('on watch', $names, true), 422, 'Use dedicated strategy lists, separate from On Watch.');
        abort_if(count(array_unique($names)) !== count($names), 422, 'Each list may appear only once.');
        abort_unless(in_array('nominate', array_column($lists, 'role'), true), 422, 'At least one list must nominate symbols.');
        // A single nominating list keeps the original payload shape.
        $payload = count($lists) === 1 && $lists[0]['role'] === 'nominate' ? ['name' => $lists[0]['name']] : ['lists' => $lists];

        return DB::transaction(function () use ($connection, $actor, $base, $request, $payload) {
            $this->authorize($connection, $actor, true);
            $query = WatchlistSetting::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection));
            if ($repeat = (clone $query)->where('request_id', $request)->first()) {
                abort_unless($repeat->payload === $payload, 409, 'Request already used for different lists.');

                return $repeat;
            }
            abort_unless(($this->setting($connection)?->version ?? 0) === $base, 409, 'Watchlist settings changed. Reload before saving.');

            return WatchlistSetting::create(['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection),
                'actor_id' => $actor, 'version' => $base + 1, 'request_id' => $request, 'payload' => $payload, 'recorded_at' => now()]);
        });
    }

    public function collect(McpConnection $connection, int $actor, string $request): WatchlistRead
    {
        Validator::make(['request' => $request], ['request' => ['required', 'uuid']])->validate();
        abort_unless(config('paper.watchlist_intake_enabled'), 409, 'Strategy watchlist intake is disabled.');
        $this->authorize($connection, $actor);
        $setting = $this->setting($connection);
        abort_unless($setting, 409, 'Configure a dedicated strategy watchlist first.');
        $lock = Cache::lock('strategy-watchlist:'.$connection->id, 180);
        abort_unless($lock->get(), 409, 'Watchlist read already in progress.');
        try {
            if ($repeat = WatchlistRead::where('setting_id', $setting->id)->where('request_id', $request)->first()) {
                return $repeat;
            }
            $reads = null;
            $reason = null;
            $lists = self::lists($setting);
            try {
                // Every configured list in one session; any failure fails the whole observation.
                $reads = $this->reader->namedMany(clone $connection, array_column($lists, 'name'));
                $counts = array_fill_keys(self::ROLES, 0);
                foreach ($lists as $list) {
                    $counts[$list['role']] += count($reads[$list['name']]['items']);
                }
                if (max($counts) > self::ROLE_CAP) {
                    $reason = 'watchlist_over_cap';
                }
            } catch (ReadFailure $failure) {
                $reason = 'broker_'.$failure->reason->value;
            }

            // No database lock spans external calls. Changed credentials, binding or settings invalidate the result.
            return DB::transaction(function () use ($connection, $actor, $setting, $request, $reads, $lists, $reason) {
                $this->authorize($connection, $actor, true);
                abort_unless(config('paper.watchlist_intake_enabled') && $this->setting($connection)?->id === $setting->id, 409, 'Watchlist settings changed during read.');
                if ($repeat = WatchlistRead::where('setting_id', $setting->id)->where('request_id', $request)->first()) {
                    return $repeat;
                }
                $previous = WatchlistRevision::where('setting_id', $setting->id)->latest('id')->first();
                $ids = $reads === null ? [] : array_map(fn ($read) => $read['watchlist']->fields['id'], $reads);
                // Each list's identity is pinned for this settings version; a same-named replacement is rejected.
                $pinned = $previous ? ($previous->payload['list_ids'] ?? [$lists[0]['name'] => $previous->payload['list_id']]) : [];
                if (! $reason && array_diff_assoc(array_intersect_key($pinned, $ids), array_intersect_key($ids, $pinned)) !== []) {
                    $reason = 'watchlist_identity_changed';
                }
                $revision = null;
                if (! $reason) {
                    $at = min(array_map(fn ($read) => CarbonImmutable::parse($read['retrieved_at']), $reads));
                    if ($at->isFuture() || $at->lt(CarbonImmutable::now()->subMinutes(5)) || ($previous && $at->lt($previous->retrieved_at))) {
                        $reason = 'watchlist_read_time_invalid';
                    } else {
                        $members = [];
                        foreach ($lists as $list) {
                            foreach ($reads[$list['name']]['items'] as $item) {
                                $f = $item->fields;
                                $skip = $f['object_type'] !== 'instrument' ? 'non_equity_item' :
                                    (! is_string($f['symbol'] ?? null) || ! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $f['symbol']) ? 'symbol_unavailable' : null);
                                $members[] = ['id' => $f['id'], 'object_type' => $f['object_type'], 'symbol' => $f['symbol'] ?? null, 'skip_reason' => $skip,
                                    'list' => $list['name'], 'role' => $list['role']];
                            }
                        }
                        $first = $reads[$lists[0]['name']];
                        usort($members, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                        $hash = hash('sha256', json_encode($members, JSON_THROW_ON_ERROR));
                        $revision = WatchlistRevision::create(['setting_id' => $setting->id, 'auth_generation' => $connection->auth_generation,
                            'request_id' => $request, 'membership_hash' => $hash, 'payload' => ['members' => $members,
                                'list_id' => $ids[$lists[0]['name']], 'list_ids' => $ids, 'source' => $first['source'], 'source_as_of' => null,
                                'completeness' => $first['completeness'], 'consistency' => $first['consistency'], 'eligibility' => 'unverified'],
                            'retrieved_at' => $at, 'changed_at' => $previous?->membership_hash === $hash ? $previous->changed_at : $at, 'recorded_at' => now()]);
                    }
                }
                $attempt = WatchlistRead::create(['setting_id' => $setting->id, 'request_id' => $request, 'revision_id' => $revision?->id, 'reason' => $reason, 'recorded_at' => now()]);
                if ($reason) {
                    $this->incidents->record($connection, 'strategy_watchlist_unavailable', 'warning', 'watchlist');
                    if ($reason === 'watchlist_over_cap') {
                        $this->incidents->record($connection, 'strategy_watchlist_over_cap', 'warning', 'watchlist');
                    }
                } else {
                    $this->incidents->resolve($connection, 'strategy_watchlist_unavailable');
                    $this->incidents->resolve($connection, 'strategy_watchlist_over_cap');
                    $this->incidents->resolve($connection, 'strategy_watchlist_expired');
                    $closed = MarketSession::where('exchange', 'XNYS')->whereIn('status', ['regular', 'early_close'])
                        ->where('opens_at', '>=', $revision->changed_at)->where('closes_at', '<=', now())
                        ->where('retrieved_at', '<=', now())->where('retrieved_at', '>=', now()->subDays(7))->count();
                    if ($closed >= 2) {
                        $this->incidents->record($connection, 'strategy_watchlist_quiet', 'info', 'watchlist');
                    } elseif (! $previous || $previous->membership_hash !== $revision->membership_hash) {
                        $this->incidents->resolve($connection, 'strategy_watchlist_quiet');
                    }
                }
                if (! $this->nominations($connection, CarbonImmutable::now())['fresh']) {
                    $this->incidents->record($connection, 'strategy_watchlist_expired', 'warning', 'watchlist');
                }

                return $attempt;
            });
        } finally {
            $lock->release();
        }
    }

    /** Independent entry nomination gate. Never call this to authorize or suppress position exits. */
    public function nominations(McpConnection $connection, CarbonImmutable $cutoff): array
    {
        $current = $connection->fresh();
        $authorized = $current && $current->status === 'connected' && $current->account_role === 'agentic_monitoring' &&
            $current->bound_account_id && $connection->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) &&
            $current->auth_generation === $connection->auth_generation && User::whereKey($current->user_id)->whereIn('email', config('mcp.operator_emails'))->exists();
        $setting = $connection->bound_account_id ? $this->setting($connection, $cutoff) : null;
        $revision = $setting ? WatchlistRevision::where('setting_id', $setting->id)->where('auth_generation', $connection->auth_generation)
            ->where('retrieved_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u'))->where('recorded_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u'))->latest('retrieved_at')->latest('id')->first() : null;
        $fresh = config('paper.watchlist_intake_enabled') && $authorized &&
            $revision && $revision->retrieved_at->gt($cutoff->subMinutes(30));
        $entryPause = $connection->bound_account_id ? app(ExternalOrders::class)->entryReason($connection) : 'account_unbound';
        $usable = $fresh ? array_filter($revision->payload['members'], fn ($m) => $m['skip_reason'] === null) : [];
        $symbols = $entryPause === null ? self::symbols($usable, 'nominate') : [];
        // Data-only members warm up collection; they never nominate and do not depend on the entry pause.
        $data = array_values(array_diff(self::symbols($usable, 'data_only'), self::symbols($usable, 'nominate')));

        return ['setting' => $setting, 'revision' => $revision, 'fresh' => (bool) $fresh, 'symbols' => $symbols, 'data_symbols' => $data,
            'entry_pause_reason' => $entryPause, 'execution_eligibility' => 'unverified'];
    }

    /** Members without a role predate multi-list settings and nominate. */
    public static function symbols(array $members, string $role): array
    {
        $symbols = array_values(array_unique(array_column(array_filter($members, fn ($m) => ($m['role'] ?? 'nominate') === $role), 'symbol')));
        sort($symbols);

        return $symbols;
    }

    public function tick(?int $account = null): int
    {
        if (! config('paper.watchlist_intake_enabled')) {
            return 0;
        }
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $count = 0;
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')
            ->when($account !== null, fn ($q) => $q->whereKey($account))->get() as $connection) {
            if (! $setting = $this->setting($connection)) {
                continue;
            }
            $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'strategy-watchlist:'.$setting->id.':'.$connection->auth_generation.':'.intdiv(now()->timestamp, 300))->toString();
            $this->collect($connection, $connection->user_id, $request);
            $count++;
        }

        return $count;
    }

    private function authorize(McpConnection $connection, int $actor, bool $lock = false): void
    {
        $query = McpConnection::whereKey($connection->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
        abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id && $connection->bound_account_id &&
            AccountScope::key($current) === AccountScope::key($connection) && $current->auth_generation === $connection->auth_generation, 409, 'Account authorization changed.');
    }
}
