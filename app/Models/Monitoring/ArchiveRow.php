<?php

namespace App\Models\Monitoring;

class ArchiveRow extends ImmutableRecord
{
    protected $table = 'mcp_archive_rows';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }
}
