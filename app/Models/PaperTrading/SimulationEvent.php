<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimulationEvent extends ImmutableRecord
{
    protected $table = 'mcp_paper_sim_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function observation(): BelongsTo
    {
        return $this->belongsTo(SimulationObservation::class, 'observation_id');
    }

    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array', 'quantity' => 'decimal:18', 'price' => 'decimal:18', 'fee' => 'decimal:18',
            'cash_delta' => 'decimal:18', 'ledger_version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
