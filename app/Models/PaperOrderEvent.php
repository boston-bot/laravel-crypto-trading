<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperOrderEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_order_id',
        'trade_decision_id',
        'broker_account_id',
        'paper_session_id',
        'asset_id',
        'event_type',
        'status',
        'side',
        'event_time',
        'quantity',
        'notional',
        'reference_price',
        'fill_price',
        'slippage_bps',
        'fill_id',
        'fee',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'side' => OrderSide::class,
            'event_time' => 'datetime',
            'quantity' => 'decimal:12',
            'notional' => 'decimal:8',
            'reference_price' => 'decimal:8',
            'fill_price' => 'decimal:8',
            'slippage_bps' => 'decimal:4',
            'fee' => 'decimal:8',
            'payload_json' => 'array',
        ];
    }

    public function brokerOrder(): BelongsTo
    {
        return $this->belongsTo(BrokerOrder::class);
    }

    public function tradeDecision(): BelongsTo
    {
        return $this->belongsTo(TradeDecision::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function paperSession(): BelongsTo
    {
        return $this->belongsTo(PaperSession::class);
    }
}
