<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EngineJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'schema_version', 'kind', 'strategy_version_id', 'universe_version_id',
        'research_manifest_id', 'idempotency_key', 'as_of', 'valid_until', 'payload_json',
        'status', 'attempts', 'max_attempts', 'lease_owner', 'lease_expires_at',
        'heartbeat_at', 'completed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'payload_json' => 'array',
            'lease_expires_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function result(): HasOne
    {
        return $this->hasOne(EngineResult::class);
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
}
