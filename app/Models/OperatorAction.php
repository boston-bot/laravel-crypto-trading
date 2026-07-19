<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatorAction extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'action_type', 'actor', 'status', 'idempotency_key', 'target_type', 'target_id', 'request_json', 'result_json', 'last_error', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['request_json' => 'array', 'result_json' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
