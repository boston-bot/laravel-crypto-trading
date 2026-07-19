<?php

namespace App\Models;

use App\Enums\HoldoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class HoldoutInterval extends Model
{
    protected $fillable = [
        'strategy_experiment_id', 'holdout_start', 'holdout_end', 'status', 'content_hash',
        'authorized_strategy_version_id', 'research_manifest_id', 'engine_job_id',
        'candidate_hash', 'manifest_hash', 'engine_version', 'code_hash',
        'authorization_idempotency_key', 'authorized_by', 'purpose', 'authorized_at',
        'revealed_at', 'terminal_at', 'terminal_result_json',
    ];

    protected function casts(): array
    {
        return [
            'status' => HoldoutStatus::class,
            'holdout_start' => 'immutable_datetime',
            'holdout_end' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'revealed_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
            'terminal_result_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $interval): void {
            foreach (['strategy_experiment_id', 'holdout_start', 'holdout_end', 'content_hash'] as $field) {
                if ($interval->isDirty($field)) {
                    throw new LogicException('Holdout interval identity is immutable.');
                }
            }
            $original = HoldoutStatus::from((string) $interval->getRawOriginal('status'));
            if ($original->isTerminal()) {
                throw new LogicException('Terminal holdout results are immutable.');
            }
            $next = $interval->status;
            $allowed = [
                HoldoutStatus::Locked->value => [HoldoutStatus::Locked, HoldoutStatus::Authorized],
                HoldoutStatus::Authorized->value => [HoldoutStatus::Authorized, HoldoutStatus::Running],
                HoldoutStatus::Running->value => [HoldoutStatus::Running, HoldoutStatus::Passed, HoldoutStatus::Failed, HoldoutStatus::Inconclusive],
            ];
            if (! in_array($next, $allowed[$original->value] ?? [], true)) {
                throw new LogicException('Invalid holdout lifecycle transition.');
            }
            $authorizationFields = [
                'authorized_strategy_version_id', 'research_manifest_id', 'candidate_hash',
                'manifest_hash', 'engine_version', 'code_hash', 'authorization_idempotency_key',
                'authorized_by', 'purpose', 'authorized_at',
            ];
            if ($original !== HoldoutStatus::Locked && $interval->isDirty($authorizationFields)) {
                throw new LogicException('Holdout authorization identity is immutable after authorization.');
            }
        });
        static::deleting(fn () => throw new LogicException('Holdout intervals are append-only.'));
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(StrategyExperiment::class, 'strategy_experiment_id');
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(StrategyVersion::class, 'authorized_strategy_version_id');
    }

    public function researchManifest(): BelongsTo
    {
        return $this->belongsTo(ResearchManifest::class);
    }

    public function engineJob(): BelongsTo
    {
        return $this->belongsTo(EngineJob::class);
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(HoldoutAccessEvent::class);
    }
}
