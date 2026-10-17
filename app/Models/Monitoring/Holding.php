<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;

class Holding extends Model
{
    protected $table = 'mcp_holdings';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:18', 'market_value' => 'decimal:18', 'attribution' => 'encrypted:array'];
    }
}
