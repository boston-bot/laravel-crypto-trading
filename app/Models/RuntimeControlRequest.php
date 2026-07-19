<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuntimeControlRequest extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'operator_action_id', 'process_name', 'requested_action', 'status', 'idempotency_key', 'requested_at', 'started_at', 'completed_at', 'last_error', 'result_json'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'result_json' => 'array'];
    }

    public function operatorAction(): BelongsTo
    {
        return $this->belongsTo(OperatorAction::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(RuntimeProcess::class, 'process_name', 'name');
    }
}
