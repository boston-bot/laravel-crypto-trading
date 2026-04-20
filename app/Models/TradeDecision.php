<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\TradeDecisionAction;
use App\Enums\TradingDecisionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_run_id',
        'broker_account_id',
        'asset_id',
        'decision',
        'side',
        'score',
        'confidence',
        'requested_quantity',
        'requested_notional',
        'market_context_json',
        'signal_context_json',
        'risk_context_json',
        'policy_result_json',
        'requires_human_approval',
        'approved_by',
        'approved_at',
        'status',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'decision' => TradeDecisionAction::class,
            'side' => OrderSide::class,
            'score' => 'decimal:4',
            'confidence' => 'decimal:4',
            'requested_quantity' => 'decimal:12',
            'requested_notional' => 'decimal:8',
            'market_context_json' => 'array',
            'signal_context_json' => 'array',
            'risk_context_json' => 'array',
            'policy_result_json' => 'array',
            'requires_human_approval' => 'boolean',
            'approved_at' => 'datetime',
            'status' => TradingDecisionStatus::class,
        ];
    }

    public function strategyRun(): BelongsTo
    {
        return $this->belongsTo(StrategyRun::class);
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function policyChecks(): HasMany
    {
        return $this->hasMany(PolicyCheck::class);
    }

    public function brokerOrder(): HasOne
    {
        return $this->hasOne(BrokerOrder::class);
    }

    public function paperOrderEvents(): HasMany
    {
        return $this->hasMany(PaperOrderEvent::class);
    }

    public function tradeAttributions(): HasMany
    {
        return $this->hasMany(TradeAttribution::class);
    }
}
