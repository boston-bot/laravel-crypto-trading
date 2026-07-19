<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StrategyExperimentCandidate extends Model
{
    protected $fillable = [
        'strategy_experiment_id', 'candidate_key', 'family', 'search_order',
        'search_budget', 'status', 'specification_json', 'content_hash',
    ];

    protected function casts(): array
    {
        return ['specification_json' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Candidate specifications are immutable.'));
        static::deleting(fn () => throw new LogicException('Candidate specifications are append-only.'));
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(StrategyExperiment::class, 'strategy_experiment_id');
    }

    public function strategyVersions(): HasMany
    {
        return $this->hasMany(StrategyVersion::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }
}
