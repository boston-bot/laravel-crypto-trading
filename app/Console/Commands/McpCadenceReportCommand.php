<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\Monitoring\MarketSession;
use App\Models\User;
use App\Services\PaperTrading\CadenceReport;
use Illuminate\Console\Command;

class McpCadenceReportCommand extends Command
{
    protected $signature = 'mcp:cadence-report {--account= : Local operator-owned connection ID} {--date= : New York session date, YYYY-MM-DD; default the latest regular session}
        {--quote-age=20 : Quote age limit in seconds to measure against}';

    protected $description = 'Measure one regular session\'s collection, quote-age and strategy-cycle cadence from stored records; read-only';

    public function handle(CadenceReport $report): int
    {
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection) {
            $this->error('Select an operator-owned bound connection.');

            return self::FAILURE;
        }
        $date = $this->option('date');
        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            $this->error('Use --date=YYYY-MM-DD.');

            return self::FAILURE;
        }
        $date ??= MarketSession::where('exchange', 'XNYS')->whereIn('status', ['regular', 'early_close'])->where('opens_at', '<=', now())
            ->orderByDesc('session_date')->value('session_date');
        $result = $report->session($connection, (string) $date, max(1, (int) $this->option('quote-age')));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['status'] === 'measured' ? self::SUCCESS : self::FAILURE;
    }
}
