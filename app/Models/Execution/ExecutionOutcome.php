<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class ExecutionOutcome extends ImmutableRecord
{
    protected $table = 'mcp_execution_outcomes';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['filled_quantity' => 'decimal:18', 'average_price' => 'decimal:18', 'fees' => 'decimal:18', 'source_as_of' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
