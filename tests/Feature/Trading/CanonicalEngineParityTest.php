<?php

namespace Tests\Feature\Trading;

use App\Data\Research\DecisionTrace;
use App\Models\Asset;
use App\Models\EngineJob;
use App\Models\EngineResult;
use App\Services\Execution\OrderIntentValidator;
use App\Services\Research\DatabaseStrategyEngineAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalEngineParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_transport_preserves_golden_canonical_decision_and_order_intent(): void
    {
        $evaluation = $this->fixture('evaluation-result-v2.json');
        $context = $this->fixture('portfolio-context-paper-v1.json');
        $intent = $this->fixture('order-intent-v1.json');
        $jobId = (string) Str::uuid();
        $pins = [
            'strategy' => ['id' => 11, 'version' => '2.0.0', 'definition_hash' => $evaluation['proposals'][0]['decision_trace']['parameter_hash']],
            'universe' => ['id' => 5, 'assets' => [['id' => 1, 'symbol' => 'BTC']]],
            'logical_bar_close' => '2026-07-19T16:00:00+00:00',
            'evidence_cutoff' => '2026-07-19T16:00:00+00:00',
            'portfolio_context' => $context,
            'execution_policy' => ['version' => 'coinbase-ioc-v1', 'venue' => 'coinbase', 'time_in_force' => 'IOC'],
        ];
        $job = EngineJob::query()->create([
            'id' => $jobId,
            'schema_version' => '2.0',
            'kind' => 'evaluation',
            'idempotency_key' => hash('sha256', json_encode($pins, JSON_THROW_ON_ERROR)),
            'as_of' => $pins['logical_bar_close'],
            'payload_json' => $pins,
            'status' => 'succeeded',
        ]);
        EngineResult::query()->create([
            'engine_job_id' => $job->id,
            'result_kind' => 'evaluation',
            'engine_version' => $evaluation['engine_version'],
            'schema_version' => $evaluation['schema_version'],
            'as_of' => $evaluation['as_of'],
            'valid_until' => $evaluation['valid_until'],
            'manifest_hash' => $evaluation['manifest_hash'],
            'payload_json' => $evaluation,
        ]);

        $transported = app(DatabaseStrategyEngineAdapter::class)->result($job);
        $trace = DecisionTrace::fromArray($transported->proposals[0]['decision_trace']);
        $fixtureTrace = DecisionTrace::fromArray($evaluation['proposals'][0]['decision_trace']);

        $this->assertTrue($transported->isPromotable());
        $this->assertSame($fixtureTrace->canonicalHash(), $trace->canonicalHash());
        $this->assertEquals($evaluation['proposals'], $transported->proposals);
        $this->assertSame($pins, $job->fresh()->payload_json);

        Asset::query()->create([
            'id' => 1, 'broker' => 'coinbase', 'symbol' => 'BTC', 'asset_type' => 'crypto',
            'is_tradable' => true, 'is_enabled' => true, 'min_order_notional' => 1, 'quantity_precision' => 8,
        ]);
        $validatedIntent = app(OrderIntentValidator::class)->validate($intent, Asset::query()->findOrFail(1));
        $this->assertSame($intent['intent_hash'], $validatedIntent['intent_hash']);
        $this->assertSame($context['context_hash'], $validatedIntent['portfolio_context_hash']);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('contracts/fixtures/'.$name)), true, flags: JSON_THROW_ON_ERROR);
    }
}
