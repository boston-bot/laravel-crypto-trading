<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeAttribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'trade_decision_id',
        'broker_order_id',
        'paper_session_id',
        'asset_id',
        'expected_probability',
        'expected_expectancy',
        'realized_return_pct',
        'realized_pnl',
        'mae_pct',
        'mfe_pct',
        'hold_hours',
        'attributed_at',
        'attribution_json',
    ];

    protected function casts(): array
    {
        return [
            'expected_probability' => 'decimal:4',
            'expected_expectancy' => 'decimal:8',
            'realized_return_pct' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'mae_pct' => 'decimal:8',
            'mfe_pct' => 'decimal:8',
            'attributed_at' => 'datetime',
            'attribution_json' => 'array',
        ];
    }

    public function tradeDecision(): BelongsTo
    {
        return $this->belongsTo(TradeDecision::class);
    }

    public function brokerOrder(): BelongsTo
    {
        return $this->belongsTo(BrokerOrder::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
