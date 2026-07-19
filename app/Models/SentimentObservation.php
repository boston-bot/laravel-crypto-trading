<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SentimentObservation extends Model
{
    protected $fillable = ['source', 'published_at', 'first_seen_at', 'raw_value', 'normalized_score', 'change_1d', 'change_7d', 'zscore_30d', 'zscore_90d', 'classification', 'raw_json'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'first_seen_at' => 'immutable_datetime', 'raw_json' => 'array'];
    }
}
