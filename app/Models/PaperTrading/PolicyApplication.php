<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class PolicyApplication extends ImmutableRecord
{
    protected $table = 'mcp_paper_policy_applications';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'revision' => 'integer', 'applied_at' => 'immutable_datetime'];
    }
}
