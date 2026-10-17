<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;

class Snapshot extends Model
{
    protected $table = 'mcp_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'observations' => 'encrypted:array', 'retrieved_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }
}
