<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyParameter extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_name',
        'version',
        'is_active',
        'parameters_json',
        'activated_at',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'parameters_json' => 'array',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function backtestRuns(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }
}
