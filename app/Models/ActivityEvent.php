<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    use HasFactory;

    protected $fillable = ['source_type', 'source_id', 'event_type', 'category', 'severity', 'title', 'explanation', 'occurred_at', 'broker_account_id', 'asset_id', 'pipeline_cycle_id', 'detail_json'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'detail_json' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class, 'broker_account_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PipelineCycle::class, 'pipeline_cycle_id');
    }
}
