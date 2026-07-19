<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UniverseMembership extends Model
{
    protected $fillable = ['universe_version_id', 'asset_id', 'venue_product_id', 'venue', 'product_id', 'quote_asset', 'valid_from', 'valid_to', 'listed_at', 'delisted_at', 'trading_state', 'price_precision', 'quantity_precision', 'minimum_notional', 'evidence_json', 'evidence_hash'];

    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_to' => 'immutable_datetime', 'listed_at' => 'immutable_datetime', 'delisted_at' => 'immutable_datetime', 'minimum_notional' => 'decimal:8', 'evidence_json' => 'array'];
    }

    public function universeVersion(): BelongsTo
    {
        return $this->belongsTo(UniverseVersion::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function venueProduct(): BelongsTo
    {
        return $this->belongsTo(VenueProduct::class);
    }
}
