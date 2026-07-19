<?php

namespace App\Services\Research;

use App\Models\AssetEvaluation;
use App\Models\MarketRegimeSnapshot;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperSession;
use App\Models\PipelineCycle;
use App\Models\TradeAttribution;

class PaperEvidenceGateService
{
    /** @return array<string, mixed> */
    public function evaluate(PaperSession $session): array
    {
        if (! $session->evidence_eligible) {
            return $this->result('not_eligible', [], [], false);
        }
        $session->loadMissing('strategyVersion.candidate.experiment.holdoutInterval');
        $experiment = $session->strategyVersion?->candidate?->experiment;
        $holdout = $experiment?->holdoutInterval;
        $pinnedFinalist = $session->strategy_version_id !== null
            && $session->universe_version_id !== null
            && $session->strategyVersion?->is_deployable
            && $session->strategyVersion?->version_role === 'final'
            && $experiment?->universe_version_id === $session->universe_version_id
            && $experiment?->execution_policy_hash === $session->execution_policy_hash
            && $holdout?->status?->value === 'passed'
            && $holdout?->authorized_strategy_version_id === $session->strategy_version_id;
        $canonicalEngine = in_array((string) config('research.engine.driver'), ['database', 'python'], true);
        $versionMismatch = $this->versionMismatchExists($session);
        $reconciled = (bool) data_get($session->metadata_json, 'reconciliation_passed', false)
            || PipelineCycle::query()->where('paper_session_id', $session->id)
                ->whereHas('steps', fn ($query) => $query->where('step_key', 'reconciliation')->where('status', 'completed'))
                ->exists();
        $reconciliationFailed = PipelineCycle::query()->where('paper_session_id', $session->id)
            ->whereHas('steps', fn ($query) => $query->where('step_key', 'reconciliation')->where('status', 'failed'))
            ->exists();
        $manifestReconciled = (bool) data_get($session->metadata_json, 'manifest_reconciled', false);
        $manifestFailed = (bool) data_get($session->metadata_json, 'manifest_reconciliation_failed', false);
        $maxDrawdown = (float) (PaperPortfolioSnapshot::query()->where('paper_session_id', $session->id)->max('drawdown_pct') ?? 0.0);
        $tradeQuery = TradeAttribution::query()->where('paper_session_id', $session->id)->whereNotNull('realized_pnl');
        $roundTrips = (clone $tradeQuery)->count();
        $assetCount = (clone $tradeQuery)->distinct()->count('asset_id');
        $regimeBars = MarketRegimeSnapshot::query()
            ->whereBetween('snapshot_time', [$session->started_at, now()])
            ->select('regime')
            ->selectRaw('COUNT(DISTINCT snapshot_time) AS bars')
            ->groupBy('regime')
            ->pluck('bars', 'regime')
            ->map(fn ($bars): int => (int) $bars)
            ->all();
        $qualifyingRegimes = collect($regimeBars)->filter(fn (int $bars): bool => $bars >= 20)->count();
        $elapsedDays = (int) floor($session->started_at->diffInDays(now()));

        $failureReasons = array_values(array_filter([
            ! $pinnedFinalist ? 'pinned_finalist_mismatch' : null,
            ! $canonicalEngine ? 'canonical_engine_required' : null,
            $versionMismatch ? 'pinned_version_mismatch' : null,
            $reconciliationFailed ? 'reconciliation_failure' : null,
            $manifestFailed ? 'manifest_failure' : null,
            $maxDrawdown > 15.0 ? 'drawdown_ceiling_breached' : null,
        ]));
        $counts = [
            'elapsed_days' => $elapsedDays,
            'closed_round_trips' => $roundTrips,
            'assets_traded' => $assetCount,
            'qualifying_regimes' => $qualifyingRegimes,
            'regime_bars' => $regimeBars,
            'max_drawdown_pct' => round($maxDrawdown, 4),
        ];
        $checks = [
            'duration' => $elapsedDays >= 90,
            'round_trips' => $roundTrips >= 15,
            'assets' => $assetCount >= 3,
            'regimes' => $qualifyingRegimes >= 2,
            'reconciled' => $reconciled && ! $reconciliationFailed,
            'manifest_reconciled' => $manifestReconciled && ! $manifestFailed,
            'drawdown_ceiling' => $maxDrawdown <= 15.0,
            'pinned_finalist' => $pinnedFinalist,
            'canonical_engine' => $canonicalEngine,
            'version_lineage' => ! $versionMismatch,
        ];
        $status = $failureReasons !== [] ? 'failed' : (collect($checks)->every(fn (bool $passed): bool => $passed) ? 'satisfied' : 'collecting_evidence');
        $suppress = $status === 'failed';
        $result = $this->result($status, $checks, $counts, $suppress, $failureReasons);
        $session->update([
            'evidence_status' => $status,
            'evidence_checked_at' => now(),
            'evidence_failure_reason' => $failureReasons !== [] ? implode(', ', $failureReasons) : null,
            'entries_suppressed_at' => $suppress ? ($session->entries_suppressed_at ?? now()) : null,
            'entries_suppression_reason' => $suppress ? implode(', ', $failureReasons) : null,
            'evidence_summary_json' => $result,
        ]);

        return $result;
    }

    public function suppressesNewEntries(PaperSession $session): bool
    {
        return (bool) $this->evaluate($session)['suppress_new_entries'];
    }

    private function versionMismatchExists(PaperSession $session): bool
    {
        $cycleMismatch = PipelineCycle::query()->where('paper_session_id', $session->id)
            ->where(function ($query) use ($session): void {
                $query->whereNull('strategy_version_id')
                    ->orWhere('strategy_version_id', '!=', $session->strategy_version_id)
                    ->orWhereNull('universe_version_id')
                    ->orWhere('universe_version_id', '!=', $session->universe_version_id);
            })->exists();
        $evaluationMismatch = AssetEvaluation::query()->whereHas('cycle', fn ($query) => $query->where('paper_session_id', $session->id))
            ->where(function ($query) use ($session): void {
                $query->whereNull('strategy_version_id')
                    ->orWhere('strategy_version_id', '!=', $session->strategy_version_id)
                    ->orWhereNull('universe_version_id')
                    ->orWhere('universe_version_id', '!=', $session->universe_version_id);
            })->exists();

        return $cycleMismatch || $evaluationMismatch;
    }

    /** @param array<string, bool> $checks @param array<string, mixed> $counts @param array<int, string> $failureReasons @return array<string, mixed> */
    private function result(string $status, array $checks, array $counts, bool $suppress, array $failureReasons = []): array
    {
        return [
            'status' => $status,
            'evidence_level' => $status === 'satisfied' ? 'forward_paper' : 'locked_holdout',
            'checks' => $checks,
            'counts' => $counts,
            'failure_reasons' => $failureReasons,
            'suppress_new_entries' => $suppress,
            'live_eligible' => false,
        ];
    }
}
