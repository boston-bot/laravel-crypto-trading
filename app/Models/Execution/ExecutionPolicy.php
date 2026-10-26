<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class ExecutionPolicy extends ImmutableRecord
{
    protected $table = 'mcp_execution_policies';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'applied_at' => 'immutable_datetime'];
    }
}
