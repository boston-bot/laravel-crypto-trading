<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    protected $table = 'mcp_runs';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'auth_generation' => 'integer'];
    }
}
