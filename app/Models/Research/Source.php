<?php

namespace App\Models\Research;

use App\Models\Monitoring\ImmutableRecord;

/** Untrusted evidence. Its payload is data supplied to research, never instructions or policy. */
class Source extends ImmutableRecord
{
    protected $table = 'mcp_research_sources';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'source_as_of' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime'];
    }
}
