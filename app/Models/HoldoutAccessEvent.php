<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HoldoutAccessEvent extends Model
{
    protected $fillable = [
        'holdout_interval_id', 'event_type', 'strategy_experiment_id', 'strategy_version_id',
        'research_manifest_id', 'engine_job_id', 'candidate_hash', 'manifest_hash',
        'engine_version', 'code_hash', 'actor', 'purpose', 'idempotency_key',
        'details_json', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['details_json' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Holdout access events are immutable.'));
        static::deleting(fn () => throw new LogicException('Holdout access events are append-only.'));
    }

    public function interval(): BelongsTo
    {
        return $this->belongsTo(HoldoutInterval::class, 'holdout_interval_id');
    }
}
