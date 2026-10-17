<?php

namespace App\Models\Monitoring;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class ImmutableRecord extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Append a new version instead of editing history.'));
        static::deleting(fn () => throw new LogicException('Historical evidence cannot be deleted.'));
    }
}
