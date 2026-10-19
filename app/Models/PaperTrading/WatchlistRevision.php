<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class WatchlistRevision extends ImmutableRecord
{
    protected $table = 'mcp_strategy_watchlist_revisions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'retrieved_at' => 'immutable_datetime', 'changed_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
