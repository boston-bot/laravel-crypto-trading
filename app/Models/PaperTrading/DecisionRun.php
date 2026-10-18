<?php

namespace App\Models\PaperTrading;

use Illuminate\Database\Eloquent\Model;

class DecisionRun extends Model
{
    protected $table = 'mcp_paper_decision_runs';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['request_snapshot' => 'encrypted:array', 'result' => 'encrypted:array', 'price_basis' => 'encrypted:array',
            'ledger_version' => 'integer', 'auth_generation' => 'integer', 'reserved_cost' => 'decimal:18', 'actual_cost' => 'decimal:18',
            'input_tokens' => 'integer', 'output_tokens' => 'integer', 'scheduled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'call_started_at' => 'immutable_datetime', 'response_received_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
