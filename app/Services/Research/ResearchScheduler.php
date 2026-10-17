<?php

namespace App\Services\Research;

use App\Jobs\ResearchJob;
use App\Models\McpConnection;
use App\Models\Research\Event;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use Illuminate\Support\Facades\DB;

final class ResearchScheduler
{
    public function __construct(private ResearchEvents $events, private ResearchRunner $runner) {}

    /** One bounded detection/dispatch cycle. Material events go first; nothing runs while research is disabled. */
    public function tick(bool $foreground = false, int $limit = 10): int
    {
        if (! config('research.enabled')) {
            return 0;
        }
        $this->runner->sweep();
        $count = 0;
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        foreach (McpConnection::whereIn('user_id', $operators)->where('status', 'connected')->whereNotNull('bound_account_id')->get() as $connection) {
            $this->events->detect($connection);
            if (ResearchReadiness::reason() !== null) {
                continue; // Events stay recorded; no job is queued that could not reserve budget or call a model.
            }
            $scope = AccountScope::key($connection);
            // Processing is claimed under row locks, so redispatching a possibly lost job or running queued work in the foreground is safe.
            $ids = Event::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'pending')
                ->when(! $foreground, fn ($q) => $q->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(15))))
                ->orderByRaw("case when priority = 'material' then 0 else 1 end")->orderBy('first_seen_at')->limit($limit)->pluck('id');
            foreach ($ids as $id) {
                if ($foreground) {
                    $this->runner->process($id);
                    $count++;

                    continue;
                }
                $count += DB::transaction(function () use ($id, $scope): int {
                    $event = Event::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($event->status !== 'pending' || $event->dispatched_at?->gte(now()->subMinutes(15))) {
                        return 0;
                    }
                    ResearchJob::dispatch($id, $scope);
                    $event->update(['dispatched_at' => now()]);

                    return 1;
                });
            }
        }

        return $count;
    }
}
