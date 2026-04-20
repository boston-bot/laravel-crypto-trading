<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketCandle extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'symbol',
        'timeframe',
        'candle_open_time',
        'candle_close_time',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'turnover_usd',
        'source',
        'ingested_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'candle_open_time' => 'datetime',
            'candle_close_time' => 'datetime',
            'open' => 'decimal:8',
            'high' => 'decimal:8',
            'low' => 'decimal:8',
            'close' => 'decimal:8',
            'volume' => 'decimal:12',
            'turnover_usd' => 'decimal:8',
            'ingested_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
