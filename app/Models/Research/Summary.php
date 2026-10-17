<?php

namespace App\Models\Research;

use App\Models\Monitoring\ImmutableRecord;

class Summary extends ImmutableRecord
{
    protected $table = 'mcp_summaries';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'generated_at' => 'immutable_datetime'];
    }
}
