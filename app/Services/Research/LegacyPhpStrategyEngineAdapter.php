<?php

namespace App\Services\Research;

use App\Contracts\StrategyEngine;
use App\Data\Research\EvaluationRequest;
use App\Data\Research\EvaluationResult;
use App\Models\Asset;
use App\Models\EngineJob;
use App\Models\EngineResult;
use App\Services\Strategy\SignalAggregator;

class LegacyPhpStrategyEngineAdapter implements StrategyEngine
{
    public function __construct(
        private readonly EngineJobService $jobs,
        private readonly SignalAggregator $signals,
    ) {}

    public function submit(EvaluationRequest $request): EngineJob
    {
        $job = $this->jobs->enqueueEvaluation($request);
        if ($job->status === 'succeeded') {
            return $job;
        }

        $proposals = [];
        foreach ($request->assets as $item) {
            $asset = Asset::query()->find($item['id']);
            if ($asset === null) {
                continue;
            }

            $signal = $this->signals->evaluate($asset);
            $proposals[] = [
                'asset_id' => $asset->id,
                'asset' => $asset->symbol,
                'action' => strtoupper((string) data_get($signal, 'decision.value', 'HOLD')),
                'side' => data_get($signal, 'side.value'),
                'score' => (float) ($signal['score'] ?? 0),
                'calibrated_probability' => (float) ($signal['confidence'] ?? 0.5),
                'expected_value_bps' => null,
                'factor_attribution' => (array) data_get($signal, 'signal_context.scoring.components', []),
                'warnings' => ['legacy_php_adapter'],
                'signal' => $this->normalizeSignal($signal),
            ];
        }

        $manifestHash = hash('sha256', json_encode($request->toPayload(), JSON_THROW_ON_ERROR));
        $resultValidUntil = now()->utc()->addMinutes((int) config('research.engine.proposal_ttl_minutes', 30));
        $logicalDeadline = ($request->logicalBarClose ?? $request->asOf)->addMinutes((int) config('research.engine.max_signal_age_minutes', 240));
        if ($logicalDeadline->lt($resultValidUntil)) {
            $resultValidUntil = $logicalDeadline;
        }
        EngineResult::query()->updateOrCreate(
            ['engine_job_id' => $job->id],
            [
                'result_kind' => 'evaluation',
                'engine_version' => 'legacy-php',
                'schema_version' => $request->schemaVersion,
                'as_of' => $request->asOf,
                'valid_until' => $resultValidUntil,
                'manifest_hash' => $manifestHash,
                'payload_json' => ['proposals' => $proposals, 'diagnostics' => ['adapter' => 'legacy_php']],
            ],
        );
        $job->update(['status' => 'succeeded', 'completed_at' => now()]);

        return $job->fresh();
    }

    public function result(EngineJob $job): ?EvaluationResult
    {
        $result = $job->result()->first();

        return $result ? EvaluationResult::fromModel($result) : null;
    }

    /** @param array<string, mixed> $signal */
    private function normalizeSignal(array $signal): array
    {
        if (isset($signal['decision']) && is_object($signal['decision']) && property_exists($signal['decision'], 'value')) {
            $signal['decision'] = $signal['decision']->value;
        }
        if (isset($signal['side']) && is_object($signal['side']) && property_exists($signal['side'], 'value')) {
            $signal['side'] = $signal['side']->value;
        }

        return $signal;
    }
}
