<?php

namespace App\Models\Research;

use Illuminate\Database\Eloquent\Model;

/** Local research watchlist only. It never edits a brokerage watchlist. */
class WatchlistItem extends Model
{
    protected $table = 'mcp_watchlist_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'added_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }
}
