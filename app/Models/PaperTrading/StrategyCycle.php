<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class StrategyCycle extends ImmutableRecord
{
    protected $table = 'mcp_strategy_cycles';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'cycle_at' => 'immutable_datetime', 'cutoff' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
