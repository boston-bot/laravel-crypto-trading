<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderBookSummary extends Model
{
    protected $fillable = ['venue', 'product_id', 'bucket_time', 'event_time', 'received_at', 'sequence', 'best_bid', 'best_ask', 'spread_bps', 'book_age_ms', 'is_valid', 'invalid_reason', 'depth_json'];

    protected function casts(): array
    {
        return ['bucket_time' => 'immutable_datetime', 'event_time' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'is_valid' => 'boolean', 'depth_json' => 'array'];
    }
}
