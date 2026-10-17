<?php

namespace App\Models\Monitoring;

class SleeveAllocation extends ImmutableRecord
{
    protected $table = 'mcp_sleeve_allocations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'effective_at' => 'immutable_datetime'];
    }
}
