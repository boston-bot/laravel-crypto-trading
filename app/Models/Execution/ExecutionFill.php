<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class ExecutionFill extends ImmutableRecord
{
    protected $table = 'mcp_execution_fills';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:18', 'price' => 'decimal:18', 'fees' => 'decimal:18', 'executed_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
