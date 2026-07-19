<?php

namespace Tests\Feature\Trading;

use App\Data\Research\DecisionTrace;
use App\Enums\EvaluationResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionTraceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_v2_fixture_has_a_canonical_trace_for_every_asset(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('contracts/fixtures/evaluation-result-v2.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2.0', $fixture['schema_version']);
        foreach ($fixture['proposals'] as $proposal) {
            $trace = DecisionTrace::fromArray($proposal['decision_trace']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $trace->canonicalHash());
            $this->assertSame($fixture['strategy_family'], $trace->strategyFamily);
            $this->assertNotNull(EvaluationResolution::tryFrom($proposal['resolution']));
        }
    }

    public function test_only_actionable_resolution_permits_a_trade_decision(): void
    {
        foreach (EvaluationResolution::cases() as $resolution) {
            $this->assertSame($resolution === EvaluationResolution::ACTIONABLE, $resolution->permitsDecision());
        }
    }
}
