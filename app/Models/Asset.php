<?php

namespace App\Models;

use App\Enums\BrokerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker',
        'symbol',
        'asset_type',
        'is_tradable',
        'is_enabled',
        'min_order_notional',
        'price_precision',
        'quantity_precision',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'broker' => BrokerType::class,
            'is_tradable' => 'boolean',
            'is_enabled' => 'boolean',
            'min_order_notional' => 'decimal:8',
            'metadata_json' => 'array',
        ];
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

    public function marketCandles(): HasMany
    {
        return $this->hasMany(MarketCandle::class);
    }

    public function marketQuotes(): HasMany
    {
        return $this->hasMany(MarketQuote::class);
    }

    public function featureSnapshots(): HasMany
    {
        return $this->hasMany(AssetFeatureSnapshot::class);
    }

    public function executionQualitySnapshots(): HasMany
    {
        return $this->hasMany(ExecutionQualitySnapshot::class);
    }

    public function paperPositions(): HasMany
    {
        return $this->hasMany(PaperPosition::class);
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
