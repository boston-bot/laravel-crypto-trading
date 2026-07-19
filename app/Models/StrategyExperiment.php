<?php

namespace App\Models;

use App\Enums\ExperimentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StrategyExperiment extends Model
{
    protected $fillable = [
        'schema_version', 'name', 'status', 'universe_version_id', 'objective',
        'constraints_json', 'search_budget', 'seeds_json', 'regimes_json',
        'cost_policy_json', 'attribution_policy_json', 'benchmark_policy_json',
        'execution_policy_version', 'execution_policy_hash', 'development_start',
        'development_end', 'holdout_start', 'holdout_end', 'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExperimentStatus::class,
            'constraints_json' => 'array',
            'seeds_json' => 'array',
            'regimes_json' => 'array',
            'cost_policy_json' => 'array',
            'attribution_policy_json' => 'array',
            'benchmark_policy_json' => 'array',
            'development_start' => 'immutable_datetime',
            'development_end' => 'immutable_datetime',
            'holdout_start' => 'immutable_datetime',
            'holdout_end' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Strategy experiments are immutable.'));
        static::deleting(fn () => throw new LogicException('Strategy experiments are append-only.'));
    }

    public function universeVersion(): BelongsTo
    {
        return $this->belongsTo(UniverseVersion::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(StrategyExperimentCandidate::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }
}
