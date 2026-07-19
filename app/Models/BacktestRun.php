<?php

namespace App\Models;

use App\Enums\EvaluationStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BacktestRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_name',
        'strategy_parameter_id',
        'strategy_version_id',
        'universe_version_id',
        'research_manifest_id',
        'run_started_at',
        'run_completed_at',
        'timeframe_start',
        'timeframe_end',
        'status',
        'trigger',
        'metadata_json',
        'spec_json',
        'result_json',
        'holdout_locked',
        'strategy_experiment_id',
        'strategy_experiment_candidate_id',
        'evaluation_stage',
        'execution_policy_hash',
        'result_manifest_hash',
    ];

    protected function casts(): array
    {
        return [
            'run_started_at' => 'datetime',
            'run_completed_at' => 'datetime',
            'timeframe_start' => 'datetime',
            'timeframe_end' => 'datetime',
            'metadata_json' => 'array',
            'spec_json' => 'array',
            'result_json' => 'array',
            'holdout_locked' => 'boolean',
            'evaluation_stage' => EvaluationStage::class,
        ];
    }

    public function strategyParameter(): BelongsTo
    {
        return $this->belongsTo(StrategyParameter::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(BacktestRunMetric::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(StrategyExperiment::class, 'strategy_experiment_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(StrategyExperimentCandidate::class, 'strategy_experiment_candidate_id');
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(StrategyVersion::class);
    }

    public function universeVersion(): BelongsTo
    {
        return $this->belongsTo(UniverseVersion::class);
    }

    public function researchManifest(): BelongsTo
    {
        return $this->belongsTo(ResearchManifest::class);
    }

    public function engineJob(): HasOne
    {
        return $this->hasOne(EngineJob::class);
    }
}
