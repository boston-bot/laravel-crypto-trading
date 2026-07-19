<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestRunMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'backtest_run_id',
        'metric_name',
        'metric_group',
        'metric_value',
        'dimension_key',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'metric_value' => 'decimal:8',
            'context_json' => 'array',
        ];
    }

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }
}
