<?php

namespace App\Models\Monitoring;

class ThesisVersion extends ImmutableRecord
{
    protected $table = 'mcp_thesis_versions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'effective_at' => 'immutable_datetime'];
    }
}
