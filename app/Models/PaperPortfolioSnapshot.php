<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperPortfolioSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_account_id',
        'snapshot_time',
        'equity',
        'cash',
        'invested_value',
        'realized_pnl',
        'unrealized_pnl',
        'gross_exposure_pct',
        'heat_score',
        'drawdown_pct',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_time' => 'datetime',
            'equity' => 'decimal:8',
            'cash' => 'decimal:8',
            'invested_value' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'unrealized_pnl' => 'decimal:8',
            'gross_exposure_pct' => 'decimal:4',
            'heat_score' => 'decimal:4',
            'drawdown_pct' => 'decimal:4',
            'metadata_json' => 'array',
        ];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }
}
