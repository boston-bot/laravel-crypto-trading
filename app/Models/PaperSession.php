<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaperSession extends Model
{
    use HasFactory;

    protected $fillable = ['broker_account_id', 'strategy_version_id', 'universe_version_id', 'funding_mode', 'status', 'currency', 'opening_cash', 'reserved_cash', 'fee_scenario', 'slippage_scenario', 'valuation_at', 'source_account_snapshot_id', 'started_at', 'ended_at', 'metadata_json'];

    protected function casts(): array
    {
        return ['opening_cash' => 'decimal:8', 'reserved_cash' => 'decimal:8', 'valuation_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'metadata_json' => 'array'];
    }

    public function brokerAccount(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class);
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(StrategyVersion::class);
    }

    public function universeVersion(): BelongsTo
    {
        return $this->belongsTo(UniverseVersion::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PaperLedgerEntry::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(PaperPosition::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PaperPortfolioSnapshot::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(BrokerOrder::class);
    }
}
