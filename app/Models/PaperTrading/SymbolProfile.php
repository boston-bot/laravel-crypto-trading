<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class SymbolProfile extends ImmutableRecord
{
    protected $table = 'mcp_symbol_profiles';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'session_date' => 'immutable_date', 'cutoff' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
