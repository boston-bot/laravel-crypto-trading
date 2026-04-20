<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_name',
        'strategy_parameter_id',
        'run_started_at',
        'run_completed_at',
        'timeframe_start',
        'timeframe_end',
        'status',
        'trigger',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'run_started_at' => 'datetime',
            'run_completed_at' => 'datetime',
            'timeframe_start' => 'datetime',
            'timeframe_end' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function strategyParameter(): BelongsTo
    {
        return $this->belongsTo(StrategyParameter::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(BacktestRunMetric::class);
    }
}
