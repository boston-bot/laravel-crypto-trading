<?php

namespace App\Models\PaperTrading;

use Illuminate\Database\Eloquent\Model;

class Portfolio extends Model
{
    protected $table = 'mcp_paper_portfolios';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['starting_cash' => 'decimal:18', 'cash' => 'decimal:18', 'reserved_cash' => 'decimal:18',
            'ledger_version' => 'integer', 'policy_revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
