<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class OrderHandoff extends ImmutableRecord
{
    protected $table = 'mcp_paper_order_handoffs';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }
}
