<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionQualitySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'snapshot_time',
        'spread_bps',
        'slippage_bps_estimate',
        'liquidity_score',
        'execution_penalty_score',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_time' => 'datetime',
            'spread_bps' => 'decimal:4',
            'slippage_bps_estimate' => 'decimal:4',
            'liquidity_score' => 'decimal:4',
            'execution_penalty_score' => 'decimal:4',
            'context_json' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
