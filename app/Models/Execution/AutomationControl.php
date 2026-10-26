<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class AutomationControl extends ImmutableRecord
{
    protected $table = 'mcp_execution_automation';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'recorded_at' => 'immutable_datetime'];
    }
}
