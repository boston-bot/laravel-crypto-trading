<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VenueProduct extends Model
{
    protected $fillable = ['asset_id', 'venue', 'product_id', 'base_asset', 'quote_asset', 'status', 'valid_from', 'valid_to', 'metadata_json'];

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_to' => 'immutable_datetime', 'metadata_json' => 'array'];
    }

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
    public function universeMemberships(): HasMany { return $this->hasMany(UniverseMembership::class); }
}
