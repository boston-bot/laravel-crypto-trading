<?php

namespace App\Services\Operations;

use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use App\Models\Monitoring\Snapshot;
use App\Models\Operations\ComponentStatus;
use App\Models\Operations\NotificationDelivery;
use App\Models\Research\Event;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Urgent incidents are pushed immediately and repeated while unacknowledged; everything else waits for the daily
 * digest. Messages carry incident keys and counts only, never account identifiers, balances or symbols.
 */
final class Notifications
{
    public function __construct(private NtfyNotifier $ntfy) {}

    public static function immediate(Incident $incident): bool
    {
        return $incident->severity === 'critical' || in_array($incident->incident_key, config('operations.immediate_keys'), true);
    }

    public function escalate(): int
    {
        $due = now()->subMinutes((int) config('operations.escalation_minutes'));
        $count = 0;
        foreach (Incident::where('state', 'open')->whereNull('resolved_at')->whereNotNull('last_notified_at')->where('last_notified_at', '<=', $due)->get() as $incident) {
            if (self::immediate($incident)) {
                $count += $this->queue('escalation:'.$incident->id.':'.$incident->notified_count, ['incident_id' => $incident->id, 'escalation' => true]);
            }
        }

        return $count;
    }

    public function queueDigest(string $date): int
    {
        return $this->queue('digest:'.$date, ['digest' => $date]);
    }

    public function queueTest(): int
    {
        return $this->queue('test:'.Str::uuid(), ['test' => true]);
    }

