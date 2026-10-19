<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\BrokerOrderRead;
use App\Models\Monitoring\ExternalOrder;
use App\Models\Monitoring\ExternalOrderReview;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\RobinhoodWatchlists;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\IncidentRecorder;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\StrategyWatchlists;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExternalOrderGateway;
use Tests\Support\McpFixture;
use Tests\Support\StrategyWatchlistGateway;
use Tests\TestCase;

class ExternalOrdersTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private ExternalOrderGateway $gateway;

    private ExternalOrders $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $this->connection = McpFixture::connection()->fresh();
        config(['mcp.external_order_detection_enabled' => true, 'mcp.operator_emails' => [User::findOrFail($this->connection->user_id)->email]]);
        $this->gateway = new ExternalOrderGateway;
        $this->app->instance(BrokerGateway::class, $this->gateway);
        $this->service = app(ExternalOrders::class);
    }

    private function sync()
    {
        return app(Monitor::class)->sync($this->connection->id, 'external-test:'.Str::uuid());
    }

    private function reason(): ?string
    {
        return $this->service->entryReason($this->connection->fresh());
    }

    private function review(ExternalOrder $order, ?string $request = null): ExternalOrderReview
    {
        return $this->service->review($this->connection->fresh(), $this->connection->user_id, $order->id, $request ?? (string) Str::uuid(), 'Reviewed account impact.');
    }

    public function test_detection_is_default_off_and_all_observed_statuses_and_legacy_like_ids_require_review(): void
    {
        $this->gateway->orders = [['id' => 'paper_fixture', 'status' => 'filled'], ['id' => 'legacy-fixture', 'status' => 'cancelled', 'side' => 'sell']];
        config(['mcp.external_order_detection_enabled' => false]);
        $this->sync();
        $this->assertDatabaseCount('mcp_external_orders', 0);
        config(['mcp.external_order_detection_enabled' => true]);
        $run = $this->sync();
        $this->assertSame('complete', $run->status);
        $this->assertSame(2, $this->service->pending($this->connection)->count());
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
        $incident = Incident::where('incident_key', ExternalOrders::INCIDENT)->sole();
        $this->assertSame(['critical', 'external_orders'], [$incident->severity, $incident->source]);
        $this->assertContains(ExternalOrders::INCIDENT, Snapshot::where('run_id', $run->id)->sole()->observations['pause_reasons']);
        $this->assertStringNotContainsString('paper_fixture', DB::table('mcp_external_orders')->value('payload'));
        $this->sync();
        $this->assertDatabaseCount('mcp_external_orders', 2);
        $this->assertSame(0, $incident->fresh()->reopen_count);
        Http::assertNothingSent();
        $this->expectException(\LogicException::class);
        ExternalOrder::first()->update(['broker_id_hash' => str_repeat('0', 64)]);
    }

    public function test_partial_order_page_latches_observed_order_even_when_snapshot_fails_and_empty_reads_do_not_clear_it(): void
    {
        $this->gateway->orders = [['id' => 'partial-observation']];
        $this->gateway->partial = true;
        $this->assertSame('failed', $this->sync()->status);
        $this->assertDatabaseCount('mcp_external_orders', 1);
        $this->assertFalse(BrokerOrderRead::sole()->complete);
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
        $this->gateway->partial = false;
        $this->gateway->orders = [];
        $this->sync();
        $incident = Incident::where('incident_key', ExternalOrders::INCIDENT)->sole();
        $this->actingAs(User::findOrFail($this->connection->user_id))->post('/mcp/incidents/'.$incident->id.'/acknowledge')->assertRedirect();
        $this->assertSame('acknowledged', $incident->fresh()->state);
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
        config(['mcp.external_order_detection_enabled' => false]);
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
        config(['mcp.external_order_detection_enabled' => true]);
        $this->review(ExternalOrder::sole());
        $this->assertNull($this->reason());
        $this->assertSame('resolved', $incident->fresh()->state);
        $this->gateway->orders = [['id' => 'new-order']];
        $this->sync();
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
        $this->assertSame(1, $incident->fresh()->reopen_count);
    }

    public function test_each_order_needs_its_own_review_and_old_order_status_changes_do_not_grant_ownership_or_reopen_review(): void
    {
        $this->gateway->orders = [['id' => 'one'], ['id' => 'two']];
        $this->sync();
        $orders = ExternalOrder::orderBy('id')->get();
        $request = (string) Str::uuid();
        $first = $this->review($orders[0], $request);
        $this->assertSame($first->id, $this->review($orders[0], $request)->id);
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
        $this->review($orders[1]);
        $this->assertNull($this->reason());
        $this->gateway->orders = [['id' => 'one', 'status' => 'cancelled']];
        $this->sync();
        $this->assertNull($this->reason());
        $this->assertDatabaseCount('mcp_external_order_reviews', 2);
        $this->expectException(HttpException::class);
        $this->review($orders[1], $request);
    }

    public function test_order_history_must_be_complete_fresh_and_currently_authorized(): void
    {
        $this->assertSame('broker_order_history_unverified', $this->reason());
        $this->sync();
        $this->assertNull($this->reason());
        $fingerprint = config('robinhood_mcp.operations.orders.fingerprint');
        config(['robinhood_mcp.operations.orders.fingerprint' => 'changed-contract']);
        $this->assertSame('broker_order_history_unverified', $this->reason());
        config(['robinhood_mcp.operations.orders.fingerprint' => $fingerprint]);
        $this->assertNull($this->reason());
        $this->gateway->partial = true;
        $this->sync();
        $this->assertSame('broker_order_history_unverified', $this->reason());
        $this->gateway->partial = false;
        $this->sync();
        $this->travel(5)->minutes();
        $this->assertSame('broker_order_history_unverified', $this->reason());
        $this->sync();
        $this->assertNull($this->reason());
        $this->connection->increment('auth_generation');
        $this->assertSame('broker_order_history_unverified', $this->reason());
    }

    public function test_account_change_during_order_read_cannot_record_foreign_evidence(): void
    {
        $this->gateway->orders = [['id' => 'changed-account']];
        $this->gateway->duringOrders = fn () => $this->connection->update(['bound_account_id' => 'different-agentic']);
        $this->assertSame('failed', $this->sync()->status);
        $this->assertDatabaseCount('mcp_external_orders', 0);
        $this->assertDatabaseCount('mcp_broker_order_reads', 0);
    }

    public function test_partial_run_retry_preserves_each_attempt_and_detects_additional_orders(): void
    {
        $this->gateway->partial = true;
        $this->gateway->orders = [['id' => 'page-one']];
        $monitor = app(Monitor::class);
        $first = $monitor->sync($this->connection->id, 'partial-retry');
        $this->assertSame('failed', $first->status);
        $this->gateway->partial = false;
        $this->gateway->orders[] = ['id' => 'page-two'];
        $second = $monitor->sync($this->connection->id, 'partial-retry');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('complete', $second->status);
        $this->assertDatabaseCount('mcp_external_orders', 2);
        $this->assertDatabaseCount('mcp_broker_order_reads', 2);
        $this->assertSame(['partial', 'complete'], BrokerOrderRead::orderBy('id')->get()->map(fn ($read) => $read->payload['read']['completeness'])->all());
        foreach (ExternalOrder::all() as $order) {
            $this->review($order);
        }
        $this->assertNull($this->reason());
    }

    public function test_detection_commit_failure_rolls_back_case_and_retry_detects_it_again(): void
    {
        $crash = true;
        BrokerOrderRead::creating(function () use (&$crash) {
            if ($crash) {
                throw new \RuntimeException('Synthetic order-read commit failure');
            }
        });
        $this->gateway->orders = [['id' => 'retry-after-crash']];
        try {
            $this->assertSame('failed', app(Monitor::class)->sync($this->connection->id, 'crash-retry')->status);
        } finally {
            $crash = false;
        }
        $this->assertDatabaseCount('mcp_external_orders', 0);
        $this->assertDatabaseCount('mcp_broker_order_reads', 0);
        $this->assertSame('complete', app(Monitor::class)->sync($this->connection->id, 'crash-retry')->status);
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
    }

    public function test_another_operator_cannot_review_this_accounts_order(): void
    {
        $this->gateway->orders = [['id' => 'scoped-order']];
        $this->sync();
        $order = ExternalOrder::sole();
        $other = McpFixture::connection()->fresh();
        config(['mcp.operator_emails' => [User::findOrFail($this->connection->user_id)->email, User::findOrFail($other->user_id)->email]]);
        $this->actingAs(User::findOrFail($other->user_id))->postJson('/mcp/control/external-orders/'.$order->id.'/review',
            ['request_id' => (string) Str::uuid(), 'confirm_review' => '1', 'note' => 'Attempted foreign review.', 'account_scope' => AccountScope::key($other)])->assertNotFound();
        $this->assertDatabaseCount('mcp_external_order_reviews', 0);
        $this->assertSame(ExternalOrders::INCIDENT, $this->reason());
    }

    public function test_review_is_operator_scoped_csrf_protected_and_uses_server_identity(): void
    {
        $this->gateway->orders = [['id' => 'review-me']];
        $this->sync();
        $order = ExternalOrder::sole();
        $url = '/mcp/control/external-orders/'.$order->id.'/review';
        $payload = ['request_id' => (string) Str::uuid(), 'note' => 'Owner inspected account.', 'confirm_review' => '1',
            'account_scope' => AccountScope::key($this->connection), 'actor_id' => 999];
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, $payload)->assertForbidden();
        $this->actingAs(User::findOrFail($this->connection->user_id));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('review-me')->assertSee('New entries paused')->assertSee('Record order review');
        $this->postJson($url, array_replace($payload, ['account_scope' => str_repeat('0', 64)]))->assertConflict();
        $this->postJson($url, array_replace($payload, ['confirm_review' => '0']))->assertUnprocessable();
        $this->post($url, $payload)->assertRedirect();
        $this->assertSame($this->connection->user_id, ExternalOrderReview::sole()->actor_id);
        $this->assertStringNotContainsString('Owner inspected', DB::table('mcp_external_order_reviews')->value('payload'));
        $this->get('/mcp/dashboard/control')->assertOk()->assertDontSee('Record order review');
        $this->expectException(\LogicException::class);
        ExternalOrderReview::sole()->delete();
    }

    public function test_fresh_watchlist_cannot_nominate_entries_while_order_review_or_order_coverage_is_pending(): void
    {
        $this->sync();
        config(['paper.watchlist_intake_enabled' => true, 'robinhood_mcp.watchlists_enabled' => true]);
        $watchlists = new StrategyWatchlists(
            new RobinhoodWatchlists(new StrategyWatchlistGateway),
            app(IncidentRecorder::class));
        $watchlists->configure($this->connection, $this->connection->user_id, 0, (string) Str::uuid(), 'Strategy Candidates');
        $watchlists->collect($this->connection, $this->connection->user_id, (string) Str::uuid());
        $this->assertSame(['TEST'], $watchlists->nominations($this->connection, CarbonImmutable::now())['symbols']);
        $this->gateway->orders = [['id' => 'pauses-nominations']];
        $this->sync();
        $paused = $watchlists->nominations($this->connection, CarbonImmutable::now());
        $this->assertTrue($paused['fresh']);
        $this->assertSame([], $paused['symbols']);
        $this->assertSame(ExternalOrders::INCIDENT, $paused['entry_pause_reason']);
        $this->review(ExternalOrder::sole());
        $this->assertSame(['TEST'], $watchlists->nominations($this->connection, CarbonImmutable::now())['symbols']);
        $this->travel(5)->minutes();
        $this->assertSame('broker_order_history_unverified', $watchlists->nominations($this->connection, CarbonImmutable::now())['entry_pause_reason']);
    }
}
