<?php

namespace App\Services\Operations;

use App\Http\Resources\DecisionInspectorResource;
use App\Models\AssetEvaluation;
use Illuminate\Http\Request;

class StrategyTransparencyQueryService
{
    /** @return array<string, mixed> */
    public function latest(?int $assetId = null, ?string $cycleId = null): array
    {
        $query = $this->baseQuery();
        if ($assetId !== null) {
            $query->where('asset_id', $assetId);
        }
        if ($cycleId !== null) {
            $query->where('pipeline_cycle_id', $cycleId);
        }
        $evaluation = $query->latest('logical_bar_close')->latest('id')->first();

        return [
            'meta' => $this->meta($evaluation),
            'selection' => [
                'asset_id' => $assetId,
                'cycle_id' => $cycleId,
                'assets' => AssetEvaluation::query()->with('asset:id,symbol')->latest('logical_bar_close')->limit(100)->get()->unique('asset_id')->map(fn (AssetEvaluation $item): array => ['id' => $item->asset_id, 'symbol' => $item->asset?->symbol])->values(),
                'cycles' => AssetEvaluation::query()->whereNotNull('pipeline_cycle_id')->latest('logical_bar_close')->limit(100)->get(['pipeline_cycle_id', 'logical_bar_close'])->unique('pipeline_cycle_id')->map(fn (AssetEvaluation $item): array => ['id' => $item->pipeline_cycle_id, 'logical_bar_close' => $item->logical_bar_close?->toIso8601String()])->values(),
            ],
            'decision' => $evaluation ? (new DecisionInspectorResource($evaluation))->resolve(Request::create('/')) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(AssetEvaluation $evaluation): array
    {
        $evaluation->load($this->relations());

        return [
            'meta' => $this->meta($evaluation),
            'decision' => (new DecisionInspectorResource($evaluation))->resolve(Request::create('/')),
        ];
    }

    private function baseQuery()
    {
        return AssetEvaluation::query()->with($this->relations());
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['asset:id,symbol', 'cycle.steps', 'decision.policyChecks', 'decision.brokerOrder'];
    }

    /** @return array<string, mixed> */
    private function meta(?AssetEvaluation $evaluation): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'local_only' => true,
            'read_only' => true,
            'measurement_state' => $evaluation ? ($evaluation->decision_trace_hash ? 'measured' : 'incomplete') : 'not_measured',
        ];
    }
}
