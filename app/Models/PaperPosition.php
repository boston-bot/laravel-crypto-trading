<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_account_id',
        'paper_session_id',
        'asset_id',
        'quantity',
        'avg_entry_price',
        'cost_basis',
        'market_price',
        'market_value',
        'unrealized_pnl',
        'realized_pnl',
        'opened_at',
        'closed_at',
        'updated_snapshot_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:12',
            'avg_entry_price' => 'decimal:8',
            'cost_basis' => 'decimal:8',
            'market_price' => 'decimal:8',
            'market_value' => 'decimal:8',
            'unrealized_pnl' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'updated_snapshot_at' => 'datetime',
            'metadata_json' => 'array',
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
}
