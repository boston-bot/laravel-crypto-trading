<?php

namespace App\Models\Execution;

use App\Models\Monitoring\ImmutableRecord;

class BrokerOrderBinding extends ImmutableRecord
{
    protected $table = 'mcp_execution_broker_orders';

    protected $guarded = ['id'];

    protected $hidden = ['broker_order_id', 'submission_receipt'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['broker_order_id' => 'encrypted', 'submission_receipt' => 'encrypted:array', 'recorded_at' => 'immutable_datetime'];
    }
}
