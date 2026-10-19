<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SymbolProfile;
use App\Services\PaperTrading\PaperMarketReferences;
use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\StrategyParameters;
use App\Services\PaperTrading\SymbolProfiles;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\ExecutionFixture as E;
use Tests\TestCase;

class SymbolProfilesTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection] = E::seed(['allowed_symbols' => ['ALFA', 'DEMO']]);
        R::enable();
        config(['paper.symbol_profiles_enabled' => true]);
    }

    /** ALFA: breakout series; DEMO: falling series; SPY: steady rise. Fetched and stored through the real adapter path. */
    private function references(bool $benchmark = true): void
    {
        $rising = fn (int $n, int $from, int $step) => array_map(fn ($i) => $from + $i * $step, range(0, $n - 1));
        $bodies = ['ALFA' => R::series([...$rising(230, 5000, 22), ...array_fill(0, 20, 10060)]), 'DEMO' => R::series($rising(250, 20000, -20))];
        if ($benchmark) {
            $bodies['SPY'] = R::series($rising(250, 30000, 10));
        }
        R::fake(fn ($query) => R::body(array_intersect_key($bodies, array_flip(explode(',', $query['symbols'])))), ['ALFA' => R::asset('ALFA'), 'DEMO' => R::asset('DEMO')]);
        app(PaperMarketReferences::class)->collect($this->connection, $this->connection->user_id, [], (string) Str::uuid());
    }

    private function record(array $symbols = [], ?string $request = null, ?CarbonImmutable $cutoff = null)
    {
        return app(SymbolProfiles::class)->record($this->connection, $this->connection->user_id, $symbols, $request ?? (string) Str::uuid(), $cutoff);
    }

    public function test_profiles_are_immutable_encrypted_reproducible_records_of_stored_history(): void
    {
        $this->references();
        $sent = count(Http::recorded());
        $rows = $this->record()->keyBy('symbol');
        $this->assertCount($sent, Http::recorded()); // profiles never call out
        $alfa = $rows['ALFA'];
        $this->assertSame(['complete', 'breakout', 'breakout_setup', '2026-10-16'], [$alfa->status, $alfa->setup, $alfa->reason, $alfa->session_date->toDateString()]);
        $this->assertSame([ProfileCalculator::VERSION, StrategyParameters::VERSION], [$alfa->profile_version, $alfa->parameters_version]);
        $this->assertSame(['trigger' => '101.10', 'max_entry' => '101.6000', 'initial_stop' => '99.1000'], $alfa->payload['levels']);
        $this->assertSame(['earnings_date_unknown'], $alfa->payload['entry_blockers']);
        $this->assertNotNull($alfa->history_read_id);
        $this->assertNotNull($alfa->benchmark_read_id);
        $this->assertFalse($alfa->payload['broker_executable']);
        $this->assertSame(['none', 'not_in_uptrend'], [$rows['DEMO']->setup, $rows['DEMO']->reason]);
        $this->assertStringNotContainsString('breakout_setup', DB::table('mcp_symbol_profiles')->pluck('payload')->implode(''));
        // Same stored inputs and cutoff give the same fingerprint.
        $again = $this->record(['ALFA'], cutoff: $alfa->cutoff)->first();
        $this->assertNotSame($alfa->id, $again->id);
        $this->assertSame($alfa->input_fingerprint, $again->input_fingerprint);
        $this->expectException(\LogicException::class);
        $alfa->update(['setup' => 'pullback']);
    }

    public function test_requests_are_idempotent_and_cutoff_bound(): void
    {
        $this->references();
        $request = (string) Str::uuid();
        $cutoff = CarbonImmutable::now();
        $first = $this->record(['ALFA'], $request, $cutoff)->first();
        $this->assertSame($first->id, $this->record(['ALFA'], $request, $cutoff)->first()->id);
        try {
            $this->record(['ALFA'], $request, $cutoff->subSecond());
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            $this->record(['ALFA'], cutoff: CarbonImmutable::now()->addMinute());
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
    }

    public function test_missing_stale_or_benchmarkless_history_is_unavailable_and_point_in_time(): void
    {
        $before = CarbonImmutable::now();
        $this->travel(1)->seconds();
        $this->references(benchmark: false);
        $rows = $this->record(['ALFA'])->first();
        // The benchmark read came back empty, so no complete benchmark history exists.
        $this->assertSame(['unavailable', null, 'benchmark_daily_history_missing'], [$rows->status, $rows->setup, $rows->reason]);
        $this->references();
        // A cutoff before the reads exist cannot use them.
        $this->assertSame('daily_history_missing', $this->record(['ALFA'], cutoff: $before)->first()->reason);
        $this->assertSame('breakout', $this->record(['ALFA'])->first()->setup);
        $this->travel(config('alpaca_data.history_max_age_hours') + 1)->hours();
        $this->assertSame('daily_history_stale', $this->record(['ALFA'])->first()->reason);
        $latest = app(SymbolProfiles::class)->latest($this->connection, 'ALFA', CarbonImmutable::now());
        $this->assertSame('daily_history_stale', $latest->reason);
        $this->assertSame('breakout', app(SymbolProfiles::class)->latest($this->connection, 'ALFA', CarbonImmutable::now()->subHours(config('alpaca_data.history_max_age_hours')))->setup);
    }

    public function test_profiles_are_gated_operator_scoped_bounded_and_daily_idempotent(): void
    {
        $this->references();
        foreach ([[['ELSE'], $this->connection->user_id, 422], [[], $this->connection->user_id + 1, 403]] as [$symbols, $actor, $status]) {
            try {
                app(SymbolProfiles::class)->record($this->connection, $actor, $symbols, (string) Str::uuid());
                $this->fail();
            } catch (HttpException $error) {
                $this->assertSame($status, $error->getStatusCode());
            }
        }
        config(['paper.symbol_profiles_enabled' => false]);
        $this->assertSame(0, app(SymbolProfiles::class)->tick());
        $this->artisan('mcp:symbol-profiles', ['--account' => $this->connection->id])->expectsOutputToContain('disabled')->assertFailed();
        config(['paper.symbol_profiles_enabled' => true]);
        $this->assertSame(2, app(SymbolProfiles::class)->tick());
        $this->assertSame(2, app(SymbolProfiles::class)->tick());
        $this->assertSame(2, SymbolProfile::count());
        $this->artisan('mcp:symbol-profiles', ['--account' => $this->connection->id, '--symbol' => ['ALFA']])
            ->expectsOutputToContain('"setup":"breakout"')->assertSuccessful();
        $this->artisan('mcp:symbol-profiles', ['--scheduled' => true])->expectsOutputToContain('Recorded symbol profiles: 2')->assertSuccessful();
    }
}
