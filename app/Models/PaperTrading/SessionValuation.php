<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class SessionValuation extends ImmutableRecord
{
    protected $table = 'mcp_paper_session_valuations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['equity' => 'decimal:18', 'payload' => 'encrypted:array', 'cutoff' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
