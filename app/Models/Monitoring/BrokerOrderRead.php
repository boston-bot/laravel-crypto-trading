<?php

namespace App\Models\Monitoring;

class BrokerOrderRead extends ImmutableRecord
{
    protected $table = 'mcp_broker_order_reads';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'complete' => 'boolean', 'retrieved_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
