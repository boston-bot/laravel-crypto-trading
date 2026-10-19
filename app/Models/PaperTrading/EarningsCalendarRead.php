<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class EarningsCalendarRead extends ImmutableRecord
{
    protected $table = 'mcp_earnings_calendar_reads';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'start_date' => 'immutable_date', 'retrieved_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
