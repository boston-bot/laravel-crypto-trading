<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class OrderIntent extends ImmutableRecord
{
    protected $table = 'mcp_execution_intents';

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'recorded_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
