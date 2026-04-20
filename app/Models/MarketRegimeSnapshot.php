<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketRegimeSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_time',
        'regime',
        'confidence',
        'components_json',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_time' => 'datetime',
            'confidence' => 'decimal:4',
            'components_json' => 'array',
        ];
    }
}
