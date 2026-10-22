<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\InstrumentClassification;
use App\Models\PaperTrading\MarketReferenceRead;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SymbolProfile;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\IntradaySymbolWarmup;
use App\Services\PaperTrading\SymbolUniverse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\EdgarFixture;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\StrategySignalFixture as F;
use Tests\TestCase;

class IntradaySymbolWarmupTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T16:00:00Z'));
        [$this->connection] = E::seed(['allowed_symbols' => ['ALFA', 'BRAVO', 'CHARLIE', 'DEMO']]);
        R::enable();
        config(['paper.intraday_warmup_enabled' => true, 'paper.symbol_profiles_enabled' => true, 'paper.market_collection_enabled' => true]);
        app(MarketCalendar::class)->store('2026-10-19', 'regular', '2026-10-19T13:30:00Z', '2026-10-19T20:00:00Z', 'synthetic');
        R::fake(fn ($query) => R::body(array_fill_keys(explode(',', $query['symbols']), R::series(array_map(fn ($n) => 10000 + $n, range(0, 274))))),
            array_combine(['ALFA', 'BRAVO', 'CHARLIE', 'DEMO'], array_map(fn ($s) => R::asset($s), ['ALFA', 'BRAVO', 'CHARLIE', 'DEMO'])));
    }

    public function test_midday_missing_references_are_bounded_repaired_and_profiled_without_repeat_downloads(): void
    {
        $warmup = app(IntradaySymbolWarmup::class);
        $this->assertSame(['attempted' => 3, 'profiles' => 3, 'failed' => 0], $warmup->tick($this->connection->id));
        $this->assertSame(3, SymbolProfile::where('status', 'complete')->count());
        $this->assertSame(1, MarketReferenceRead::where('symbol', 'SPY')->count(), 'Fresh benchmark history must be reused.');
        $this->assertSame(['attempted' => 1, 'profiles' => 1, 'failed' => 0], $warmup->tick($this->connection->id));
        $sent = count(Http::recorded());
        $this->assertSame(0, $warmup->tick($this->connection->id)['attempted']);
        $this->assertCount($sent, Http::recorded());
        $this->assertSame(4, SymbolProfile::count());
    }

    public function test_failed_provider_results_remain_unknown_and_back_off_without_retry_storms(): void
    {
        R::fake(fn () => Http::response([], 429));
        $warmup = app(IntradaySymbolWarmup::class);
        $this->assertSame(3, $warmup->tick($this->connection->id)['attempted']);
        $this->assertSame(0, SymbolProfile::where('status', 'complete')->count());
        $warmup->tick($this->connection->id); // Fourth symbol; first three remain throttled.
        $sent = count(Http::recorded());
        $this->assertSame(0, $warmup->tick($this->connection->id)['attempted']);
        $this->assertCount($sent, Http::recorded());
        $this->travel(11)->minutes();
        $this->assertSame(3, $warmup->tick($this->connection->id)['attempted']);
    }

    public function test_data_only_additions_warm_up_without_becoming_nominations(): void
    {
        [$c, , , , $watchlists, $reader] = F::seed();
        config(['sec_edgar.enabled' => false]);
        $reader->lists = ['Core' => ['id' => 'core', 'members' => [['id' => 'demo', 'object_type' => 'instrument', 'symbol' => 'DEMO']]],
            'Warmup' => ['id' => 'warmup', 'members' => [['id' => 'alfa', 'object_type' => 'instrument', 'symbol' => 'ALFA']]]];
        $watchlists->configure($c, $c->user_id, 1, (string) Str::uuid(), [['name' => 'Core', 'role' => 'nominate'], ['name' => 'Warmup', 'role' => 'data_only']]);
        $watchlists->collect($c, $c->user_id, (string) Str::uuid());
        R::fake(fn ($query) => R::body(array_fill_keys(explode(',', $query['symbols']), R::series(array_map(fn ($n) => 10000 + $n, range(0, 274))))), ['ALFA' => R::asset('ALFA')]);
        app(IntradaySymbolWarmup::class)->tick($c->id);
        $this->assertSame('complete', SymbolProfile::where('mcp_connection_id', $c->id)->where('symbol', 'ALFA')->sole()->status);
        $this->assertSame(['DEMO'], app(SymbolUniverse::class)->candidates($c, CarbonImmutable::now()));
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_missing_sec_classification_is_collected_for_midday_symbols_and_failures_do_not_grant_eligibility(): void
    {
        EdgarFixture::enable();
        Http::fake(EdgarFixture::plainFund());
        app(IntradaySymbolWarmup::class)->tick($this->connection->id);
        app(IntradaySymbolWarmup::class)->tick($this->connection->id);
        $classification = InstrumentClassification::where('symbol', 'DEMO')->sole();
        $this->assertSame('complete', $classification->status);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertSame(4, SymbolProfile::count());
    }

    public function test_disabled_closed_and_overlapping_runs_make_no_reads(): void
    {
        config(['paper.intraday_warmup_enabled' => false]);
        $this->assertTrue(app(IntradaySymbolWarmup::class)->tick()['disabled']);
        config(['paper.intraday_warmup_enabled' => true]);
        $lock = Cache::lock('intraday-symbol-warmup', 600);
        $this->assertTrue($lock->get());
        $this->assertSame(0, app(IntradaySymbolWarmup::class)->tick()['attempted']);
        $lock->release();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T20:00:00Z'));
        $this->assertSame(0, app(IntradaySymbolWarmup::class)->tick()['attempted']);
        Http::assertNothingSent();
    }
}
