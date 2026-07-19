<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\AssetEvaluation;
use App\Models\BrokerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyTransparencyEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_and_detail_endpoints_return_three_stable_decision_layers(): void
    {
        $account = BrokerAccount::query()->create([
            'broker' => 'coinbase', 'external_account_id' => 'transparency', 'currency' => 'USD',
            'buying_power' => 0, 'cash_balance' => 0, 'equity' => 0, 'status' => 'active', 'snapshot_at' => now(),
        ]);
        $asset = Asset::query()->create([
            'broker' => 'coinbase', 'symbol' => 'BTC', 'asset_type' => 'crypto', 'is_tradable' => true, 'is_enabled' => true,
        ]);
        $evaluation = AssetEvaluation::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'mode' => 'paper',
            'evaluation_kind' => 'trading',
            'logical_bar_close' => now()->startOfHour(),
            'as_of' => now(),
            'eligible' => true,
            'actionable' => false,
            'action' => 'HOLD',
            'evaluation_resolution' => 'blocked_by_strategy',
            'strategy_family' => 'trend_rotation',
            'strategy_definition_version' => '2.0.0',
            'decision_trace_hash' => hash('sha256', 'trace'),
            'evidence_hash' => hash('sha256', 'evidence'),
            'parameter_hash' => hash('sha256', 'parameters'),
            'score' => 0.25,
            'calibrated_probability' => 0.51,
            'expected_value_bps' => 4,
            'primary_explanation' => 'BTC remains in cash because net edge is below the entry threshold.',
            'reason_codes_json' => ['insufficient_net_edge'],
            'decision_trace_json' => [
                'rank' => 1,
                'portfolio_state' => ['regime' => 'risk_on', 'capacity' => 'available'],
                'rule_checklist' => [['rule' => 'minimum_net_edge', 'passed' => false, 'observed' => 4, 'required' => 12]],
                'factor_contributions' => ['trend' => 18, 'momentum' => -6],
                'gross_edge_bps' => 12,
                'cost_estimate_bps' => 8,
                'net_edge_bps' => 4,
                'counterfactual' => 'Enter when net edge reaches 12 bps.',
            ],
            'thresholds_json' => ['entry_probability' => 0.52],
        ]);

        $this->getJson('/api/ops/v1/strategy-decisions/latest?asset_id='.$asset->id)
            ->assertOk()
            ->assertJsonPath('data.meta.measurement_state', 'measured')
            ->assertJsonPath('data.decision.immediate.action', 'HOLD')
            ->assertJsonPath('data.decision.mechanics.rules.0.passed', false)
            ->assertJsonPath('data.decision.mechanics.counterfactual', 'Enter when net edge reaches 12 bps.')
            ->assertJsonStructure(['data' => ['decision' => ['immediate', 'mechanics', 'audit', 'performance']]]);

        $this->getJson('/api/ops/v1/strategy-decisions/'.$evaluation->id)
            ->assertOk()
            ->assertJsonPath('data.decision.id', $evaluation->id)
            ->assertJsonPath('data.decision.audit.lineage.decision_trace_hash', hash('sha256', 'trace'));
    }

    public function test_empty_projection_is_explicitly_not_measured_and_non_loopback_is_rejected(): void
    {
        $this->getJson('/api/ops/v1/strategy-decisions/latest')
            ->assertOk()
            ->assertJsonPath('data.meta.measurement_state', 'not_measured')
            ->assertJsonPath('data.decision', null);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('/api/ops/v1/strategy-decisions/latest')
            ->assertForbidden();
    }
}
