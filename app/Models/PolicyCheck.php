<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'trade_decision_id',
        'policy_name',
        'result',
        'message',
        'context_json',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'boolean',
            'context_json' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function tradeDecision(): BelongsTo
    {
        return $this->belongsTo(TradeDecision::class);
    }
}

