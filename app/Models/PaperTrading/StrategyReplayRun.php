<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class StrategyReplayRun extends ImmutableRecord
{
    protected $table = 'mcp_strategy_replays';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'recorded_at' => 'immutable_datetime'];
    }
}
