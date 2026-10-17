<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['bound_account_id', 'capability_manifest'];

    protected function casts(): array
    {
        return ['auth_generation' => 'integer', 'capability_manifest' => 'encrypted:array', 'bound_account_id' => 'encrypted', 'bound_at' => 'immutable_datetime', 'capabilities_checked_at' => 'immutable_datetime'];
    }
}
