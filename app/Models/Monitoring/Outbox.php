<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    protected $table = 'mcp_outbox';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'dispatched_at' => 'immutable_datetime'];
    }
}
