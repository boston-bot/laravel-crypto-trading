<?php

namespace App\Models\PaperTrading;

use App\Models\Monitoring\ImmutableRecord;

/** Saved planning versions only. No runtime or model consumer is attached in this slice. */
class MandateVersion extends ImmutableRecord
{
    protected $table = 'mcp_paper_mandate_versions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'reason' => 'encrypted', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
