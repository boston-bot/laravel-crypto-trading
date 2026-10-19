<?php

namespace App\Models\Monitoring;

class ExternalOrder extends ImmutableRecord
{
    protected $table = 'mcp_external_orders';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'retrieved_at' => 'immutable_datetime', 'detected_at' => 'immutable_datetime'];
    }
}
