<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpOauthCredential extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['client_id', 'access_token', 'refresh_token', 'oauth_metadata'];

    protected function casts(): array
    {
        return ['client_id' => 'encrypted', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'oauth_metadata' => 'encrypted:array', 'expires_at' => 'immutable_datetime'];
    }
}
