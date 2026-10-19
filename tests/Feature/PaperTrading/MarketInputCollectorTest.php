<?php

namespace Tests\Feature\PaperTrading;

use App\Jobs\CollectPaperMarketInput;
use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\SimulationOrder;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\MarketInputCollector;
use App\Services\PaperTrading\PaperMarketInputs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperMarketGateway;
use Tests\TestCase;

class MarketInputCollectorTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $execution;

    private PaperMarketGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, , , $this->execution] = E::seed(['allowed_symbols' => ['ALFA', 'BRAVO', 'DEMO']]);
        $this->gateway = new PaperMarketGateway;
        $this->app->instance(BrokerGateway::class, $this->gateway);
        config(['paper.market_collection_enabled' => true, 'paper.market_inputs_enabled' => true]);
    }

    public function test_rotation_is_bounded_idempotent_encrypted_and_never_creates_execution_records(): void
    {
        $collector = app(MarketInputCollector::class);
        $this->assertSame(2, $collector->tick(true, $this->connection->id, 2));
        $this->assertSame(['ALFA', 'BRAVO'], MarketInput::where('origin', 'broker_read')->orderBy('id')->pluck('symbol')->all());
        $this->assertSame(1, $collector->tick(true, $this->connection->id, 2));
        $this->assertSame(0, $collector->tick(true, $this->connection->id));
        $this->assertCount(6, $this->gateway->calls);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertSame(0, CorporateActionRecord::count());
        $this->assertStringNotContainsString('synthetic-instrument', \DB::table('mcp_paper_market_inputs')->where('origin', 'broker_read')->value('payload'));
        $this->travel(6)->minutes();
        $this->assertSame(1, $collector->tick(true, $this->connection->id, 1));
        $this->assertSame(4, MarketInput::where('origin', 'broker_read')->count());
        Http::assertNothingSent();
    }

    public function test_independent_gate_operator_scope_and_lock_prevent_reads(): void
    {
        $collector = app(MarketInputCollector::class);
        config(['paper.market_collection_enabled' => false]);
        $this->assertSame(0, $collector->tick(true));
        config(['paper.market_collection_enabled' => true, 'paper.market_inputs_enabled' => false]);
        $this->assertSame(0, $collector->tick(true));
        config(['paper.market_inputs_enabled' => true]);
        $lock = Cache::lock('paper-market-collector', 600);
        $this->assertTrue($lock->get());
        $this->assertSame(0, $collector->tick(true));
        $lock->release();
        config(['mcp.operator_emails' => []]);
        $this->assertSame(0, $collector->tick(true));
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_queued_work_rechecks_authorization_settings_gates_and_age(): void
    {
        Queue::fake();
        $this->assertSame(1, app(MarketInputCollector::class)->tick(false, $this->connection->id, 1));
        Queue::assertPushed(CollectPaperMarketInput::class, 1);
        $job = new CollectPaperMarketInput($this->connection->id, AccountScope::key($this->connection), $this->connection->auth_generation,
            $this->execution->id, 'ALFA', (string) Str::uuid(), now()->toISOString());
        $this->connection->increment('auth_generation');
        $job->handle(app(PaperMarketInputs::class));
        $job->generation++;
        E::apply($this->connection->fresh(), ['allowed_symbols' => ['DEMO']]);
        $job->handle(app(PaperMarketInputs::class));
        $job->executionId = $this->execution->fresh()->id;
        $this->travel(6)->minutes();
        $job->handle(app(PaperMarketInputs::class));
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_off_session_collection_is_hourly_and_cli_rejects_unbounded_requests(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T23:05:00Z'));
        $this->assertSame(0, app(MarketInputCollector::class)->tick(true, null, 5, true));
        $this->travelTo(CarbonImmutable::parse('2026-10-20T00:00:00Z'));
        $this->assertSame(1, app(MarketInputCollector::class)->tick(true, null, 1, true));
        $this->artisan('mcp:paper-collect', ['--once' => true, '--limit' => '99'])->assertFailed();
        $this->artisan('mcp:paper-collect')->assertFailed();
        config(['paper.market_collection_enabled' => false]);
        $this->artisan('mcp:paper-collect', ['--once' => true])->expectsOutputToContain('selected: 0')->assertSuccessful();
    }
}
