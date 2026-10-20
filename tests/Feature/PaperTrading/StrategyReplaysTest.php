<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\StrategyReplayRun;
use App\Services\PaperTrading\StrategyReplay;
use App\Services\PaperTrading\StrategyReplays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\ExecutionFixture as E;
use Tests\TestCase;

class StrategyReplaysTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection] = E::seed();
        R::enable();
    }

    private function fake(bool $benchmark = true): array
    {
        $queries = [];
        $rising = fn (int $n, int $from, int $step) => array_map(fn ($i) => $from + $i * $step, range(0, $n - 1));
        $bodies = ['ALFA' => R::series([...$rising(230, 5000, 22), ...array_fill(0, 20, 10060), ...$rising(10, 10140, 30)])];
        if ($benchmark) {
            $bodies['SPY'] = R::series($rising(260, 30000, 10));
        }
        R::fake(function ($query) use ($bodies, &$queries) {
            $queries[] = $query;

            return R::body(array_intersect_key($bodies, array_flip(explode(',', $query['symbols']))));
        });

        return $queries;
    }

    private function replay(?string $request = null, int $actor = 0)
    {
        return app(StrategyReplays::class)->run($this->connection, $actor ?: $this->connection->user_id, $request ?? (string) Str::uuid(), 3, '10000', ['ALFA']);
    }

    public function test_replays_are_labeled_immutable_encrypted_and_bound_to_their_inputs(): void
    {
        $this->fake();
        $run = $this->replay();
        $this->assertSame(['complete', 'retrospective_replay_recorded', StrategyReplay::VERSION], [$run->status, $run->reason, $run->replay_version]);
        $this->assertSame('retrospective_daily_replay_not_forward_results', $run->payload['label']);
        $this->assertSame(['ALFA'], $run->payload['symbols']);
        $this->assertSame('signal_execution_defaults', $run->payload['settings_source']);
        $this->assertNotNull($run->start_date);
        $this->assertGreaterThan(0, $run->payload['result']['sessions']);
        $this->assertContains('earnings_blackout_not_reconstructed', $run->payload['result']['limitations']);
        $this->assertFalse($run->payload['broker_executable']);
        $this->assertStringNotContainsString('retrospective', DB::table('mcp_strategy_replays')->value('payload'));
        // One read covers the symbols and benchmark; the window is the replay span plus profile warm-up.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'symbols=ALFA%2CSPY') && str_contains($request->url(), 'start=2022-09-14'));
        // Same request, same record, no further reads; same inputs give the same fingerprint.
        $sent = count(Http::recorded());
        $this->assertSame($run->id, app(StrategyReplays::class)->run($this->connection, $this->connection->user_id, $run->request_id, 3, '10000', ['ALFA'])->id);
        $this->assertCount($sent, Http::recorded());
        $this->assertSame($run->input_fingerprint, $this->replay()->input_fingerprint);
        $this->expectException(\LogicException::class);
        $run->update(['reason' => 'changed']);
    }

    public function test_missing_benchmark_history_is_recorded_incomplete_never_a_result(): void
    {
        $this->fake(false);
        $run = $this->replay();
        $this->assertSame(['incomplete', 'benchmark_history_unavailable', null, null], [$run->status, $run->reason, $run->start_date, $run->payload['result']]);
        $this->assertSame(['SPY' => 'no_bars_returned'], $run->payload['excluded']);
    }

    public function test_replays_are_gated_operator_scoped_and_available_from_the_command(): void
    {
        $this->fake();
        try {
            $this->replay(actor: $this->connection->user_id + 1);
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        config(['alpaca_data.reference_enabled' => false]);
        try {
            $this->replay();
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertNothingSent();
        R::enable();
        $this->artisan('mcp:strategy-replay', ['--account' => $this->connection->id, '--symbol' => ['ALFA'], '--equity' => '10000'])
            ->expectsOutputToContain('"status":"complete"')->assertSuccessful();
        $this->assertSame(1, StrategyReplayRun::count());
    }
}
