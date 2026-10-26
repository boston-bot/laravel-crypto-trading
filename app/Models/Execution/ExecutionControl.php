<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class ExecutionControl extends ImmutableRecord
{
    protected $table = 'mcp_execution_controls';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['buy_paused' => 'boolean', 'emergency_disabled' => 'boolean', 'recorded_at' => 'immutable_datetime'];
    }
}
