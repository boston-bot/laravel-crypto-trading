<?php

namespace Tests\Feature\PaperTrading;

use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\PaperTrading\ExecutionDataReadiness;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Services\PaperTrading\ReferenceDataCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperMarketGateway;
use Tests\TestCase;

class ReferenceDataCoverageTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection] = E::seed();
    }

    private function payload(): array
    {
        $entry = ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'source_as_of' => null, 'retrieved_at' => now()->toIso8601String()];

        return ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'instrument' => ['id' => 'synthetic-instrument'], 'quote' => ['symbol' => 'DEMO'],
            'reference_reads' => ['classification' => $entry + ['contract_fingerprint' => config('robinhood_mcp.operations.classification.fingerprint'),
                'fields' => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO', 'asset_type' => 'etf']],
                'dividends' => $entry + ['contract_fingerprint' => config('robinhood_mcp.operations.dividends.fingerprint'),
                    'fields' => ['symbol' => 'DEMO', 'market_date' => '2026-10-19', 'dividend_per_share' => '0.25',
                        'ex_dividend_date' => '2026-10-20', 'record_date' => '2026-10-20', 'payable_date' => '2026-10-30', 'dividend_schedule_status' => 'observed_cycle_unverified']]]];
    }

    public function test_observed_type_and_dividend_subset_do_not_imply_leverage_or_complete_action_clearance(): void
    {
        $payload = $this->payload();
        $result = app(ReferenceDataCoverage::class)->inspect($payload, CarbonImmutable::now(), 20);
        $this->assertSame('etf', $result['asset_type']);
        $this->assertSame('observed_etf_source_time_unknown', $result['classification_status']);
        $this->assertSame('dividend_subset_only_no_split_merger_coverage', $result['corporate_action_status']);
        $this->assertSame('observed_cycle_unverified', $result['dividend_status']);
        foreach (['leveraged', 'inverse', 'corporate_actions_clear'] as $key) {
            $this->assertNull($result[$key]);
        }
        $payload['quote'] += ['eligible' => true, 'leveraged' => false, 'inverse' => false, 'corporate_actions_clear' => true];
        $payload['reference_reads']['dividends']['fields']['corporate_actions_clear'] = true;
        $this->assertNull(app(ReferenceDataCoverage::class)->inspect($payload, CarbonImmutable::now(), 20)['corporate_actions_clear']);
    }

    public function test_type_identity_contract_and_freshness_are_required_and_unknown_dividends_stay_unknown(): void
    {
        foreach ([['reference_reads.classification.fields.id', 'wrong'], ['reference_reads.classification.fields.symbol', 'OTHER'],
            ['reference_reads.classification.contract_fingerprint', str_repeat('0', 64)], ['reference_reads.classification.complete', false],
            ['reference_reads.classification.retrieved_at', now()->subSeconds(21)->toIso8601String()],
            ['reference_reads.classification.retrieved_at', now()->addSecond()->toIso8601String()],
            ['reference_reads.classification.fields.asset_type', 'EQUITY']] as [$path, $value]) {
            $payload = $this->payload();
            data_set($payload, $path, $value);
            $this->assertNull(app(ReferenceDataCoverage::class)->inspect($payload, CarbonImmutable::now(), 20)['asset_type'], $path);
        }
        $payload = $this->payload();
        $payload['reference_reads']['dividends']['fields']['dividend_schedule_status'] = 'inconsistent_dates';
        $result = app(ReferenceDataCoverage::class)->inspect($payload, CarbonImmutable::now(), 20);
        $this->assertSame('inconsistent_dates', $result['dividend_status']);
        $this->assertNull($result['corporate_actions_clear']);
        $payload['reference_reads']['dividends']['complete'] = false;
        $this->assertSame('no_complete_corporate_action_coverage', app(ReferenceDataCoverage::class)->inspect($payload, CarbonImmutable::now(), 20)['corporate_action_status']);
    }

    public function test_collection_encrypts_reference_subsets_and_readiness_exposes_remaining_gaps(): void
    {
        config(['paper.market_inputs_enabled' => true, 'robinhood_mcp.reference_data_enabled' => true]);
        $gateway = new PaperMarketGateway;
        $gateway->respond = function ($connection, $operation) {
            $fields = match ($operation) {
                Operation::Quotes => ['symbol' => 'DEMO', 'bid' => '99', 'ask' => '100', 'bid_as_of' => now()->toIso8601String(), 'ask_as_of' => now()->toIso8601String()],
                Operation::Instruments => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO'],
                Operation::Classification, Operation::Dividends => $this->payload()['reference_reads'][$operation->value]['fields'],
                default => throw new \RuntimeException('Unexpected broker operation.'),
            };

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [BrokerRecord::from($operation, $fields, null)], true);
        };
        $this->app->instance(BrokerGateway::class, $gateway);
        $input = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $this->assertSame('etf', $input->payload['quote']['asset_type']);
        $this->assertNull($input->payload['quote']['eligible']);
        $this->assertNull($input->payload['quote']['leveraged']);
        $this->assertNull($input->payload['quote']['inverse']);
        $this->assertNull($input->payload['quote']['corporate_actions_clear']);
        $this->assertStringNotContainsString('synthetic-instrument', DB::table('mcp_paper_market_inputs')->where('id', $input->id)->value('payload'));
        $report = app(ExecutionDataReadiness::class)->inspect($input, CarbonImmutable::now(), 20);
        $this->assertSame('observed_etf_source_time_unknown', $report['checks']['classification']);
        $this->assertSame('dividend_subset_only_no_split_merger_coverage', $report['checks']['corporate_actions']);
        $this->assertSame('observed_cycle_unverified', $report['checks']['dividend_subset']);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame('instrument_eligibility_unverified', app(PaperMarketInputs::class)->executionReason($input, CarbonImmutable::now(), 20));
        $this->assertSame(0, app(ExecutionDataReadiness::class)->for($this->connection, CarbonImmutable::now())['ready_count']);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        Http::assertNothingSent();
    }

    public function test_reference_failure_is_independent_of_base_quote_completeness_and_cannot_be_imported_from_text(): void
    {
        config(['paper.market_inputs_enabled' => true, 'robinhood_mcp.reference_data_enabled' => true]);
        $gateway = new PaperMarketGateway;
        $gateway->respond = function ($connection, $operation) {
            if (in_array($operation, [Operation::Classification, Operation::Dividends], true)) {
                throw new ReadFailure(ReadError::Timeout);
            }
            $fields = $operation === Operation::Quotes ? ['symbol' => 'DEMO', 'bid' => '99', 'ask' => '100'] : ['symbol' => 'DEMO', 'id' => 'synthetic-instrument'];

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [BrokerRecord::from($operation, $fields, null)], true);
        };
        $this->app->instance(BrokerGateway::class, $gateway);
        $input = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $this->assertTrue($input->payload['complete']);
        $this->assertNull($input->payload['quote']['asset_type']);
        $this->assertNull($input->payload['quote']['corporate_actions_clear']);
        $this->assertSame('timeout', $input->payload['reference_reads']['dividends']['error']);
    }
}
