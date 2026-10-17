<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpOauthAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['state_hash', 'session_hash', 'verifier', 'redirect_uri', 'client_id', 'oauth_metadata'];

    protected function casts(): array
    {
        return ['verifier' => 'encrypted', 'redirect_uri' => 'encrypted', 'client_id' => 'encrypted', 'oauth_metadata' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
