<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_account_id',
        'asset_id',
        'quantity',
        'avg_cost',
        'market_value',
        'unrealized_pnl',
        'snapshot_at',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:12',
            'avg_cost' => 'decimal:8',
            'market_value' => 'decimal:8',
            'unrealized_pnl' => 'decimal:8',
            'snapshot_at' => 'datetime',
            'raw_json' => 'array',
        ];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

