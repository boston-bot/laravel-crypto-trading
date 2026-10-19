<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class CorporateActionRecord extends ImmutableRecord
{
    protected $table = 'mcp_paper_action_records';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'ledger_version' => 'integer', 'effective_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
