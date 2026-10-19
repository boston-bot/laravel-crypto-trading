<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class EligibilityAssessment extends ImmutableRecord
{
    protected $table = 'mcp_eligibility_assessments';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'cutoff' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
