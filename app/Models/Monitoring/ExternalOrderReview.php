<?php

namespace App\Models\Monitoring;

class ExternalOrderReview extends ImmutableRecord
{
    protected $table = 'mcp_external_order_reviews';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'reviewed_at' => 'immutable_datetime'];
    }
}
