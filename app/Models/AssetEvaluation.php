<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssetEvaluation extends Model
{
    use HasFactory;

    protected $fillable = ['pipeline_cycle_id', 'broker_account_id', 'asset_id', 'strategy_run_id', 'strategy_version_id', 'universe_version_id', 'engine_result_id', 'mode', 'evaluation_kind', 'logical_bar_close', 'as_of', 'eligible', 'actionable', 'action_suppressed_at', 'action_suppression_reason', 'action', 'score', 'calibrated_probability', 'expected_value_bps', 'primary_explanation', 'thresholds_json', 'factor_attribution_json', 'reason_codes_json', 'warnings_json', 'candle_evidence_json'];

    protected function casts(): array
    {
        return ['logical_bar_close' => 'immutable_datetime', 'as_of' => 'immutable_datetime', 'eligible' => 'boolean', 'actionable' => 'boolean', 'action_suppressed_at' => 'immutable_datetime', 'score' => 'decimal:6', 'calibrated_probability' => 'decimal:8', 'expected_value_bps' => 'decimal:6', 'thresholds_json' => 'array', 'factor_attribution_json' => 'array', 'reason_codes_json' => 'array', 'warnings_json' => 'array', 'candle_evidence_json' => 'array'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PipelineCycle::class, 'pipeline_cycle_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function decision(): HasOne
    {
        return $this->hasOne(TradeDecision::class);
    }
}
