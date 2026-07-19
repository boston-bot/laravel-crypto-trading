<?php

namespace Tests\Feature\Trading;

use App\Enums\HoldoutStatus;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\HoldoutInterval;
use App\Models\MarketRegimeSnapshot;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperSession;
use App\Models\PipelineCycle;
use App\Models\StrategyExperiment;
use App\Models\StrategyExperimentCandidate;
use App\Models\StrategyVersion;
use App\Models\TradeAttribution;
use App\Models\UniverseVersion;
use App\Services\PaperTrading\PaperSessionService;
use App\Services\Research\PaperEvidenceGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PaperEvidenceGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_session_defaults_only_to_the_single_holdout_passing_finalist(): void
    {
        config()->set('research.engine.driver', 'database');
        [$strategy, $universe] = $this->passingFinalist('single');
        $account = $this->account();

        $session = app(PaperSessionService::class)->start(
            $account,
            'virtual',
            10_000,
            evidenceEligible: true,
        );

        $this->assertSame($strategy->id, $session->strategy_version_id);
        $this->assertSame($universe->id, $session->universe_version_id);
        $this->assertTrue($session->evidence_eligible);
        $this->assertSame('collecting_evidence', $session->evidence_status);
    }

    public function test_evidence_session_rejects_unfrozen_or_ambiguous_selection(): void
    {
        config()->set('research.engine.driver', 'database');
        $account = $this->account();
        $this->expectException(RuntimeException::class);
        app(PaperSessionService::class)->start($account, 'virtual', 10_000, evidenceEligible: true);
    }

    public function test_gate_is_satisfied_only_after_duration_trades_assets_regimes_and_reconciliation(): void
    {
        config()->set('research.engine.driver', 'database');
        [$strategy, $universe] = $this->passingFinalist('satisfied');
        $session = $this->paperSession($strategy, $universe, now()->subDays(91));
        $assets = collect(['BTC', 'ETH', 'SOL'])->map(fn (string $symbol): Asset => Asset::query()->create([
            'broker' => 'coinbase', 'symbol' => $symbol, 'asset_type' => 'crypto',
            'is_tradable' => true, 'is_enabled' => true,
        ]));
        foreach (range(0, 14) as $index) {
            TradeAttribution::query()->create([
                'paper_session_id' => $session->id,
                'asset_id' => $assets[$index % 3]->id,
                'realized_pnl' => 10,
                'realized_return_pct' => 1,
                'attributed_at' => now()->subDays(60)->addHours($index),
                'attribution_json' => ['round_trip_closed' => true],
            ]);
        }
        foreach (['risk_on', 'risk_off'] as $regime) {
            foreach (range(1, 20) as $bar) {
                MarketRegimeSnapshot::query()->create([
                    'snapshot_time' => now()->subDays(40)->addHours($bar * 4 + ($regime === 'risk_off' ? 100 : 0)),
                    'regime' => $regime,
                    'confidence' => 0.8,
                    'source' => 'canonical-python-v2',
                ]);
            }
        }
        PaperPortfolioSnapshot::query()->create([
            'broker_account_id' => $session->broker_account_id,
            'paper_session_id' => $session->id,
            'snapshot_time' => now(),
            'equity' => 8_500,
            'cash' => 8_500,
            'drawdown_pct' => 15.0,
        ]);

        $gate = app(PaperEvidenceGateService::class)->evaluate($session);

        $this->assertSame('satisfied', $gate['status']);
        $this->assertFalse($gate['live_eligible']);
        $this->assertFalse($gate['suppress_new_entries']);
    }

    public function test_drawdown_or_version_mismatch_fails_immediately_and_suppresses_new_entries(): void
    {
        config()->set('research.engine.driver', 'database');
        [$strategy, $universe] = $this->passingFinalist('failed');
        $session = $this->paperSession($strategy, $universe, now()->subDays(10));
        PaperPortfolioSnapshot::query()->create([
            'broker_account_id' => $session->broker_account_id,
            'paper_session_id' => $session->id,
            'snapshot_time' => now(),
            'equity' => 8_499,
            'cash' => 8_499,
            'drawdown_pct' => 15.01,
        ]);
        PipelineCycle::query()->create([
            'id' => (string) Str::uuid(),
            'broker_account_id' => $session->broker_account_id,
            'paper_session_id' => $session->id,
            'strategy_version_id' => null,
            'universe_version_id' => $universe->id,
            'mode' => 'paper',
            'trigger' => 'test',
            'evaluation_kind' => 'trading',
            'status' => 'completed',
            'cycle_key' => hash('sha256', 'mismatch'),
            'as_of' => now(),
        ]);

        $gate = app(PaperEvidenceGateService::class)->evaluate($session);

        $this->assertSame('failed', $gate['status']);
        $this->assertTrue($gate['suppress_new_entries']);
        $this->assertContains('drawdown_ceiling_breached', $gate['failure_reasons']);
        $this->assertContains('pinned_version_mismatch', $gate['failure_reasons']);
    }

    /** @return array{StrategyVersion, UniverseVersion} */
    private function passingFinalist(string $suffix): array
    {
        $universe = UniverseVersion::query()->create([
            'name' => 'paper-'.$suffix,
            'version' => 'v1',
            'status' => 'research',
            'content_hash' => hash('sha256', 'universe-'.$suffix),
            'symbols_json' => ['BTC', 'ETH', 'SOL'],
            'rules_json' => ['quote' => 'USD'],
        ]);
        $experiment = StrategyExperiment::query()->create([
            'schema_version' => '1.0',
            'name' => 'paper-'.$suffix,
            'status' => 'completed',
            'universe_version_id' => $universe->id,
            'objective' => 'maximize_compounded_net_oos_return',
            'constraints_json' => ['maximum_drawdown_pct' => 15],
            'search_budget' => 4,
            'seeds_json' => [7],
            'regimes_json' => ['risk_on', 'risk_off'],
            'cost_policy_json' => ['version' => 'v1'],
            'attribution_policy_json' => ['version' => 'v1'],
            'benchmark_policy_json' => ['version' => 'v1'],
            'execution_policy_version' => 'coinbase-ioc-v1',
            'execution_policy_hash' => hash('sha256', 'execution-'.$suffix),
            'development_start' => now()->subYears(3),
            'development_end' => now()->subYear(),
            'holdout_start' => now()->subYear(),
            'holdout_end' => now(),
            'content_hash' => hash('sha256', 'experiment-'.$suffix),
        ]);
        $candidate = StrategyExperimentCandidate::query()->create([
            'strategy_experiment_id' => $experiment->id,
            'candidate_key' => '01-trend',
            'family' => 'trend_rotation',
            'search_order' => 1,
            'search_budget' => 4,
            'status' => 'completed',
            'specification_json' => ['family' => 'trend_rotation'],
            'content_hash' => hash('sha256', 'candidate-'.$suffix),
        ]);
        $strategy = StrategyVersion::query()->create([
            'name' => 'paper-'.$suffix,
            'version' => 'final-v1',
            'schema_version' => '2.0',
            'engine_version' => (string) config('research.engine.version'),
            'status' => 'frozen',
            'content_hash' => hash('sha256', 'strategy-'.$suffix),
            'definition_json' => ['family' => 'trend_rotation'],
            'strategy_experiment_candidate_id' => $candidate->id,
            'version_role' => 'final',
            'calibration_start' => $experiment->development_start,
            'calibration_end' => $experiment->development_end,
            'is_deployable' => true,
        ]);
        HoldoutInterval::query()->create([
            'strategy_experiment_id' => $experiment->id,
            'holdout_start' => $experiment->holdout_start,
            'holdout_end' => $experiment->holdout_end,
            'status' => HoldoutStatus::Passed,
            'content_hash' => hash('sha256', 'holdout-'.$suffix),
            'authorized_strategy_version_id' => $strategy->id,
            'candidate_hash' => $strategy->content_hash,
            'terminal_at' => now(),
            'terminal_result_json' => ['status' => 'passed'],
        ]);

        return [$strategy, $universe];
    }

    private function paperSession(StrategyVersion $strategy, UniverseVersion $universe, \DateTimeInterface $startedAt): PaperSession
    {
        return PaperSession::query()->create([
            'broker_account_id' => $this->account()->id,
            'strategy_version_id' => $strategy->id,
            'universe_version_id' => $universe->id,
            'funding_mode' => 'virtual',
            'status' => 'active',
            'currency' => 'USD',
            'opening_cash' => 10_000,
            'reserved_cash' => 0,
            'fee_scenario' => 'test',
            'slippage_scenario' => 'test',
            'valuation_at' => $startedAt,
            'started_at' => $startedAt,
            'evidence_eligible' => true,
            'evidence_status' => 'collecting_evidence',
            'execution_policy_hash' => $strategy->candidate->experiment->execution_policy_hash,
            'metadata_json' => ['reconciliation_passed' => true, 'manifest_reconciled' => true, 'live_eligible' => false],
        ]);
    }

    private function account(): BrokerAccount
    {
        return BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => uniqid('paper-evidence-', true),
            'currency' => 'USD',
            'buying_power' => 0,
            'cash_balance' => 0,
            'equity' => 0,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);
    }
}
