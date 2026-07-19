<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StrategyVersion extends Model
{
    protected $fillable = [
        'name', 'version', 'schema_version', 'engine_version', 'status', 'content_hash',
        'definition_json', 'activated_at', 'retired_at', 'strategy_experiment_candidate_id',
        'parent_strategy_version_id', 'version_role', 'calibration_start', 'calibration_end',
        'is_deployable',
    ];

    protected function casts(): array
    {
        return [
            'definition_json' => 'array', 'activated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime', 'calibration_start' => 'immutable_datetime',
            'calibration_end' => 'immutable_datetime', 'is_deployable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') !== 'draft') {
                throw new LogicException('Completed strategy versions are immutable; create a new version.');
            }
        });
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(StrategyExperimentCandidate::class, 'strategy_experiment_candidate_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_strategy_version_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_strategy_version_id');
    }
}
