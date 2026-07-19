<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketCandleRevision extends Model
{
    protected $fillable = ['market_candle_id', 'revision', 'content_hash', 'first_seen_at', 'available_at', 'values_json', 'change_reason'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'values_json' => 'array'];
    }
}
