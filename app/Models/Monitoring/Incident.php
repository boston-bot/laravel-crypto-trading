<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $table = 'mcp_incidents';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime', 'last_notified_at' => 'immutable_datetime', 'reopen_count' => 'integer', 'notified_count' => 'integer'];
    }
}
