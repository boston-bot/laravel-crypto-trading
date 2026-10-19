<?php

namespace App\Console\Commands;

use App\Services\PaperTrading\MarketInputCollector;
use Illuminate\Console\Command;

class McpPaperCollectCommand extends Command
{
    protected $signature = 'mcp:paper-collect {--once} {--sync : Collect in the foreground} {--account= : Operator-owned local connection ID} {--limit=5 : Maximum symbols, 1 to 5} {--scheduled : Respect regular-session/hourly observation cadence}';

    protected $description = 'Collect bounded read-only market observations from the applied allowlist; no AI or orders';

    public function handle(MarketInputCollector $collector): int
    {
        if (! $this->option('once') || ! ctype_digit((string) $this->option('limit')) || (int) $this->option('limit') < 1 || (int) $this->option('limit') > 5 ||
            ($this->option('account') !== null && ! ctype_digit((string) $this->option('account')))) {
            $this->error('Use --once, --limit=1..5 and an optional numeric local account ID.');

            return self::FAILURE;
        }
        $count = $collector->tick((bool) $this->option('sync'), $this->option('account') === null ? null : (int) $this->option('account'), (int) $this->option('limit'), (bool) $this->option('scheduled'));
        $this->line('Market observations selected: '.$count);

        return self::SUCCESS;
    }
}
