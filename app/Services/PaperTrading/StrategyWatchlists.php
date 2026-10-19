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
use Ramsey\Uuid\Uuid;

/** Intake only. Nominations never grant execution eligibility, change policy or interfere with exits. */
final class StrategyWatchlists
{
    public function __construct(private RobinhoodWatchlists $reader, private IncidentRecorder $incidents) {}

    public function setting(McpConnection $connection, ?CarbonImmutable $cutoff = null): ?WatchlistSetting
    {
        return WatchlistSetting::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))
            ->when($cutoff, fn ($q) => $q->where('recorded_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u')))->latest('version')->first();
    }

    public function configure(McpConnection $connection, int $actor, int $base, string $request, string $name): WatchlistSetting
    {
        $name = trim($name);
        Validator::make(['name' => $name, 'request' => $request, 'base' => $base], ['name' => ['required', 'string', 'max:128', 'not_in:On Watch,on watch'],
            'request' => ['required', 'uuid'], 'base' => ['integer', 'min:0']])->validate();
        abort_if(strcasecmp($name, 'On Watch') === 0, 422, 'Use a dedicated strategy list, separate from On Watch.');

        return DB::transaction(function () use ($connection, $actor, $base, $request, $name) {
            $this->authorize($connection, $actor, true);
            $query = WatchlistSetting::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection));
            if ($repeat = (clone $query)->where('request_id', $request)->first()) {
                abort_unless($repeat->payload === ['name' => $name], 409, 'Request already used for a different list.');

                return $repeat;
            }
            abort_unless(($this->setting($connection)?->version ?? 0) === $base, 409, 'Watchlist settings changed. Reload before saving.');

            return WatchlistSetting::create(['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection),
                'actor_id' => $actor, 'version' => $base + 1, 'request_id' => $request, 'payload' => ['name' => $name], 'recorded_at' => now()]);
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
            $read = null;
            $reason = null;
            try {
                $readConnection = clone $connection;
                $read = $this->reader->named($readConnection, $setting->payload['name']);
                if (count($read['items']) > 75) {
                    $reason = 'watchlist_over_cap';
                }
            } catch (ReadFailure $failure) {
                $reason = 'broker_'.$failure->reason->value;
            }

            // No database lock spans external calls. Changed credentials, binding or settings invalidate the result.
            return DB::transaction(function () use ($connection, $actor, $setting, $request, $read, $reason) {
                $this->authorize($connection, $actor, true);
                abort_unless(config('paper.watchlist_intake_enabled') && $this->setting($connection)?->id === $setting->id, 409, 'Watchlist settings changed during read.');
                if ($repeat = WatchlistRead::where('setting_id', $setting->id)->where('request_id', $request)->first()) {
                    return $repeat;
                }
                $previous = WatchlistRevision::where('setting_id', $setting->id)->latest('id')->first();
                if (! $reason && $previous && $previous->payload['list_id'] !== $read['watchlist']->fields['id']) {
                    $reason = 'watchlist_identity_changed';
                }
                $revision = null;
                if (! $reason) {
                    $at = CarbonImmutable::parse($read['retrieved_at']);
                    if ($at->isFuture() || $at->lt(CarbonImmutable::now()->subMinutes(5)) || ($previous && $at->lt($previous->retrieved_at))) {
                        $reason = 'watchlist_read_time_invalid';
                    } else {
                        $members = [];
                        foreach ($read['items'] as $item) {
                            $f = $item->fields;
                            $skip = $f['object_type'] !== 'instrument' ? 'non_equity_item' :
                                (! is_string($f['symbol'] ?? null) || ! preg_match('/^[A-Z][A-Z0-9.-]{0,15}$/D', $f['symbol']) ? 'symbol_unavailable' : null);
                            $members[] = ['id' => $f['id'], 'object_type' => $f['object_type'], 'symbol' => $f['symbol'] ?? null, 'skip_reason' => $skip];
                        }
                        usort($members, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                        $hash = hash('sha256', json_encode($members, JSON_THROW_ON_ERROR));
                        $revision = WatchlistRevision::create(['setting_id' => $setting->id, 'auth_generation' => $connection->auth_generation,
                            'request_id' => $request, 'membership_hash' => $hash, 'payload' => ['members' => $members,
                                'list_id' => $read['watchlist']->fields['id'], 'source' => $read['source'], 'source_as_of' => null,
                                'completeness' => $read['completeness'], 'consistency' => $read['consistency'], 'eligibility' => 'unverified'],
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
        $symbols = $fresh && $entryPause === null ? array_values(array_unique(array_column(array_filter($revision->payload['members'], fn ($m) => $m['skip_reason'] === null), 'symbol'))) : [];
        sort($symbols);

        return ['setting' => $setting, 'revision' => $revision, 'fresh' => (bool) $fresh, 'symbols' => $symbols, 'entry_pause_reason' => $entryPause, 'execution_eligibility' => 'unverified'];
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
