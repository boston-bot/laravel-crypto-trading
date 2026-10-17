<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;

class MarketSession extends Model
{
    protected $table = 'mcp_market_sessions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['opens_at' => 'immutable_datetime', 'closes_at' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime'];
    }
}
