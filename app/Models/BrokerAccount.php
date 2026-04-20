<?php

namespace App\Models;

use App\Enums\BrokerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_credential_id',
        'broker',
        'external_account_id',
        'account_type',
        'currency',
        'buying_power',
        'cash_balance',
        'equity',
        'status',
        'snapshot_at',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'broker' => BrokerType::class,
            'buying_power' => 'decimal:8',
            'cash_balance' => 'decimal:8',
            'equity' => 'decimal:8',
            'snapshot_at' => 'datetime',
            'raw_json' => 'array',
        ];
    }

    public function brokerCredential(): BelongsTo
    {
        return $this->belongsTo(BrokerCredential::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function brokerOrders(): HasMany
    {
        return $this->hasMany(BrokerOrder::class);
    }

    public function tradeDecisions(): HasMany
    {
        return $this->hasMany(TradeDecision::class);
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(DailyPortfolioSnapshot::class);
    }

    public function paperPositions(): HasMany
    {
        return $this->hasMany(PaperPosition::class);
    }

    public function paperOrderEvents(): HasMany
    {
        return $this->hasMany(PaperOrderEvent::class);
    }

    public function paperPortfolioSnapshots(): HasMany
    {
        return $this->hasMany(PaperPortfolioSnapshot::class);
    }
}
