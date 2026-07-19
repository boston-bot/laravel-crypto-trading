<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionCheckpoint extends Model
{
    protected $fillable = ['source', 'stream', 'partition_key', 'cursor', 'last_sequence', 'event_time', 'received_at', 'status', 'metadata_json'];

    protected function casts(): array
    {
        return ['event_time' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'metadata_json' => 'array'];
    }
}
