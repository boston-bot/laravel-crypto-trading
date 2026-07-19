<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = ['paper_session_id', 'asset_id', 'broker_order_id', 'reverses_entry_id', 'entry_type', 'fill_id', 'cash_delta', 'reserved_cash_delta', 'quantity_delta', 'unit_price', 'fee', 'occurred_at', 'context_json'];

    protected function casts(): array
    {
        return ['cash_delta' => 'decimal:8', 'reserved_cash_delta' => 'decimal:8', 'quantity_delta' => 'decimal:12', 'unit_price' => 'decimal:8', 'fee' => 'decimal:8', 'occurred_at' => 'datetime', 'context_json' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PaperSession::class, 'paper_session_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function brokerOrder(): BelongsTo
    {
        return $this->belongsTo(BrokerOrder::class);
    }
}
