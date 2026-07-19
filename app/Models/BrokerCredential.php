<?php

namespace App\Models;

use App\Enums\BrokerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'broker',
        'label',
        'api_key_ref',
        'secret_ref',
        'status',
        'metadata',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'broker' => BrokerType::class,
            'metadata' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }

    public function brokerAccounts(): HasMany
    {
        return $this->hasMany(BrokerAccount::class);
    }
}
