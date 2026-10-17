<?php

namespace App\Console\Commands;

use App\Services\Operations\Notifications;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class McpNotifyCommand extends Command
{
    protected $signature = 'mcp:notify {--once : Escalate and dispatch pending alerts} {--test : Send a delivery test} {--digest : Queue and send today\'s digest}';

    protected $description = 'Dispatch alert intents to the configured destination; delivered only on provider acknowledgement';

    public function handle(Notifications $notifications): int
    {
        if (! $this->option('once') && ! $this->option('test') && ! $this->option('digest')) {
            $this->error('Use --once, --test or --digest.');

            return self::FAILURE;
        }
        if ($this->option('test')) {
            $notifications->queueTest();
        }
        if ($this->option('digest')) {
            $notifications->queueDigest(CarbonImmutable::now('America/New_York')->toDateString());
        }
        $escalated = $notifications->escalate();
        $result = $notifications->dispatch();
        if ($result === null) {
            $this->warn('No alert destination is configured; alert intents stay pending.');

            return $this->option('test') ? self::FAILURE : self::SUCCESS;
        }
        $this->line(json_encode(['escalated' => $escalated] + $result, JSON_THROW_ON_ERROR));

        return $this->option('test') && $result['delivered'] < 1 ? self::FAILURE : self::SUCCESS;
    }
}
