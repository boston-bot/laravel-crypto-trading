<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $table = 'mcp_research_budgets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['envelope' => 'decimal:18', 'fixed_costs' => 'decimal:18', 'reserved' => 'decimal:18', 'actual' => 'decimal:18', 'reduced_at' => 'immutable_datetime'];
    }
}
