<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineCycle extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'broker_account_id', 'paper_session_id', 'strategy_version_id', 'universe_version_id', 'mode', 'trigger', 'evaluation_kind', 'status', 'cycle_key', 'as_of', 'logical_bar_close', 'evidence_cutoff', 'current_step', 'started_at', 'heartbeat_at', 'lease_expires_at', 'completed_at', 'last_error', 'summary_json'];

    protected function casts(): array
    {
        return ['as_of' => 'immutable_datetime', 'logical_bar_close' => 'immutable_datetime', 'evidence_cutoff' => 'immutable_datetime', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'lease_expires_at' => 'datetime', 'completed_at' => 'datetime', 'summary_json' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BrokerAccount::class, 'broker_account_id');
    }

    public function paperSession(): BelongsTo
    {
        return $this->belongsTo(PaperSession::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(PipelineCycleStep::class)->orderBy('position');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(AssetEvaluation::class);
    }
}
