<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'mcp_research_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'occurrences' => 'integer'];
    }
}