    /** @return array<string, int>|null null when no destination is configured; intents then stay pending */
    public function dispatch(int $limit = 20): ?array
    {
        if (! $this->ntfy->configured()) {
            return null;
        }
        $counts = ['delivered' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0];
        foreach (Outbox::where('kind', 'notification')->whereNull('dispatched_at')->orderBy('created_at')->limit($limit)->pluck('id') as $id) {
            if ($status = $this->deliver($id)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    private function deliver(string $id): ?string
    {
        $claim = DB::transaction(function () use ($id) {
            $event = Outbox::whereKey($id)->lockForUpdate()->first();
            if (! $event || $event->dispatched_at || $event->attempts >= (int) config('operations.delivery_max_attempts')) {
                return null;
            }
            $last = NotificationDelivery::where('outbox_id', $id)->latest('id')->first();
            // A crashed send expires after two minutes; failed sends back off exponentially, up to 30 minutes.
            if ($last && (($last->status === 'sending' && $last->attempted_at->gt(now()->subMinutes(2))) ||
                ($last->status === 'failed' && $last->attempted_at->gt(now()->subMinutes(min(30, 2 ** max(0, $event->attempts - 1))))))) {
                return null;
            }
            if ($last?->status === 'sending') {
                $last->update(['status' => 'unknown']); // the outcome of a crashed send cannot be known; it may be repeated
            }
            $payload = $event->payload;
            $incident = isset($payload['incident_id']) ? Incident::find($payload['incident_id']) : null;
            $kind = match (true) {
                isset($payload['digest']) => 'digest',
                isset($payload['test']) => 'test',
                ($payload['escalation'] ?? false) === true => 'escalation',
                default => 'incident',
            };
            if (in_array($kind, ['incident', 'escalation'], true) && (! $incident || ! self::immediate($incident) || $incident->state !== 'open' || $incident->resolved_at)) {
                $status = $incident && ! self::immediate($incident) ? 'deferred' : 'skipped';
                NotificationDelivery::create(['outbox_id' => $id, 'incident_id' => $incident?->id, 'kind' => $kind, 'channel' => 'ntfy', 'status' => $status, 'attempted_at' => now()]);
                $event->update(['dispatched_at' => now()]);

                return $status;
            }
            $event->update(['attempts' => $event->attempts + 1]);

            return [$event, $incident, $kind, NotificationDelivery::create(['outbox_id' => $id, 'incident_id' => $incident?->id, 'kind' => $kind,
                'channel' => 'ntfy', 'status' => 'sending', 'attempted_at' => now()])];
        });
        if (! is_array($claim)) {
            return $claim;
        }
        [$event, $incident, $kind, $delivery] = $claim;
        [$title, $message, $priority, $tags] = $this->compose($kind, $incident, $event->payload);
        try {
            $messageId = $this->ntfy->send($title, $message, $priority, $tags);
        } catch (NotificationFailure $failure) {
            $delivery->update(['status' => 'failed', 'error_code' => substr($failure->getMessage(), 0, 64)]);

            return 'failed';
        }
        DB::transaction(function () use ($event, $incident, $delivery, $messageId): void {
            $delivery->update(['status' => 'delivered', 'provider_message_id' => substr($messageId, 0, 128)]);
            Outbox::whereKey($event->id)->update(['dispatched_at' => now()]);
            if ($incident) {
                Incident::whereKey($incident->id)->update(['last_notified_at' => now(), 'notified_count' => DB::raw('notified_count + 1')]);
            }
        });

        return 'delivered';
    }

    /** @return array{0: string, 1: string, 2: int, 3: list<string>} */
    private function compose(string $kind, ?Incident $incident, array $payload): array
    {
        if ($kind === 'test') {
            return ['Investing Ops: test', 'Delivery check from the investing operations app. No action needed.', 3, ['white_check_mark']];
        }
        if ($kind === 'digest') {
            return ['Investing Ops: daily digest '.$payload['digest'], $this->digest(), 3, ['clipboard']];
        }
        $critical = $incident->severity === 'critical';
        $prefix = $kind === 'escalation' ? 'Still unacknowledged: ' : ($incident->reopen_count ? 'Recurred: ' : '');

        return ['Investing Ops: '.$prefix.$incident->incident_key, sprintf('%s incident "%s" (%s). First seen %s, last seen %s (New York). Review and acknowledge it in the dashboard.',
            ucfirst($incident->severity), $incident->incident_key, $incident->source, $incident->first_seen_at->setTimezone('America/New_York')->format('M j H:i'),
            $incident->last_seen_at->setTimezone('America/New_York')->format('M j H:i')), $critical ? 5 : 4, [$critical ? 'rotating_light' : 'warning']];
    }

    private function digest(): string
    {
        $open = Incident::whereNull('resolved_at')->where('state', 'open')->get();
        $snapshot = Snapshot::where('status', 'complete')->latest('published_at')->first();
        $backup = ComponentStatus::where('component', 'backup')->first();
        $failed = NotificationDelivery::where('status', 'failed')->where('attempted_at', '>=', now()->subDay())->count();
        $lines = [
            sprintf('Open incidents: %d (%d critical). Acknowledged or resolved items are not listed.', $open->count(), $open->where('severity', 'critical')->count()),
            'Top open: '.($open->pluck('incident_key')->take(6)->implode(', ') ?: 'none'),
            'Monitoring: '.(config('mcp.monitoring_enabled') ? 'enabled' : 'disabled').'; latest complete snapshot '.($snapshot ? $snapshot->published_at->diffForHumans() : 'none'),
            'Research events pending: '.Event::where('status', 'pending')->count(),
            'Last backup: '.($backup ? $backup->status.', '.$backup->observed_at->diffForHumans() : 'never'),
            'Failed alert deliveries (24h): '.$failed,
        ];

        return implode("\n", $lines);
    }

    private function queue(string $key, array $payload): int
    {
        try {
            return DB::transaction(function () use ($key, $payload): int {
                if (Outbox::where('event_key', $key)->exists()) {
                    return 0;
                }
                $event = new Outbox;
                $event->forceFill(['id' => (string) Str::uuid(), 'event_key' => $key, 'kind' => 'notification', 'payload' => $payload])->save();

                return 1;
            });
        } catch (UniqueConstraintViolationException) {
            return 0;
        }
    }
}
