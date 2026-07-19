<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PaperSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker_account_id', 'strategy_version_id', 'universe_version_id', 'funding_mode',
        'status', 'currency', 'opening_cash', 'reserved_cash', 'fee_scenario',
        'slippage_scenario', 'valuation_at', 'source_account_snapshot_id', 'started_at',
        'ended_at', 'metadata_json', 'evidence_eligible', 'evidence_status',
        'execution_policy_hash', 'evidence_checked_at', 'evidence_failure_reason',
        'entries_suppressed_at', 'entries_suppression_reason', 'evidence_summary_json',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:8', 'reserved_cash' => 'decimal:8',
            'valuation_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime',
            'metadata_json' => 'array', 'evidence_eligible' => 'boolean',
            'evidence_checked_at' => 'datetime', 'entries_suppressed_at' => 'datetime',
            'evidence_summary_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $session): void {
            if ((bool) $session->getRawOriginal('evidence_eligible')
                && $session->isDirty(['strategy_version_id', 'universe_version_id', 'execution_policy_hash', 'evidence_eligible'])) {
                throw new LogicException('Evidence-eligible paper session pins are immutable.');
            }
        });
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

    public function reservations(): HasMany
    {
        return $this->hasMany(PaperOrderReservation::class);
    }
}
