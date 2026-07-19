<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'severity',
        'event_type',
        'asset_id',
        'broker_order_id',
        'trade_decision_id',
        'message',
        'context_json',
        'triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'triggered_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function brokerOrder(): BelongsTo
    {
        return $this->belongsTo(BrokerOrder::class);
    }

    public function tradeDecision(): BelongsTo
    {
        return $this->belongsTo(TradeDecision::class);
    }
}
