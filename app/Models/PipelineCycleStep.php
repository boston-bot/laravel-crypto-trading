<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineCycleStep extends Model
{
    use HasFactory;

    protected $fillable = ['pipeline_cycle_id', 'step_key', 'position', 'status', 'engine_job_id', 'started_at', 'heartbeat_at', 'lease_expires_at', 'completed_at', 'reason', 'last_error', 'context_json'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'lease_expires_at' => 'datetime', 'completed_at' => 'datetime', 'context_json' => 'array'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PipelineCycle::class, 'pipeline_cycle_id');
    }

    public function engineJob(): BelongsTo
    {
        return $this->belongsTo(EngineJob::class);
    }
}
