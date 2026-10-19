<?php

namespace Tests\Feature\PaperTrading;

use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\MarketData\Alpaca\CorporateActions;
use App\Services\PaperTrading\CorporateActionCoverage;
use App\Services\PaperTrading\ExecutionDataReadiness;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Support\Mcp\ExactJsonNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperMarketGateway;
use Tests\TestCase;

class AlpacaCorporateActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        config(['alpaca_data.enabled' => true, 'alpaca_data.key' => 'synthetic-key', 'alpaca_data.secret' => 'synthetic-secret']);
    }

    private function fake(array $responses): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake($responses);
    }

    private function event(string $id = 'test-action'): array
    {
        return ['id' => $id, 'symbol' => 'DEMO', 'process_date' => '2026-10-19', 'ex_date' => '2026-10-19',
            'record_date' => '2026-10-19', 'payable_date' => '2026-10-30', 'rate' => '0.123456789012345678'];
    }

    private function read(): array
    {
        return app(CorporateActions::class)->read('DEMO', '2026-10-01', '2026-11-01');
    }

    public function test_lossless_json_preserves_numeric_tokens_escaped_strings_and_large_numbers(): void
    {
        $data = ExactJsonNumbers::decode('{"amount":0.123456789012345678,"ratio":1e-8,"large":99999999999999999999,"bool":true,"none":null,"text":"rate 123 \\"quoted\\""}');
        $this->assertSame('0.123456789012345678', $data['amount']);
        $this->assertSame('1e-8', $data['ratio']);
        $this->assertSame('99999999999999999999', $data['large']);
        $this->assertTrue($data['bool']);
        $this->assertNull($data['none']);
        $this->assertSame('rate 123 "quoted"', $data['text']);
        $this->expectException(\JsonException::class);
        ExactJsonNumbers::decode('{"rate":01}');
    }

    public function test_all_quality_pagination_exact_rates_and_early_records_are_preserved_without_clearance(): void
    {
        $this->fake([CorporateActions::URL.'*' => Http::sequence()
            ->push('{"corporate_actions":{"cash_dividends":[{"id":"first","symbol":"DEMO","process_date":"2026-10-19","ex_date":"2026-10-20","rate":0.123456789012345678}]},"next_page_token":"next"}')
            ->push(['corporate_actions' => ['forward_splits' => [['id' => 'second', 'symbol' => 'DEMO', 'process_date' => '2026-10-19', 'new_rate' => '2', 'old_rate' => '1']]], 'next_page_token' => null])]);
        $read = $this->read();
        $this->assertTrue($read['complete']);
        $this->assertSame(2, $read['pages']);
        $this->assertSame('0.123456789012345678', $read['events'][0]['rates']['rate']);
        $this->assertSame(['ex_date'], $read['events'][1]['missing_fields']);
        $this->assertNull($read['source_as_of']);
        $this->assertNull($read['corporate_actions_clear']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('APCA-API-KEY-ID', 'synthetic-key') &&
            $r->hasHeader('APCA-API-SECRET-KEY', 'synthetic-secret') && $r['data_quality'] === 'all' && ! isset($r['types']) && ($r['page_token'] ?? null) === 'next');
        $this->assertStringNotContainsString('synthetic-secret', json_encode($read));
    }

    public function test_empty_pages_are_not_positive_clearance_and_process_date_is_not_effective_date(): void
    {
        $this->fake([CorporateActions::URL.'*' => Http::response(['corporate_actions' => (object) [], 'next_page_token' => null])]);
        $read = $this->read();
        $this->assertTrue($read['complete']);
        $this->assertSame('process_date', $read['date_basis']);
        $this->assertTrue($read['publication_delay_possible']);
        $payload = ['quote' => ['symbol' => 'DEMO'], 'corporate_action_reads' => ['alpaca' => $read]];
        $coverage = app(CorporateActionCoverage::class)->inspect($payload, CarbonImmutable::now(), 60);
        $this->assertSame('process_window_complete_no_events_returned', $coverage['status']);
        $this->assertNull($coverage['corporate_actions_clear']);
    }

    public function test_failure_after_page_retains_partial_evidence_and_redacts_provider_errors(): void
    {
        $this->fake([CorporateActions::URL.'*' => Http::sequence()
            ->push(['corporate_actions' => ['cash_dividends' => [$this->event()]], 'next_page_token' => 'next'])
            ->push(['message' => 'synthetic-secret should never be retained'], 403)]);
        $read = $this->read();
        $this->assertFalse($read['complete']);
        $this->assertSame('access_denied', $read['error']);
        $this->assertCount(1, $read['events']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode($read));
        $this->assertNull($read['corporate_actions_clear']);
    }

    public function test_disabled_missing_credentials_and_invalid_queries_never_send_requests(): void
    {
        config(['alpaca_data.enabled' => false]);
        $this->assertSame('disabled', $this->read()['error']);
        config(['alpaca_data.enabled' => true, 'alpaca_data.secret' => '']);
        $this->assertSame('credentials_missing', $this->read()['error']);
        config(['alpaca_data.secret' => 'synthetic-secret']);
        $this->assertFalse(app(CorporateActions::class)->read('DEMO', '2026-02-30', '2026-11-01')['complete']);
        $this->assertFalse(app(CorporateActions::class)->read('DEMO?key=bad', '2026-10-01', '2026-11-01')['complete']);
        Http::assertNothingSent();
    }

    public function test_wrong_symbols_dates_unknown_groups_duplicates_and_repeated_cursors_fail_closed(): void
    {
        foreach ([['cash_dividends' => [array_replace($this->event(), ['symbol' => 'OTHER'])]],
            ['cash_dividends' => [array_replace($this->event(), ['process_date' => '2026-12-01'])]],
            ['cash_dividends' => [array_replace($this->event(), ['rate' => '-1'])]],
            ['cash_dividends' => [array_replace($this->event(), ['ex_date' => '2026-02-30'])]],
            ['new_unknown_group' => [$this->event()]], ['cash_dividends' => [$this->event(), $this->event()]]] as $groups) {
            $this->fake([CorporateActions::URL.'*' => Http::response(['corporate_actions' => $groups, 'next_page_token' => null])]);
            $read = $this->read();
            $this->assertFalse($read['complete']);
            $this->assertSame('invalid_or_unreviewed_response', $read['error']);
        }
        $this->fake([CorporateActions::URL.'*' => Http::sequence()
            ->push(['corporate_actions' => (object) [], 'next_page_token' => 'same'])
            ->push(['corporate_actions' => (object) [], 'next_page_token' => 'same'])]);
        $this->assertSame('invalid_or_unreviewed_response', $this->read()['error']);
    }

    public function test_limits_rate_limits_and_transport_failure_keep_coverage_unknown(): void
    {
        config(['alpaca_data.max_pages' => 1]);
        $this->fake([CorporateActions::URL.'*' => Http::response(['corporate_actions' => (object) [], 'next_page_token' => 'next'])]);
        $this->assertSame('page_limit', $this->read()['error']);
        $this->fake([CorporateActions::URL.'*' => Http::response('key=synthetic-secret', 429)]);
        $this->assertSame('rate_limited', $this->read()['error']);
        $this->fake([CorporateActions::URL.'*' => Http::failedConnection()]);
        $this->assertSame('transport_unavailable', $this->read()['error']);
    }

    public function test_scoped_collection_encrypts_actions_and_reports_coverage_without_inventing_eligibility(): void
    {
        [$connection] = E::seed();
        config(['paper.market_inputs_enabled' => true]);
        $this->fake([CorporateActions::URL.'*' => Http::response(['corporate_actions' => ['cash_dividends' => [$this->event()]], 'next_page_token' => null])]);
        $gateway = new PaperMarketGateway;
        $gateway->respond = function ($connection, $operation) {
            $fields = $operation === Operation::Quotes ? ['symbol' => 'DEMO', 'bid' => '99', 'ask' => '100', 'bid_as_of' => now()->toIso8601String(), 'ask_as_of' => now()->toIso8601String()] : ['symbol' => 'DEMO', 'id' => 'synthetic-instrument'];

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [BrokerRecord::from($operation, $fields, null)], true);
        };
        $this->app->instance(BrokerGateway::class, $gateway);
        $key = (string) Str::uuid();
        $input = app(PaperMarketInputs::class)->collect($connection, $connection->user_id, 'DEMO', $key);
        $this->assertSame('0.123456789012345678', $input->payload['corporate_action_reads']['alpaca']['events'][0]['rates']['rate']);
        $this->assertStringNotContainsString('test-action', DB::table('mcp_paper_market_inputs')->where('id', $input->id)->value('payload'));
        $this->assertNull($input->payload['quote']['corporate_actions_clear']);
        $this->assertNull($input->payload['quote']['eligible']);
        $readiness = app(ExecutionDataReadiness::class)->inspect($input, CarbonImmutable::now(), 60);
        $this->assertSame('process_window_complete_events_require_review', $readiness['checks']['alpaca_actions']);
        $this->assertSame('1', $readiness['checks']['alpaca_event_count']);
        $this->assertSame($input->id, app(PaperMarketInputs::class)->collect($connection, $connection->user_id, 'DEMO', $key)->id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->artisan('mcp:corporate-actions', ['--account' => $connection->id, '--symbol' => 'DEMO', '--start' => '2026-10-01', '--end' => '2026-11-01'])
            ->expectsOutputToContain('"event_count":1')->assertSuccessful();
        $this->artisan('mcp:corporate-actions')->assertFailed();
    }
}
