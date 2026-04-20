<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetFeatureSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'snapshot_time',
        'timeframe',
        'trend_score',
        'momentum_score',
        'relative_strength_score',
        'pullback_quality_score',
        'volatility_quality_score',
        'participation_score',
        'execution_quality_penalty',
        'atr_pct',
        'realized_volatility_20d',
        'features_json',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_time' => 'datetime',
            'trend_score' => 'decimal:4',
            'momentum_score' => 'decimal:4',
            'relative_strength_score' => 'decimal:4',
            'pullback_quality_score' => 'decimal:4',
            'volatility_quality_score' => 'decimal:4',
            'participation_score' => 'decimal:4',
            'execution_quality_penalty' => 'decimal:4',
            'atr_pct' => 'decimal:6',
            'realized_volatility_20d' => 'decimal:6',
            'features_json' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
