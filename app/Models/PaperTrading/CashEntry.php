<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class CashEntry extends ImmutableRecord
{
    protected $table = 'mcp_paper_cash_entries';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:18', 'balance_after' => 'decimal:18', 'ledger_version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
