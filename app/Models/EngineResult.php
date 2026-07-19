<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineResult extends Model
{
    protected $fillable = [
        'engine_job_id', 'result_kind', 'engine_version', 'schema_version', 'as_of',
        'valid_until', 'manifest_hash', 'payload_json', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'payload_json' => 'array',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(EngineJob::class, 'engine_job_id');
    }
}
