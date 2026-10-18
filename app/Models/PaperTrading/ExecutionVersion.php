<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class ExecutionVersion extends ImmutableRecord
{
    protected $table = 'mcp_paper_execution_versions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
