<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPortfolioSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_account_id',
        'equity',
        'cash',
        'invested_value',
        'realized_pnl',
        'unrealized_pnl',
        'drawdown_pct',
        'snapshot_date',
    ];

    protected function casts(): array
    {
        return [
            'equity' => 'decimal:8',
            'cash' => 'decimal:8',
            'invested_value' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'unrealized_pnl' => 'decimal:8',
            'drawdown_pct' => 'decimal:4',
            'snapshot_date' => 'date',
        ];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
