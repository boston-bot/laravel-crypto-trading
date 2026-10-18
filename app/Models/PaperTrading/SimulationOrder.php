<?php

namespace App\Models\PaperTrading;

use Illuminate\Database\Eloquent\Model;

class SimulationOrder extends Model
{
    protected $table = 'mcp_paper_sim_orders';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (self $order) {
            if ($order->isDirty(['paper_portfolio_id', 'policy_application_id', 'actor_id', 'request_id', 'intent_hash', 'intent', 'assumptions',
                'auth_generation', 'symbol', 'sleeve', 'side', 'type', 'quantity', 'price_bound', 'accepted_at', 'expires_at'])) {
                throw new \LogicException('Append a new scenario intent instead of changing accepted evidence.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Scenario order history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['intent' => 'encrypted:array', 'assumptions' => 'encrypted:array', 'auth_generation' => 'integer',
            'quantity' => 'decimal:18', 'filled_quantity' => 'decimal:18', 'price_bound' => 'decimal:18', 'reserved_cash' => 'decimal:18',
            'accepted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'last_source_at' => 'immutable_datetime'];
    }
}
