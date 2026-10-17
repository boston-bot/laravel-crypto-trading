<?php

namespace App\Models\Monitoring;

class PolicyVersion extends ImmutableRecord
{
    protected $table = 'mcp_policy_versions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'effective_at' => 'immutable_datetime'];
    }
}
