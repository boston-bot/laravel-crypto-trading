<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class ExecutionApproval extends ImmutableRecord
{
    protected $table = 'mcp_execution_approvals';

    protected $guarded = ['id'];

    protected $hidden = ['approval_id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['approval_id' => 'encrypted', 'is_edited' => 'boolean', 'expires_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
