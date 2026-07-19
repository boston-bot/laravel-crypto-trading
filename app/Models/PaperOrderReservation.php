<?php

namespace App\Models;

use App\Enums\OrderSide;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperOrderReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'paper_session_id', 'asset_id', 'trade_decision_id', 'broker_order_id',
        'idempotency_key', 'intent_hash', 'side', 'amount', 'reserved_quantity',
        'status', 'reserved_at', 'filled_at', 'released_at', 'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'amount' => 'decimal:8',
            'reserved_quantity' => 'decimal:12',
            'reserved_at' => 'datetime',
            'filled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function paperSession(): BelongsTo
    {
        return $this->belongsTo(PaperSession::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tradeDecision(): BelongsTo
    {
        return $this->belongsTo(TradeDecision::class);
    }

    public function brokerOrder(): BelongsTo
    {
        return $this->belongsTo(BrokerOrder::class);
    }
}
