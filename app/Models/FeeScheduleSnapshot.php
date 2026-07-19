<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeScheduleSnapshot extends Model
{
    protected $fillable = ['venue', 'account_scope', 'maker_fee_bps', 'taker_fee_bps', 'volume_tier_usd', 'effective_at', 'expires_at', 'source_url', 'content_hash', 'raw_json'];

    protected function casts(): array
    {
        return ['maker_fee_bps' => 'float', 'taker_fee_bps' => 'float', 'effective_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'raw_json' => 'array'];
    }
}
