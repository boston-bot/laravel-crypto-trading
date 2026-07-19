<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class StrategyVersion extends Model
{
    protected $fillable = ['name', 'version', 'schema_version', 'engine_version', 'status', 'content_hash', 'definition_json', 'activated_at', 'retired_at'];

    protected function casts(): array
    {
        return ['definition_json' => 'array', 'activated_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') !== 'draft') {
                throw new LogicException('Completed strategy versions are immutable; create a new version.');
            }
        });
    }
}
