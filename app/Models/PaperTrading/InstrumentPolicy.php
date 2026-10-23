<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class InstrumentPolicy extends ImmutableRecord
{
    protected $table = 'mcp_paper_instrument_policies';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'applied_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
