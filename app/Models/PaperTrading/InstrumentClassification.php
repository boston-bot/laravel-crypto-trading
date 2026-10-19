<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

class InstrumentClassification extends ImmutableRecord
{
    protected $table = 'mcp_paper_instrument_classifications';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'source_at' => 'immutable_datetime', 'retrieved_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
