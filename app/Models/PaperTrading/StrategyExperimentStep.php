<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class StrategyExperimentStep extends ImmutableRecord
{
    protected $table = 'mcp_strategy_experiment_steps';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'recorded_at' => 'immutable_datetime'];
    }
}
