<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class CryptoFeeObservation extends ImmutableRecord
{
    protected $table = 'mcp_crypto_fee_observations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['reasons' => 'array', 'maker_rate' => 'decimal:18', 'taker_rate' => 'decimal:18',
            'request_started_at' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
