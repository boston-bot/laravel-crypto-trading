<?php

namespace App\Services\Research;

use App\Enums\HoldoutStatus;
use App\Models\EngineJob;
use App\Models\HoldoutAccessEvent;
use App\Models\HoldoutInterval;
use App\Models\ResearchManifest;
use App\Models\StrategyVersion;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class HoldoutGuard
{
    public function assertDevelopmentWindowAllowed(CarbonInterface $start, CarbonInterface $end): void
    {
        $overlaps = HoldoutInterval::query()
            ->where('holdout_start', '<', $end)
            ->where('holdout_end', '>', $start)
            ->exists();
        if ($overlaps) {
            throw new DomainException('Development repositories cannot read a locked or revealed holdout interval.');
        }
    }

    public function authorize(
        StrategyVersion $finalist,
        ResearchManifest $manifest,
        string $actor,
        string $purpose,
    ): HoldoutInterval {
        $finalist->loadMissing('candidate.experiment.holdoutInterval');
        $candidate = $finalist->candidate;
        $experiment = $candidate?->experiment;
        if ($candidate === null || $experiment === null || ! $finalist->is_deployable || $finalist->version_role !== 'final') {
            throw new DomainException('Holdout authorization requires a frozen deployable finalist linked to an experiment candidate.');
        }
        if ($finalist->calibration_end === null || $finalist->calibration_end->gt($experiment->development_end)) {
            throw new DomainException('The finalist calibration boundary must not enter the holdout interval.');
        }
        if ($manifest->source_window_end === null || $manifest->source_window_end->gt($experiment->development_end)) {
            throw new DomainException('The finalist manifest must be development-only.');
        }

        return DB::transaction(function () use ($finalist, $manifest, $actor, $purpose, $candidate, $experiment): HoldoutInterval {
            $interval = HoldoutInterval::query()
                ->where('strategy_experiment_id', $experiment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $codeHash = hash('sha256', $finalist->engine_version);
            $idempotencyKey = $this->hash([
                'interval_hash' => $interval->content_hash,
                'candidate_hash' => $finalist->content_hash,
                'manifest_hash' => $manifest->content_hash,
                'engine_version' => $finalist->engine_version,
                'code_hash' => $codeHash,
                'actor' => $actor,
                'purpose' => $purpose,
            ]);

            if ($interval->status !== HoldoutStatus::Locked) {
                if ($interval->authorization_idempotency_key === $idempotencyKey
                    && $interval->candidate_hash === $finalist->content_hash
                    && $interval->manifest_hash === $manifest->content_hash) {
                    return $interval;
                }

                throw new DomainException('This holdout interval is already bound to a different finalist or authorization.');
            }
            if (HoldoutInterval::query()
                ->whereKeyNot($interval->id)
                ->whereNotNull('revealed_at')
                ->where('holdout_start', '<', $interval->holdout_end)
                ->where('holdout_end', '>', $interval->holdout_start)
                ->exists()) {
                throw new DomainException('The holdout overlaps a previously revealed interval and can never be opened.');
            }
            $eligibleRun = $experiment->runs()
                ->where('strategy_experiment_candidate_id', $candidate->id)
                ->where('research_manifest_id', $manifest->id)
                ->where('status', 'completed')
                ->get()
                ->contains(fn ($run): bool => data_get($run->result_json, 'gate.status') === 'passed');
            if (! $eligibleRun) {
                throw new DomainException('The finalist has no passing development-only run for this manifest.');
            }

            $interval->update([
                'status' => HoldoutStatus::Authorized,
                'authorized_strategy_version_id' => $finalist->id,
                'research_manifest_id' => $manifest->id,
                'candidate_hash' => $finalist->content_hash,
                'manifest_hash' => $manifest->content_hash,
                'engine_version' => $finalist->engine_version,
                'code_hash' => $codeHash,
                'authorization_idempotency_key' => $idempotencyKey,
                'authorized_by' => $actor,
                'purpose' => $purpose,
                'authorized_at' => now(),
            ]);
            $this->event($interval, 'authorized', $idempotencyKey, $actor, $purpose);

            return $interval->refresh();
        });
    }

    public function recordAccess(EngineJob $job): HoldoutInterval
    {
        return DB::transaction(function () use ($job): HoldoutInterval {
            $interval = HoldoutInterval::query()
                ->whereKey((int) data_get($job->payload_json, 'holdout_interval_id'))
                ->lockForUpdate()
                ->firstOrFail();
            if ($interval->engine_job_id !== $job->id) {
                throw new DomainException('The worker job is not the authorized holdout job.');
            }
            $idempotencyKey = $this->hash(['access', $interval->authorization_idempotency_key, $job->id]);
            if ($interval->status === HoldoutStatus::Running && $interval->revealed_at !== null) {
                return $interval;
            }
            if ($interval->status !== HoldoutStatus::Authorized) {
                throw new DomainException('The holdout is not authorized for access.');
            }
            $interval->update(['status' => HoldoutStatus::Running, 'revealed_at' => now()]);
            $this->event($interval, 'accessed', $idempotencyKey, (string) $interval->authorized_by, (string) $interval->purpose, $job->id);

            return $interval->refresh();
        });
    }

    /** @param array<string, mixed> $details */
    public function recordTerminal(HoldoutInterval $interval, HoldoutStatus $status, array $details): HoldoutInterval
    {
        if (! $status->isTerminal()) {
            throw new DomainException('A holdout terminal result must be passed, failed, or inconclusive.');
        }

        return DB::transaction(function () use ($interval, $status, $details): HoldoutInterval {
            $locked = HoldoutInterval::query()->whereKey($interval->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                if ($locked->status === $status && $locked->terminal_result_json === $details) {
                    return $locked;
                }
                throw new DomainException('The holdout already has an immutable terminal result.');
            }
            if ($locked->status !== HoldoutStatus::Running) {
                throw new DomainException('Only an accessed holdout can record a terminal result.');
            }
            $locked->update(['status' => $status, 'terminal_at' => now(), 'terminal_result_json' => $details]);
            $this->event(
                $locked,
                'terminal_'.$status->value,
                $this->hash(['terminal', $locked->id, $status->value, $details]),
                (string) $locked->authorized_by,
                (string) $locked->purpose,
                $locked->engine_job_id,
                $details,
            );

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $details */
    private function event(HoldoutInterval $interval, string $type, string $key, string $actor, string $purpose, ?string $jobId = null, array $details = []): HoldoutAccessEvent
    {
        return HoldoutAccessEvent::query()->firstOrCreate(
            ['holdout_interval_id' => $interval->id, 'event_type' => $type, 'idempotency_key' => $key],
            [
                'strategy_experiment_id' => $interval->strategy_experiment_id,
                'strategy_version_id' => $interval->authorized_strategy_version_id,
                'research_manifest_id' => $interval->research_manifest_id,
                'engine_job_id' => $jobId,
                'candidate_hash' => $interval->candidate_hash,
                'manifest_hash' => $interval->manifest_hash,
                'engine_version' => $interval->engine_version,
                'code_hash' => $interval->code_hash,
                'actor' => $actor,
                'purpose' => $purpose,
                'details_json' => $details ?: null,
                'occurred_at' => now(),
            ],
        );
    }

    /** @param array<mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
