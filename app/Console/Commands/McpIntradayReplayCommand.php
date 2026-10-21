<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\HistoricalQuoteReplays;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class McpIntradayReplayCommand extends Command
{
    protected $signature = 'mcp:intraday-replay {--account= : Local operator-owned connection ID} {--date= : Completed session YYYY-MM-DD}
        {--session=* : Two to five consecutive dates instead of --date; requires --minutes=390}
        {--symbol=* : One or two explicit research symbols} {--minutes=60 : First 20-390 minutes, multiple of five} {--equity=500 : Virtual starting cash}
        {--request= : Stable UUID for this research request}';

    protected $description = 'Archive a historical SIP quote scenario using the existing rules; no forward paper fills, account mutation or scheduler';

    public function handle(HistoricalQuoteReplays $replays): int
    {
        if (! ctype_digit((string) $this->option('account')) || ! ctype_digit((string) $this->option('minutes'))) {
            $this->error('Use a numeric --account and --minutes.');

            return self::FAILURE;
        }
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->first();
        if (! $connection) {
            $this->error('Select an operator-owned connection.');

            return self::FAILURE;
        }
        $run = $replays->run($connection, $connection->user_id, $this->option('request') ?? (string) Str::uuid(), (string) $this->option('date'),
            $this->option('symbol'), (string) $this->option('equity'), (int) $this->option('minutes'), $this->option('session') ?: null);
        $result = $run->payload['result'];
        $this->line(json_encode(['replay_id' => $run->id, 'version' => $run->replay_version, 'status' => $run->status, 'reason' => $run->reason,
            'label' => $run->payload['label'], 'coverage' => $result['coverage'] ?? null, 'orders' => count($result['orders'] ?? []), 'fills' => count($result['fills'] ?? []),
            'final_equity' => $result['final_equity'] ?? null, 'stats' => $result['stats'] ?? null, 'limitations' => $result['limitations'] ?? []], JSON_THROW_ON_ERROR));

        return $run->status === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
