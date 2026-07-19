<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataQualityIncident extends Model
{
    protected $fillable = ['source', 'stream', 'severity', 'incident_type', 'started_at', 'resolved_at', 'message', 'context_json'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'context_json' => 'array'];
    }
}
