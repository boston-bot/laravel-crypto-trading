<?php

namespace App\Services\PaperTrading;

use App\Enums\HoldoutStatus;
use App\Models\BrokerAccount;
use App\Models\HoldoutInterval;
use App\Models\PaperLedgerEntry;
use App\Models\PaperSession;
use App\Models\StrategyVersion;
use App\Models\UniverseVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaperSessionService
{
    public function start(
        BrokerAccount $account,
        string $fundingMode,
        ?float $virtualCapital = null,
        ?int $strategyVersionId = null,
        ?int $universeVersionId = null,
        bool $evidenceEligible = false,
    ): PaperSession {
        if (! in_array($fundingMode, ['virtual', 'mirror'], true)) {
            throw new RuntimeException('Choose virtual capital or mirrored Coinbase equity.');
        }

        return DB::transaction(function () use ($account, $fundingMode, $virtualCapital, $strategyVersionId, $universeVersionId, $evidenceEligible): PaperSession {
            $lockedAccount = BrokerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (PaperSession::query()->where('broker_account_id', $account->id)->where('status', 'active')->lockForUpdate()->exists()) {
                throw new RuntimeException('This account already has an active paper session. End it before starting another.');
            }

            $openingCash = $fundingMode === 'mirror'
                ? $this->mirroredCapital($lockedAccount)
                : round((float) $virtualCapital, 8);
            if ($openingCash <= 0) {
                throw new RuntimeException('Paper starting capital must be greater than zero.');
            }

            [$strategyVersion, $universeVersion, $executionPolicyHash] = $evidenceEligible
                ? $this->resolveEvidencePins($strategyVersionId, $universeVersionId)
                : [
                    StrategyVersion::query()->where('status', 'active')->latest('activated_at')->first(),
                    UniverseVersion::query()->where('status', 'active')->latest('activated_at')->first(),
                    null,
                ];
            $session = PaperSession::query()->create([
                'broker_account_id' => $account->id,
                'strategy_version_id' => $strategyVersion?->id,
                'universe_version_id' => $universeVersion?->id,
                'funding_mode' => $fundingMode,
                'status' => 'active',
                'currency' => 'USD',
                'opening_cash' => $openingCash,
                'reserved_cash' => 0,
                'fee_scenario' => (string) config('trading.paper.fee_scenario', 'coinbase_taker_snapshot'),
                'slippage_scenario' => (string) config('trading.paper.slippage_scenario', 'observed_quote'),
                'valuation_at' => $lockedAccount->snapshot_at,
                'source_account_snapshot_id' => $fundingMode === 'mirror' ? $lockedAccount->id : null,
                'started_at' => now(),
                'evidence_eligible' => $evidenceEligible,
                'evidence_status' => $evidenceEligible ? 'collecting_evidence' : 'not_eligible',
                'execution_policy_hash' => $executionPolicyHash,
                'metadata_json' => [
                    'strategy_name' => config('trading.strategy_name'),
                    'source_equity' => $fundingMode === 'mirror' ? (float) $lockedAccount->equity : null,
                    'live_eligible' => false,
                ],
            ]);

            PaperLedgerEntry::query()->create([
                'paper_session_id' => $session->id,
                'entry_type' => 'opening_cash',
                'cash_delta' => $openingCash,
                'occurred_at' => now(),
                'context_json' => ['funding_mode' => $fundingMode, 'source_account_snapshot_id' => $session->source_account_snapshot_id],
            ]);

            return $session->fresh();
        });
    }

    /** @return array{StrategyVersion, UniverseVersion, string} */
    private function resolveEvidencePins(?int $strategyVersionId, ?int $universeVersionId): array
    {
        if (! in_array((string) config('research.engine.driver'), ['database', 'python'], true)) {
            throw new RuntimeException('Evidence-eligible paper sessions require the canonical Python/database strategy engine.');
        }
        if (($strategyVersionId === null) !== ($universeVersionId === null)) {
            throw new RuntimeException('Select both a frozen strategy version and its universe version.');
        }

        if ($strategyVersionId === null) {
            $passing = HoldoutInterval::query()->where('status', HoldoutStatus::Passed)->whereNotNull('authorized_strategy_version_id')->get();
            if ($passing->count() !== 1) {
                throw new RuntimeException('Select an exact finalist; automatic defaulting requires exactly one holdout-passing finalist.');
            }
            $strategyVersionId = (int) $passing->sole()->authorized_strategy_version_id;
        }
        $strategy = StrategyVersion::query()->with('candidate.experiment.universeVersion')->findOrFail($strategyVersionId);
        $experiment = $strategy->candidate?->experiment;
        $holdoutPassed = HoldoutInterval::query()
            ->where('strategy_experiment_id', $experiment?->id)
            ->where('authorized_strategy_version_id', $strategy->id)
            ->where('status', HoldoutStatus::Passed)
            ->exists();
        if ($experiment === null || ! $strategy->is_deployable || $strategy->version_role !== 'final' || ! $holdoutPassed) {
            throw new RuntimeException('The selected strategy is not the frozen holdout-passing finalist.');
        }
        $universe = $experiment->universeVersion;
        if ($universeVersionId !== null && $universe->id !== $universeVersionId) {
            throw new RuntimeException('The selected universe does not belong to the frozen finalist experiment.');
        }

        return [$strategy, $universe, (string) $experiment->execution_policy_hash];
    }

    public function activeFor(BrokerAccount|int $account): ?PaperSession
    {
        $accountId = $account instanceof BrokerAccount ? $account->id : $account;

        return PaperSession::query()->where('broker_account_id', $accountId)->where('status', 'active')->latest('id')->first();
    }

    public function availableCash(PaperSession $session): float
    {
        $cash = (float) PaperLedgerEntry::query()->where('paper_session_id', $session->id)->sum('cash_delta');

        return round($cash - (float) $session->reserved_cash, 8);
    }

    public function end(PaperSession $session): PaperSession
    {
        return DB::transaction(function () use ($session): PaperSession {
            $locked = PaperSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new RuntimeException('This paper session is already closed.');
            }
            if ($locked->positions()->where('quantity', '>', 0.000000000001)->exists()) {
                throw new RuntimeException('This session still has open positions. Liquidate them before ending the session.');
            }
            if ($locked->orders()->whereIn('status', ['draft', 'submitted', 'partially_filled', 'reconciliation_required'])->exists()) {
                throw new RuntimeException('This session still has a pending order. Reconcile or cancel it before ending the session.');
            }
            if ($locked->reservations()->where('status', 'reserved')->exists()) {
                throw new RuntimeException('This session still has reserved cash or quantity. Release it before ending the session.');
            }

            $locked->update(['status' => 'ended', 'ended_at' => now()]);

            return $locked->fresh();
        });
    }

    private function mirroredCapital(BrokerAccount $account): float
    {
        $maxAge = (int) config('operations.account_freshness_minutes', 10);
        if ($account->snapshot_at === null || $account->snapshot_at->lt(now()->subMinutes($maxAge))) {
            throw new RuntimeException('Coinbase account equity is stale. Sync the account before starting a mirrored session.');
        }
        $equity = round((float) $account->equity, 8);
        if ($equity <= 0 || strtoupper($account->currency) !== 'USD') {
            throw new RuntimeException('A fresh, positive USD Coinbase equity value is required for mirror funding.');
        }

        return $equity;
    }
}
