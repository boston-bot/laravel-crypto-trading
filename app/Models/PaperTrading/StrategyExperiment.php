<?php

namespace App\Models\PaperTrading;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class StrategyExperiment extends Model
{
    protected $table = 'mcp_strategy_experiments';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'report' => 'encrypted:array', 'lease_expires_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->isDirty(['experiment_id', 'mcp_connection_id', 'actor_id', 'scope', 'request_id', 'definition_hash', 'definition'])) {
                throw new LogicException('Experiment definitions are frozen; start a new experiment.');
            }
            if ($row->getOriginal('status') === 'complete') {
                throw new LogicException('Completed experiments are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Experiment history cannot be deleted.'));
    }
}
