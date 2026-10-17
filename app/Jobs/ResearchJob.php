<?php

namespace App\Jobs;

use App\Models\McpConnection;
use App\Models\Research\Event;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\Research\ResearchRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResearchJob implements ShouldQueue
{
    use Queueable;

    // Model calls cost money: no generic queue retry. A failed run needs a new event or an explicit operator request.
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $eventId, public string $scope)
    {
        $this->onConnection('mcp_research');
        $this->onQueue('research');
    }

    public function handle(ResearchRunner $runner): void
    {
        $event = Event::find($this->eventId);
        $connection = $event ? McpConnection::find($event->mcp_connection_id) : null;
        // A temporarily disconnected broker is left to the runner, which keeps the event pending with its reason.
        if (! $event || ! $connection || ! $connection->bound_account_id || $event->scope !== $this->scope ||
            AccountScope::key($connection) !== $this->scope || ! User::whereKey($connection->user_id)->whereIn('email', config('mcp.operator_emails'))->exists()) {
            return;
        }
        $runner->process($this->eventId);
    }
}
