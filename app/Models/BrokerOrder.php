<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BrokerOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_account_id',
        'paper_session_id',
        'asset_id',
        'trade_decision_id',
        'external_order_id',
        'client_order_id',
        'side',
        'order_type',
        'time_in_force',
        'requested_quantity',
        'requested_notional',
        'requested_price',
        'status',
        'filled_quantity',
        'filled_notional',
        'avg_fill_price',
        'submitted_at',
        'filled_at',
        'fee_amount',
        'raw_request_json',
        'raw_response_json',
    ];

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'status' => OrderStatus::class,
            'requested_quantity' => 'decimal:12',
            'requested_notional' => 'decimal:8',
            'requested_price' => 'decimal:8',
            'filled_quantity' => 'decimal:12',
            'filled_notional' => 'decimal:8',
            'avg_fill_price' => 'decimal:8',
            'submitted_at' => 'datetime',
            'filled_at' => 'datetime',
            'fee_amount' => 'decimal:8',
            'raw_request_json' => 'array',
            'raw_response_json' => 'array',
        ];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
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

    public function paperOrderEvents(): HasMany
    {
        return $this->hasMany(PaperOrderEvent::class);
    }

    public function tradeAttributions(): HasMany
    {
        return $this->hasMany(TradeAttribution::class);
    }

    public function paperReservation(): HasOne
    {
        return $this->hasOne(PaperOrderReservation::class);
    }
}
