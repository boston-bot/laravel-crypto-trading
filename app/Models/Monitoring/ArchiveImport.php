<?php

namespace App\Models\Monitoring;

class ArchiveImport extends ImmutableRecord
{
    protected $table = 'mcp_archive_imports';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['report' => 'encrypted:array', 'committed_at' => 'immutable_datetime'];
    }
}
