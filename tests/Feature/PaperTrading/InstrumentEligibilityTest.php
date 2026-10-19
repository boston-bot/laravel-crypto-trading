<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\InstrumentClassification;
use App\Models\PaperTrading\MarketInput;
use App\Models\User;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\MarketData\Sec\EdgarClassifications as Edgar;
use App\Services\PaperTrading\BrokerSimulationQuote;
use App\Services\PaperTrading\DecisionFailure;
use App\Services\PaperTrading\ExecutionDataReadiness;
use App\Services\PaperTrading\InstrumentEligibility;
use App\Services\PaperTrading\PaperInstrumentClassifications;
use App\Services\PaperTrading\PaperMarketInputs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EdgarFixture as X;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperActionFixture as A;
use Tests\TestCase;

class InstrumentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection] = E::seed();
        X::enable();
        config(['paper.instrument_classification_enabled' => true]);
    }

    private function classify(array $responses, array $symbols = []): InstrumentClassification
    {
        X::fake($responses);

        return app(PaperInstrumentClassifications::class)->collect($this->connection, $this->connection->user_id, $symbols, (string) Str::uuid())->sole();
    }

    /** A fresh instrument-bound broker type; quote-level claims are cleared so only the policy can supply them. */
    private function input(string $type = 'etf', array $changes = []): MarketInput
    {
        $entry = ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'source_as_of' => null, 'retrieved_at' => now()->toIso8601String(),
            'contract_fingerprint' => config('robinhood_mcp.operations.classification.fingerprint'),
            'fields' => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO', 'asset_type' => $type]];

        return A::input($this->connection, [], array_merge(['instrument' => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO'],
            'quote.eligible' => null, 'quote.asset_type' => null, 'quote.leveraged' => null, 'quote.inverse' => null, 'reference_reads.classification' => $entry], $changes));
    }

    private function policy(MarketInput $input, ?CarbonImmutable $cutoff = null): array
    {
        return app(InstrumentEligibility::class)->inspect($input, $cutoff ?? CarbonImmutable::now(), 60);
    }

    private function reason(MarketInput $input): ?string
    {
        return app(PaperMarketInputs::class)->executionReason($input, CarbonImmutable::now(), 60);
    }

    public function test_plain_etf_and_sole_operating_stock_become_eligible_only_with_matching_fresh_broker_type(): void
    {
        $record = $this->classify(X::plainFund());
        $this->assertSame(['complete', 'registered_fund_ncen'], [$record->status, $record->basis]);
        $etf = $this->input('etf');
        $policy = $this->policy($etf);
        $this->assertSame(['eligible', true, 'etf', false, false], [$policy['status'], $policy['eligible'], $policy['asset_type'], $policy['leveraged'], $policy['inverse']]);
        $this->assertSame('2026-05-31', $policy['report_period_end']);
        $this->assertNull($this->reason($etf));
        $this->assertSame('classification_sources_disagree', $this->policy($this->input('stock'))['reason']);
        $this->assertSame('broker_asset_type_unverified', $this->policy($this->input('etf', ['reference_reads.classification.fields.id' => 'other-instrument']))['reason']);
        $this->assertSame('instrument_eligibility_unverified', $this->reason($this->input('stock')));

        $stock = $this->classify(X::mappings([], [['DEMO', X::ISSUER_CIK]]) + [X::submissionsUrl(X::ISSUER_CIK) => Http::response(X::submissions(X::ISSUER_CIK, 'operating', ['DEMO'], []))]);
        $this->assertSame('operating_issuer_sole_listed_security', $stock->basis);
        $this->assertSame(['eligible', 'stock'], [$this->policy($this->input('stock'))['status'], $this->policy($this->input('stock'))['asset_type']]);
        $this->assertSame('classification_sources_disagree', $this->policy($this->input('etf'))['reason']);
        $this->travelTo(CarbonImmutable::now()->addDays(7)->addSecond());
        $this->assertSame('sec_classification_missing_or_stale', $this->policy($this->input('stock'))['reason']);
    }

    public function test_regulatory_leveraged_or_inverse_fund_is_ineligible_and_payload_claims_cannot_override(): void
    {
        $this->classify(X::plainFund([X::series(['Exchange-Traded Fund', Edgar::MULTIPLE_OR_INVERSE])]));
        $input = $this->input('etf', ['quote.eligible' => true, 'quote.asset_type' => 'etf', 'quote.leveraged' => false, 'quote.inverse' => false]);
        $policy = $this->policy($input);
        $this->assertSame(['ineligible', 'regulatory_leveraged_or_inverse_fund', false], [$policy['status'], $policy['reason'], $policy['eligible']]);
        $this->assertNull($policy['leveraged']); // Item C.3.c does not say which of leverage or inversion applies.
        $this->assertSame('instrument_ineligible', $this->reason($input));
        config(['paper.real_data_fills_enabled' => true]);
        try {
            BrokerSimulationQuote::fromStored($this->connection, $input->id, CarbonImmutable::now(), 60);
            $this->fail('Leveraged fund reached a paper quote.');
        } catch (DecisionFailure $failure) {
            $this->assertSame('instrument_ineligible', $failure->reason);
        }
        config(['paper.instrument_classification_enabled' => false]);
        $this->assertSame('classification_policy_disabled', $this->policy($input)['reason']);
    }

    public function test_bank_note_shelves_unmapped_and_late_or_foreign_evidence_stay_unknown(): void
    {
        $this->classify(X::mappings([], [['DEMO', X::BANK_CIK], ['BANK', X::BANK_CIK]]) + [X::submissionsUrl(X::BANK_CIK) => Http::response(X::submissions(X::BANK_CIK, 'operating', ['BANK', 'DEMO'], []))]);
        $this->assertSame('sec_issuer_has_multiple_listed_securities', $this->policy($this->input('stock'))['reason']);
        $input = $this->input('etf');
        $cutoff = CarbonImmutable::now();
        $this->travel(1)->seconds();
        $this->classify(X::plainFund());
        $this->assertSame('eligible', $this->policy($input)['status']);
        // Point in time: the newer fund evidence did not exist at the earlier cutoff, so the older result still governs.
        $this->assertSame('sec_issuer_has_multiple_listed_securities', $this->policy($input, $cutoff)['reason']);
        $this->assertSame('market_input_unverified', $this->policy($this->input('etf'), $cutoff)['reason']);
        $this->expectException(HttpException::class);
        app(PaperInstrumentClassifications::class)->collect($this->connection, $this->connection->user_id, ['OTHER'], (string) Str::uuid());
    }

    public function test_collection_is_gated_scoped_idempotent_immutable_encrypted_and_keeps_last_complete_evidence(): void
    {
        X::fake(X::plainFund());
        $service = app(PaperInstrumentClassifications::class);
        try {
            $service->collect($this->connection, $this->connection->user_id + 100, [], (string) Str::uuid());
            $this->fail('Foreign actor passed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $request = (string) Str::uuid();
        $first = $service->collect($this->connection, $this->connection->user_id, [], $request)->sole();
        Http::assertSentCount(4);
        $this->assertSame($first->id, $service->collect($this->connection, $this->connection->user_id, [], $request)->sole()->id);
        Http::assertSentCount(4);
        $raw = DB::table('mcp_paper_instrument_classifications')->where('id', $first->id)->value('payload');
        $this->assertStringNotContainsString('S000000001', $raw);
        $this->travel(1)->seconds();
        $failed = $this->classify([Edgar::FUND_TICKERS => Http::response('denied', 403)]);
        $this->assertSame(['incomplete', null, 'access_denied'], [$failed->status, $failed->basis, $failed->reason]);
        $this->assertSame($first->id, $this->policy($this->input('etf'))['classification_id']);
        config(['sec_edgar.enabled' => false]);
        try {
            $service->collect($this->connection, $this->connection->user_id, [], (string) Str::uuid());
            $this->fail('Disabled reads passed.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->expectException(\LogicException::class);
        $first->update(['basis' => 'operating_issuer_sole_listed_security']);
    }

    public function test_readiness_quote_and_commands_use_derived_classification_and_daily_refresh_is_idempotent(): void
    {
        config(['paper.corporate_actions_enabled' => true, 'paper.real_data_fills_enabled' => true]);
        X::fake(X::plainFund());
        $this->artisan('mcp:instrument-classification', ['--account' => $this->connection->id])->expectsOutputToContain('registered_fund_ncen')->assertSuccessful();
        $input = $this->input('etf');
        $report = app(ExecutionDataReadiness::class)->for($this->connection, CarbonImmutable::now());
        $checks = $report['symbols']['DEMO']['checks'];
        $this->assertSame([1, 1, 'data_ready_whole_shares'], [$report['ready_count'], $report['symbol_count'], $report['status']]);
        $this->assertSame('verified_not_leveraged_or_inverse_registered_fund_ncen', $checks['leverage_and_inverse']);
        $this->assertSame(['eligible', 'passed_whole_shares_only', 'no_reviewed_fractional_limit_contract'], [$checks['instrument_eligibility'], $checks['execution_gate'], $checks['fractional_limit']]);
        $this->artisan('mcp:paper-readiness', ['--account' => $this->connection->id])->assertSuccessful();
        $quote = BrokerSimulationQuote::fromStored($this->connection, $input->id, CarbonImmutable::now(), 60);
        $this->assertSame([true, 'etf', false, false], [$quote->data['eligible'], $quote->data['asset_type'], $quote->data['leveraged'], $quote->data['inverse']]);
        $this->assertSame('registered_fund_ncen', $quote->data['evidence']['classification_policy']['basis']);
        $this->actingAs(User::findOrFail($this->connection->user_id))->get('/mcp/dashboard/control')->assertOk()->assertSee('verified_not_leveraged_or_inverse_registered_fund_ncen');

        $this->artisan('mcp:instrument-classification', ['--scheduled' => true])->expectsOutputToContain('Recorded classifications: 1')->assertSuccessful();
        $this->artisan('mcp:instrument-classification', ['--scheduled' => true])->assertSuccessful();
        $this->assertSame(2, InstrumentClassification::count());
        Http::assertSentCount(8);
        config(['sec_edgar.enabled' => false]);
        $this->artisan('mcp:instrument-classification', ['--account' => $this->connection->id])->assertFailed();
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
    }
}
