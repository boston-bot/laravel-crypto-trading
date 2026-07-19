<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class UniverseVersion extends Model
{
    protected $fillable = ['name', 'version', 'status', 'content_hash', 'symbols_json', 'rules_json', 'activated_at', 'retired_at'];

    protected function casts(): array
    {
        return ['symbols_json' => 'array', 'rules_json' => 'array', 'activated_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') !== 'draft') {
                throw new LogicException('Completed universe versions are immutable; create a new version.');
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UniverseMembership::class);
    }
}
