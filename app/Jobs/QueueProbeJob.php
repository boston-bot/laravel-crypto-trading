<?php

namespace App\Jobs;

use App\Models\Operations\ComponentStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Proves a worker is consuming its queue; it touches no business data. */
class QueueProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $probeQueue, string $connection, public ?string $probeId = null)
    {
        $this->onConnection($connection);
        $this->onQueue($probeQueue);
    }

    public function handle(): void
    {
        // Ignore an old job after a newer probe has been issued for this queue.
        DB::transaction(function (): void {
            $sent = ComponentStatus::where('component', 'probe:'.$this->probeQueue)->lockForUpdate()->first();
            if ($this->probeId !== null && ($sent?->detail['probe_id'] ?? null) === $this->probeId) {
                ComponentStatus::record('queue:'.$this->probeQueue, 'processed', ['probe_id' => $this->probeId]);
            }
        });
    }
}
