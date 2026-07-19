<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ResearchManifest extends Model
{
    protected $fillable = ['kind', 'schema_version', 'content_hash', 'source_window_start', 'source_window_end', 'row_count', 'inputs_json', 'quality_json', 'frozen_at'];

    protected function casts(): array
    {
        return [
            'source_window_start' => 'immutable_datetime', 'source_window_end' => 'immutable_datetime',
            'inputs_json' => 'array', 'quality_json' => 'array', 'frozen_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Research manifests are immutable.'));
        static::deleting(fn () => throw new LogicException('Research manifests are append-only.'));
    }
}
