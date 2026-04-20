<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'snapshot_time',
        'bid_price',
        'ask_price',
        'mid_price',
        'last_price',
        'spread_bps',
        'liquidity_score',
        'slippage_bps_estimate',
        'source',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_time' => 'datetime',
            'bid_price' => 'decimal:8',
            'ask_price' => 'decimal:8',
            'mid_price' => 'decimal:8',
            'last_price' => 'decimal:8',
            'spread_bps' => 'decimal:4',
            'liquidity_score' => 'decimal:4',
            'slippage_bps_estimate' => 'decimal:4',
            'raw_json' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
