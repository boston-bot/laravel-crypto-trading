<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\EligibilityAssessment;
use App\Models\PaperTrading\EligibilityPolicy;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\RobinhoodWatchlists;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\MarketData\Alpaca\DailyBars;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\IncidentRecorder;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\BrokerSimulationQuote;
use App\Services\PaperTrading\ExecutionDataReadiness;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\PaperInstrumentClassifications;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Services\PaperTrading\PaperMarketReferences;
use App\Services\PaperTrading\PaperOrderHandoff;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Services\PaperTrading\RuleEligibility;
use App\Services\PaperTrading\RuleEligibilityPolicies;
use App\Services\PaperTrading\StrategyWatchlists;
use App\Services\PaperTrading\SymbolUniverse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\EdgarFixture as X;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\ExternalOrderGateway;
use Tests\Support\PaperActionFixture as A;
use Tests\Support\PaperMarketGateway;
use Tests\Support\SimulationFixture as F;
use Tests\Support\StrategyWatchlistGateway;
use Tests\TestCase;

class RuleEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    private $policy;

    private $execution;

    private StrategyWatchlistGateway $gateway;

    private StrategyWatchlists $watchlists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $this->portfolio, $this->policy, $this->execution] = E::seed();
        config(['paper.rule_eligibility_enabled' => true, 'paper.watchlist_intake_enabled' => true, 'robinhood_mcp.watchlists_enabled' => true,
            'paper.instrument_classification_enabled' => true, 'paper.corporate_actions_enabled' => true]);
        $this->gateway = new StrategyWatchlistGateway;
        $this->gateway->members = [['id' => 'fixture-demo', 'object_type' => 'instrument', 'symbol' => 'DEMO'],
            ['id' => 'fixture-new', 'object_type' => 'instrument', 'symbol' => 'NEW']];
        $this->watchlists = new StrategyWatchlists(new RobinhoodWatchlists($this->gateway), app(IncidentRecorder::class));
        $this->watchlists->configure($this->connection, $this->connection->user_id, 0, (string) Str::uuid(), 'Strategy Candidates');
        $this->watchlists->collect($this->connection, $this->connection->user_id, (string) Str::uuid());
    }

    private function apply(array $parameters = []): EligibilityPolicy
    {
        return app(RuleEligibilityPolicies::class)->apply($this->connection, $this->connection->user_id,
            RuleEligibilityPolicies::selected($this->connection)?->version ?? 0, (string) Str::uuid(), array_replace(RuleEligibilityPolicies::DEFAULTS, $parameters));
    }

    private function input(array $changes = [])
    {
        $reference = ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'source_as_of' => null, 'retrieved_at' => now()->toISOString(),
            'contract_fingerprint' => config('robinhood_mcp.operations.classification.fingerprint'),
            'fields' => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO', 'asset_type' => 'etf']];

        return A::input($this->connection, [], $changes + ['auth_generation' => $this->connection->auth_generation,
            'quote.ask' => '99.1', 'execution_reads.price_book.fields.book_ask' => '99.1', 'execution_reads.price_book.fields.book_ask_quantity' => '100',
            'instrument' => ['id' => 'synthetic-instrument', 'symbol' => 'DEMO'], 'reference_reads.classification' => $reference,
            'execution_reads.tradability.contract_fingerprint' => config('robinhood_mcp.operations.tradability.fingerprint')]);
    }

    private function report(): array
    {
        return app(RuleEligibility::class)->inspect($this->connection, 'DEMO', CarbonImmutable::now());
    }

    private function classify(): void
    {
        X::enable();
        X::fake(X::plainFund());
        app(PaperInstrumentClassifications::class)->collect($this->connection, $this->connection->user_id, ['DEMO'], (string) Str::uuid());
    }

    private function realQuote(): BrokerSimulationQuote
    {
        return BrokerSimulationQuote::fromStored($this->connection, $this->input()->id, CarbonImmutable::now(), 60);
    }

    private function submit(array $changes = [])
    {
        return app(OfflineSimulator::class)->submit($this->connection, $this->connection->user_id, $this->policy->id, $this->portfolio->fresh()->ledger_version,
            F::intent($changes), F::assumptions(), ['DEMO' => $this->realQuote()], $this->execution->id);
    }

    public function test_policy_is_explicit_scoped_immutable_encrypted_and_does_not_change_cash_execution_or_flags(): void
    {
        $before = $this->portfolio->fresh()->getAttributes();
        $policy = $this->apply();
        $this->assertSame('watchlist_rules', $policy->payload['universe']);
        $this->assertSame($before, $this->portfolio->fresh()->getAttributes());
        $this->assertSame(['DEMO'], $this->execution->fresh()->payload['allowed_symbols']);
        $this->assertStringNotContainsString('minimum_price', DB::table('mcp_eligibility_policies')->value('payload'));
        $this->assertFalse(config('paper.decisions_enabled'));
        $this->assertFalse(config('paper.handoff_enabled'));
        $repeat = app(RuleEligibilityPolicies::class)->apply($this->connection, $this->connection->user_id, 0, $policy->request_id, RuleEligibilityPolicies::DEFAULTS);
        $this->assertSame($policy->id, $repeat->id);
        $this->expectException(\LogicException::class);
        $policy->update(['version' => 20]);
    }

    public function test_unconfigured_history_and_liquidity_fail_closed_despite_payload_claims_and_verified_classification_tradability_actions(): void
    {
        $this->apply();
        X::enable();
        X::fake(X::plainFund());
        app(PaperInstrumentClassifications::class)->collect($this->connection, $this->connection->user_id, ['DEMO'], (string) Str::uuid());
        $this->input(['history' => ['verified' => true, 'sessions' => 252, 'average_dollar_volume_20' => '999999999'],
            'quote.eligible' => true, 'quote.leveraged' => false, 'quote.inverse' => false]);
        $report = $this->report();
        $this->assertSame('unavailable', $report['status']);
        foreach (['classification', 'tradability', 'corporate_actions', 'price', 'spread', 'membership', 'policy'] as $check) {
            $this->assertSame('pass', $report['checks'][$check]['status'], $check);
        }
        $this->assertSame('daily_history_source_unconfigured', $report['checks']['history']['reason']);
        $this->assertSame('daily_history_source_unconfigured', $report['checks']['liquidity']['reason']);
        $this->assertSame('us_exchange_listing_unverified', $report['checks']['listing']['reason']);
        $this->assertSame('external_order_detection_disabled', $report['checks']['entry_safety']['reason']);
        $readiness = app(ExecutionDataReadiness::class)->for($this->connection, CarbonImmutable::now());
        $this->assertSame(0, $readiness['ready_count']);
        $this->assertSame('unavailable', $readiness['symbols']['DEMO']['checks']['rule_eligibility']);
    }

    /** Fresh complete order history with no outside orders, recorded through the existing monitor path. */
    private function clearOrderHistory(): void
    {
        config(['mcp.external_order_detection_enabled' => true]);
        $this->app->instance(BrokerGateway::class, new ExternalOrderGateway);
        $this->assertSame('complete', app(Monitor::class)->sync($this->connection->id, 'eligibility:'.Str::uuid())->status);
    }

    private function references(array $bars, array $asset): void
    {
        R::enable();
        R::fake(fn () => R::body(['DEMO' => $bars]), ['DEMO' => $asset]);
        app(PaperMarketReferences::class)->collect($this->connection, $this->connection->user_id, ['DEMO'], (string) Str::uuid());
    }

    public function test_reviewed_listing_and_daily_history_let_a_fully_verified_symbol_become_eligible(): void
    {
        $this->apply();
        $this->classify();
        $this->clearOrderHistory();
        $this->references(R::bars(260, '99.05', '120000'), R::asset('DEMO', 'ARCA'));
        $this->input();
        $report = $this->report();
        $this->assertSame('eligible', $report['status'], json_encode(array_map(fn ($c) => $c['reason'], $report['checks'])));
        $this->assertSame(['pass', 'us_exchange_listed', 'ARCA'], [$report['checks']['listing']['status'], $report['checks']['listing']['reason'], $report['checks']['listing']['exchange']]);
        $this->assertSame(['pass', 260], [$report['checks']['history']['status'], $report['checks']['history']['sessions']]);
        $this->assertSame(['pass', '11886000.00'], [$report['checks']['liquidity']['status'], $report['checks']['liquidity']['average_dollar_volume_20']]);
        $this->assertSame(DailyBars::CONTRACT, $report['evidence']['history_source']);
        $this->assertNotNull($report['evidence']['history_read_id']);
        $this->assertNotNull($report['evidence']['listing_read_id']);
        $recorded = app(RuleEligibility::class)->record($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid(), CarbonImmutable::now());
        $this->assertSame('eligible', $recorded->status);
    }

    public function test_known_listing_history_and_liquidity_failures_are_ineligible_and_stale_history_is_unknown(): void
    {
        $this->apply();
        $this->classify();
        $this->clearOrderHistory();
        // A young, thinly traded OTC symbol: each fact fails on its own recorded reason.
        $this->references(R::bars(120, '6.10', '1000'), R::asset('DEMO', 'OTC'));
        $this->input();
        $report = $this->report();
        $this->assertSame('ineligible', $report['status']);
        $this->assertSame(['ineligible', 'not_us_exchange_listed'], [$report['checks']['listing']['status'], $report['checks']['listing']['reason']]);
        $this->assertSame(['ineligible', 'minimum_history_failed'], [$report['checks']['history']['status'], $report['checks']['history']['reason']]);
        $this->assertSame(['ineligible', 'minimum_dollar_volume_failed'], [$report['checks']['liquidity']['status'], $report['checks']['liquidity']['reason']]);

        $this->travel(config('alpaca_data.history_max_age_hours') + 1)->hours();
        $later = $this->report();
        $this->assertSame(['unavailable', 'daily_history_stale'], [$later['checks']['history']['status'], $later['checks']['history']['reason']]);
        $this->assertSame('daily_history_stale', $later['checks']['liquidity']['reason']);
    }

    public function test_known_price_spread_and_tradability_failures_are_recorded_alongside_unknown_sources(): void
    {
        $this->apply();
        $this->input(['quote.bid' => '4.9', 'quote.ask' => '5.1', 'execution_reads.tradability.fields.broker_tradeable' => false]);
        $report = $this->report();
        $this->assertSame('ineligible', $report['status']);
        foreach (['price', 'spread', 'tradability'] as $check) {
            $this->assertSame('ineligible', $report['checks'][$check]['status']);
        }
        $this->assertSame('unavailable', $report['checks']['history']['status']);
    }

    public function test_assessments_are_idempotent_encrypted_point_in_time_and_bound_to_policy_membership_and_inputs(): void
    {
        $policy = $this->apply();
        $input = $this->input();
        $cutoff = CarbonImmutable::now();
        $request = (string) Str::uuid();
        $first = app(RuleEligibility::class)->record($this->connection, $this->connection->user_id, 'DEMO', $request, $cutoff);
        $this->assertSame([$policy->id, $input->id], [$first->policy_id, $first->market_input_id]);
        $this->assertNotNull($first->watchlist_revision_id);
        $this->assertStringNotContainsString('classification', DB::table('mcp_eligibility_assessments')->value('payload'));
        $this->assertSame($first->id, app(RuleEligibility::class)->record($this->connection, $this->connection->user_id, 'DEMO', $request, $cutoff)->id);
        $this->travel(1)->seconds();
        $this->input(['quote.bid' => '1']);
        $past = app(RuleEligibility::class)->inspect($this->connection, 'DEMO', $cutoff);
        $this->assertSame($input->id, $past['evidence']['market_input_id']);
        $this->assertSame($first->input_fingerprint, $past['input_fingerprint']);
        $this->expectException(HttpException::class);
        app(RuleEligibility::class)->record($this->connection, $this->connection->user_id, 'DEMO', $request, CarbonImmutable::now());
    }

    public function test_removal_or_expiry_stops_entry_membership_but_keeps_held_symbols_in_data_universe(): void
    {
        $this->apply();
        SimulationPosition::create(['paper_portfolio_id' => $this->portfolio->id, 'symbol' => 'DEMO', 'sleeve' => 'active', 'quantity' => '1',
            'reserved_quantity' => '0', 'cost_basis' => '100', 'realized_pnl' => '0']);
        $this->gateway->members = [['id' => 'fixture-new', 'object_type' => 'instrument', 'symbol' => 'NEW']];
        $this->watchlists->collect($this->connection, $this->connection->user_id, (string) Str::uuid());
        $this->assertSame(['DEMO', 'NEW'], app(SymbolUniverse::class)->reads($this->connection, CarbonImmutable::now()));
        $this->assertSame('unavailable', $this->report()['checks']['membership']['status']);
        $this->travel(30)->minutes();
        $this->assertSame(['DEMO'], app(SymbolUniverse::class)->reads($this->connection, CarbonImmutable::now()));
        config(['paper.rule_eligibility_enabled' => false]);
        $this->assertSame(['DEMO'], app(SymbolUniverse::class)->reads($this->connection, CarbonImmutable::now()));
    }

    public function test_new_watchlist_symbols_can_be_collected_beyond_legacy_allowlist_but_foreign_symbols_cannot(): void
    {
        $this->apply();
        config(['paper.market_inputs_enabled' => true]);
        $gateway = new PaperMarketGateway;
        $this->app->instance(BrokerGateway::class, $gateway);
        app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'NEW', (string) Str::uuid());
        $this->assertCount(2, $gateway->calls);
        $this->expectException(HttpException::class);
        app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'FOREIGN', (string) Str::uuid());
    }

    public function test_quote_component_staleness_and_old_authorization_do_not_pass_thresholds(): void
    {
        $this->apply();
        $this->input(['quote.bid_as_of' => now()->subMinutes(3)->toISOString()]);
        $this->assertSame('unavailable', $this->report()['checks']['price']['status']);
        $this->input(['auth_generation' => $this->connection->auth_generation + 1]);
        $this->assertSame('unavailable', $this->report()['checks']['tradability']['status']);
    }

    public function test_rule_buy_records_immutable_unavailable_assessment_without_reserving_cash_or_filling(): void
    {
        $this->classify();
        $this->apply();
        config(['paper.real_data_fills_enabled' => true]);
        $buy = $this->submit();
        $this->assertSame('rejected', $buy->status);
        $assessment = EligibilityAssessment::findOrFail($buy->intent['eligibility_assessment_id']);
        $this->assertSame('unavailable', $assessment->status);
        $this->assertSame('rule_eligibility_unavailable', SimulationEvent::where('sim_order_id', $buy->id)->sole()->reason);
        $this->assertSame('0.000000000000000000', $this->portfolio->fresh()->reserved_cash);
        $this->assertDatabaseCount('mcp_paper_sim_positions', 0);
        $this->expectException(\LogicException::class);
        $assessment->delete();
    }

    public function test_held_exit_survives_rule_selection_removal_disabled_rules_and_a_replaced_execution_allowlist(): void
    {
        $this->classify();
        config(['paper.real_data_fills_enabled' => true]);
        $buy = $this->submit();
        $this->assertSame('open', $buy->status);
        $this->travel(1)->seconds();
        $this->realQuote();
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $buy->id)->status);
        $this->execution = E::apply($this->connection, ['allowed_symbols' => ['OTHER']]);
        $this->apply();
        $this->gateway->members = [];
        $this->watchlists->collect($this->connection, $this->connection->user_id, (string) Str::uuid());
        config(['paper.rule_eligibility_enabled' => false]);
        $this->assertSame(['DEMO'], app(SymbolUniverse::class)->reads($this->connection, CarbonImmutable::now()));
        $sell = $this->submit(['side' => 'SELL', 'price_bound' => '98']);
        $this->assertSame('open', $sell->status);
        $this->travel(1)->seconds();
        $this->realQuote();
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $sell->id)->status);
        $this->assertSame('0.000000000000000000', SimulationPosition::sole()->quantity);
    }

    public function test_late_policy_and_disabled_rules_cannot_fall_back_to_legacy_entry_permission(): void
    {
        $this->classify();
        config(['paper.real_data_fills_enabled' => true]);
        $buy = $this->submit();
        $this->apply();
        config(['paper.rule_eligibility_enabled' => false]);
        $cancelled = app(OfflineSimulator::class)->revalidate($this->connection, $this->connection->user_id, $buy->id);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('0.000000000000000000', $this->portfolio->fresh()->reserved_cash);
        $this->assertSame('rule_eligibility_unavailable', SimulationEvent::where('sim_order_id', $buy->id)->latest('id')->first()->reason);
    }

    public function test_policy_bounds_unknown_fields_and_stale_versions_are_rejected(): void
    {
        $service = app(RuleEligibilityPolicies::class);
        foreach ([['maximum_spread_pct' => '2'], ['minimum_price' => '0'], ['minimum_dollar_volume' => '1'], ['risk_per_trade' => '100']] as $patch) {
            try {
                $service->validate(array_replace(RuleEligibilityPolicies::DEFAULTS, $patch));
                $this->fail('Invalid or unrelated policy field passed.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('mcp_eligibility_policies', 0);
            }
        }
        $this->apply();
        $this->expectException(HttpException::class);
        $service->apply($this->connection, $this->connection->user_id, 0, (string) Str::uuid(), RuleEligibilityPolicies::DEFAULTS);
    }

    public function test_failed_assessment_commit_is_retryable_and_future_or_foreign_assessments_cannot_be_recorded(): void
    {
        $this->apply();
        $crash = true;
        EligibilityAssessment::creating(function () use (&$crash) {
            if ($crash) {
                throw new \RuntimeException('Synthetic assessment failure');
            }
        });
        $request = (string) Str::uuid();
        $cutoff = CarbonImmutable::now();
        try {
            app(RuleEligibility::class)->record($this->connection, $this->connection->user_id, 'DEMO', $request, $cutoff);
            $this->fail('Synthetic crash did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic assessment failure', $e->getMessage());
        } finally {
            $crash = false;
        }
        $this->assertDatabaseCount('mcp_eligibility_assessments', 0);
        app(RuleEligibility::class)->record($this->connection, $this->connection->user_id, 'DEMO', $request, $cutoff);
        $this->assertDatabaseCount('mcp_eligibility_assessments', 1);
        try {
            app(RuleEligibility::class)->record($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid(), $cutoff->addSecond());
            $this->fail('Future cutoff passed.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->expectException(HttpException::class);
        app(RuleEligibility::class)->record($this->connection, $this->connection->user_id + 1, 'DEMO', (string) Str::uuid(), $cutoff);
    }

    public function test_disabled_policy_cannot_reenable_model_handoff(): void
    {
        $this->apply();
        config(['paper.handoff_enabled' => true, 'paper.rule_eligibility_enabled' => false]);
        $run = DecisionRun::forceCreate(['id' => (string) Str::uuid(), 'mcp_connection_id' => $this->connection->id,
            'scope' => AccountScope::key($this->connection), 'paper_portfolio_id' => $this->portfolio->id, 'policy_application_id' => $this->policy->id,
            'execution_version_id' => $this->execution->id, 'auth_generation' => $this->connection->auth_generation, 'ledger_version' => $this->portfolio->fresh()->ledger_version,
            'dedup_key' => 'synthetic-blocked-model', 'scheduled_at' => now(), 'expires_at' => now()->addMinute(), 'status' => 'wait']);
        $outcome = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('model_handoff_superseded_by_rules', $outcome->reason);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        Http::assertNothingSent();
    }

    public function test_control_apply_and_assessment_routes_are_scoped_validated_and_cli_records_no_trades(): void
    {
        $url = '/mcp/control/eligibility/apply';
        $payload = ['base_version' => 0, 'request_id' => (string) Str::uuid(), 'parameters' => RuleEligibilityPolicies::DEFAULTS,
            'account_scope' => AccountScope::key($this->connection), 'confirm_rules' => '1'];
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, $payload)->assertForbidden();
        $this->actingAs(User::findOrFail($this->connection->user_id));
        $this->postJson($url, array_replace($payload, ['parameters' => ['minimum_price' => 5.0] + RuleEligibilityPolicies::DEFAULTS]))->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['account_scope' => str_repeat('0', 64)]))->assertConflict();
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $this->assertDatabaseCount('mcp_eligibility_policies', 1);
        $this->artisan('mcp:eligibility', ['--account' => (string) $this->connection->id])->expectsOutputToContain('Eligibility assessments: 2')->assertSuccessful();
        $assessmentForm = ['request_id' => (string) Str::uuid(), 'account_scope' => AccountScope::key($this->connection), 'cutoff' => CarbonImmutable::now()->toISOString()];
        $this->post('/mcp/control/eligibility/assess', $assessmentForm)->assertRedirect();
        $this->post('/mcp/control/eligibility/assess', $assessmentForm)->assertRedirect();
        $this->assertDatabaseCount('mcp_eligibility_assessments', 4);
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('daily_history_source_unconfigured');
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        Http::assertNothingSent();
    }
}
