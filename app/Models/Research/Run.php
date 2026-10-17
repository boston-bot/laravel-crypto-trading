<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    protected $table = 'mcp_research_runs';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['result' => 'encrypted:array', 'reserved_cost' => 'decimal:18', 'actual_cost' => 'decimal:18', 'attempts' => 'integer',
            'input_tokens' => 'integer', 'output_tokens' => 'integer', 'lease_until' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
