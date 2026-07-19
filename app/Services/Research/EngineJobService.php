<?php

namespace App\Services\Research;

use App\Data\Research\EvaluationRequest;
use App\Models\EngineJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EngineJobService
{
    public function enqueueEvaluation(EvaluationRequest $request): EngineJob
    {
        return EngineJob::query()->firstOrCreate(
            ['idempotency_key' => $request->idempotencyKey()],
            [
                'id' => (string) Str::uuid(),
                'schema_version' => $request->schemaVersion,
                'kind' => 'evaluate',
                'strategy_version_id' => $request->strategyVersionId,
                'universe_version_id' => $request->universeVersionId,
                'research_manifest_id' => $request->manifestId,
                'as_of' => $request->asOf,
                'valid_until' => now()->utc()->addMinutes((int) config('research.engine.job_ttl_minutes', 60)),
                'payload_json' => $request->toPayload(),
                'status' => 'pending',
                'max_attempts' => (int) config('research.engine.max_attempts', 3),
            ],
        );
    }

    public function expireStaleLeases(): int
    {
        return DB::transaction(function (): int {
            return EngineJob::query()
                ->where('status', 'leased')
                ->where('lease_expires_at', '<=', now())
                ->whereColumn('attempts', '<', 'max_attempts')
                ->update([
                    'status' => 'pending',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    public function expireOverdueJobs(): int
    {
        return EngineJob::query()
            ->whereIn('status', ['pending', 'leased'])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now())
            ->update(['status' => 'expired', 'completed_at' => now(), 'updated_at' => now()]);
    }
}
