<?php

namespace App\Models\Monitoring;

class FlowEvent extends ImmutableRecord
{
    protected $table = 'mcp_flow_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'effective_at' => 'immutable_datetime'];
    }
}
