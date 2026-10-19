<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class WatchlistRead extends ImmutableRecord
{
    protected $table = 'mcp_strategy_watchlist_reads';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
