<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;

/** Latest observation per operational component. Details are non-sensitive operational metadata only. */
class ComponentStatus extends Model
{
    protected $table = 'mcp_component_status';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['detail' => 'array', 'observed_at' => 'immutable_datetime'];
    }

    public static function record(string $component, string $status, array $detail = []): self
    {
        return self::updateOrCreate(['component' => $component], ['status' => $status, 'detail' => $detail, 'observed_at' => now()]);
    }
}
