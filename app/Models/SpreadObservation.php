<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpreadObservation extends Model
{
    protected $fillable = ['product_id', 'buy_venue', 'sell_venue', 'notional_usd', 'observed_at', 'buy_book_age_ms', 'sell_book_age_ms', 'receive_delta_ms', 'buy_vwap', 'sell_vwap', 'gross_edge_bps', 'net_edge_bps', 'buy_fee_bps', 'sell_fee_bps', 'impact_bps', 'rebalance_reserve_bps', 'safety_buffer_bps', 'classification', 'opportunity_lifetime_ms', 'delay_outcomes_json', 'rejection_reasons_json'];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'delay_outcomes_json' => 'array', 'rejection_reasons_json' => 'array'];
    }
}
