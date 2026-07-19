<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuntimeProcess extends Model
{
    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'desired_state', 'observed_state', 'version', 'current_task', 'process_identity', 'heartbeat_at', 'last_success_at', 'last_error', 'metadata_json'];

    protected function casts(): array
    {
        return ['heartbeat_at' => 'datetime', 'last_success_at' => 'datetime', 'metadata_json' => 'array'];
    }
}
