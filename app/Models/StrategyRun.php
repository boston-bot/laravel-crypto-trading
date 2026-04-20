<?php

namespace App\Models;

use App\Enums\StrategyMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_name',
        'mode',
        'started_at',
        'completed_at',
        'account_equity',
        'summary_json',
        'skill_outputs_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'mode' => StrategyMode::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'account_equity' => 'decimal:8',
            'summary_json' => 'array',
            'skill_outputs_json' => 'array',
        ];
    }

    public function tradeDecisions(): HasMany
    {
        return $this->hasMany(TradeDecision::class);
    }
}

