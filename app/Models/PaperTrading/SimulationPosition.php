<?php

namespace App\Models\PaperTrading;

use Illuminate\Database\Eloquent\Model;

class SimulationPosition extends Model
{
    protected $table = 'mcp_paper_sim_positions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:18', 'reserved_quantity' => 'decimal:18', 'cost_basis' => 'decimal:18', 'realized_pnl' => 'decimal:18'];
    }
}
