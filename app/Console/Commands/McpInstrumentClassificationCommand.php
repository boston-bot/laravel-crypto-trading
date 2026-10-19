<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\PaperInstrumentClassifications;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class McpInstrumentClassificationCommand extends Command
{
    protected $signature = 'mcp:instrument-classification {--account= : Local operator-owned connection ID} {--symbol=* : Applied allowlisted symbols; default all}
        {--scheduled : Idempotent daily refresh for every bound operator connection}';

    protected $description = 'Record public SEC filing classification evidence for applied symbols; no broker call, policy change or order';

    public function handle(PaperInstrumentClassifications $classifications): int
    {
        if ($this->option('scheduled')) {
            $this->line('Recorded classifications: '.$classifications->tick());

            return self::SUCCESS;
        }
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection) {
            $this->error('Select an operator-owned bound connection.');

            return self::FAILURE;
        }
        if (! config('sec_edgar.enabled')) {
            $this->error('SEC classification reads are disabled.');

            return self::FAILURE;
        }
        $rows = $classifications->collect($connection, $connection->user_id, $this->option('symbol'), (string) Str::uuid());
        foreach ($rows as $row) {
            $p = $row->payload;
            $this->line(json_encode(['symbol' => $row->symbol, 'status' => $row->status, 'basis' => $row->basis, 'reason' => $row->reason,
                'multiple_or_inverse' => $p['multiple_or_inverse'], 'report_period_end' => $p['fund']['report_period_end'] ?? null,
                'accession' => $p['fund']['accession'] ?? null, 'requests' => $p['requests'], 'retrieved_at' => $p['retrieved_at']], JSON_THROW_ON_ERROR));
        }

        return $rows->every(fn ($row) => $row->status === 'complete') ? self::SUCCESS : self::FAILURE;
    }
}
