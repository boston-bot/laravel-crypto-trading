<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class SimulationObservation extends ImmutableRecord
{
    protected $table = 'mcp_paper_sim_observations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'source_at' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
