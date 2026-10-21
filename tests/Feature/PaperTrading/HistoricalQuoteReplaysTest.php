<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Services\MarketData\Alpaca\CorporateActions;
use App\Services\MarketData\Alpaca\DailyBars;
use App\Services\MarketData\Alpaca\HistoricalQuotes;
use App\Services\PaperTrading\HistoricalQuoteReplays;
use App\Services\PaperTrading\StrategyReplay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\IntradayReplayFixture as F;
use Tests\TestCase;

class HistoricalQuoteReplaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20T14:00:00Z'));
        R::enable();
        config(['alpaca_data.calendar_enabled' => true, 'alpaca_data.historical_quote_replay_enabled' => true]);
    }

    private function fakeSources(bool $quoteAccess = true): void
    {
        $bars = F::data()['daily_reads'];
        Http::fake([
            'https://paper-api.alpaca.markets/v2/calendar*' => function ($q) {
                $dates = array_values(array_filter(['2026-10-16', '2026-10-19', '2026-10-20'], fn ($d) => $d >= $q['start'] && $d <= $q['end']));

                return Http::response(array_map(fn ($date) => ['date' => $date, 'open' => '09:30', 'close' => '16:00', 'session_open' => '04:00', 'session_close' => '20:00', 'settlement_date' => '2026-10-21'], $dates));
            },
            DailyBars::URL.'*' => function ($q) use ($bars) {
                if (substr((string) $q['end'], 0, 10) >= '2026-10-19') {
                    foreach ($bars as &$read) {
                        $bar = $read['bars'][array_key_last($read['bars'])];
                        $bar[0] = '2026-10-19';
                        $read['bars'][] = $bar;
                    }
                    unset($read);
                }
                $body = [];
                foreach (explode(',', $q['symbols']) as $symbol) {
                    $body[$symbol] = array_map(fn ($b) => '{"t":"'.CarbonImmutable::parse($b[0], 'America/New_York')->utc()->format('Y-m-d\TH:i:s\Z').'","o":'.$b[1].',"h":'.$b[2].',"l":'.$b[3].',"c":'.$b[4].',"v":'.$b[5].',"vw":'.$b[6].',"n":'.$b[7].'}', $bars[$symbol]['bars']);
                }

                return Http::response(R::body($body));
            },
            CorporateActions::URL.'*' => Http::response('{"corporate_actions":{},"next_page_token":null}'),
            HistoricalQuotes::URL.'*' => function ($q) use ($quoteAccess) {
                if (! $quoteAccess) {
                    return Http::response('', 403);
                }
                $quote = F::quote($q['end']);

                return Http::response(['quotes' => [$q['symbols'] => [['t' => $quote['timestamp_ns'], 'bp' => $quote['bid'], 'ap' => $quote['ask'],
                    'bs' => '100', 'as' => '100', 'ax' => 'V', 'bx' => 'V', 'z' => 'C', 'c' => ['R']]]], 'next_page_token' => 'older']);
            },
        ]);
    }

    public function test_a_real_rule_scenario_is_archived_encrypted_without_touching_forward_cash_or_orders(): void
    {
        [$c, $p] = E::seed();
        $this->fakeSources();
        $request = (string) Str::uuid();
        $run = app(HistoricalQuoteReplays::class)->run($c, $c->user_id, $request, '2026-10-19', ['DEMO'], '10000', 25);
        $this->assertSame('complete', $run->status, $run->reason);
        $this->assertSame(StrategyReplay::INTRADAY_VERSION, $run->replay_version);
        $this->assertNotEmpty($run->payload['result']['fills']);
        $this->assertFalse($run->payload['broker_executable']);
        $this->assertSame($p->cash, $p->fresh()->cash);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertStringNotContainsString('quote_reads', DB::table('mcp_strategy_replays')->value('payload'));
        Http::assertSent(fn ($q) => str_starts_with($q->url(), DailyBars::URL) && $q['adjustment'] === 'raw');
        $count = count(Http::recorded());
        $this->assertSame($run->id, app(HistoricalQuoteReplays::class)->run($c, $c->user_id, $request, '2026-10-19', ['DEMO'], '10000', 25)->id);
        $this->assertCount($count, Http::recorded());
        $this->artisan('mcp:intraday-replay', ['--account' => (string) $c->id, '--date' => '2026-10-19', '--symbol' => ['DEMO'], '--equity' => '10000', '--minutes' => '25', '--request' => $request])
            ->expectsOutputToContain('retrospective_sampled_quote_scenario_not_forward_or_point_in_time')->assertSuccessful();
        $this->assertCount($count, Http::recorded());
        $this->expectException(HttpException::class);
        app(HistoricalQuoteReplays::class)->run($c, $c->user_id, $request, '2026-10-19', ['DEMO'], '500', 25);
    }

    public function test_missing_historical_permission_is_incomplete_and_does_not_generate_partial_performance(): void
    {
        [$c] = E::seed();
        $this->fakeSources(false);
        $run = app(HistoricalQuoteReplays::class)->run($c, $c->user_id, (string) Str::uuid(), '2026-10-19', ['DEMO'], '500', 25);
        $this->assertSame('incomplete', $run->status);
        $this->assertSame('historical_quote_access_denied', $run->reason);
        $this->assertNull($run->payload['result']);
        $this->assertNull($run->start_date);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_independent_gate_and_operator_scope_prevent_network_calls(): void
    {
        [$c] = E::seed();
        config(['alpaca_data.historical_quote_replay_enabled' => false]);
        try {
            app(HistoricalQuoteReplays::class)->run($c, $c->user_id, (string) Str::uuid(), '2026-10-19', ['DEMO']);
            $this->fail('Disabled research ran.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        config(['alpaca_data.historical_quote_replay_enabled' => true, 'mcp.operator_emails' => []]);
        try {
            app(HistoricalQuoteReplays::class)->run($c, $c->user_id, (string) Str::uuid(), '2026-10-19', ['DEMO']);
            $this->fail('Untrusted actor ran.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, StrategyReplayRun::count());
        Http::assertNothingSent();
    }

    public function test_multi_session_command_archives_a_versioned_range_and_retries_without_network_or_ledger_changes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
        [$c, $p] = E::seed();
        $this->fakeSources();
        $request = (string) Str::uuid();
        $args = ['--account' => (string) $c->id, '--session' => ['2026-10-19', '2026-10-20'], '--symbol' => ['DEMO'],
            '--minutes' => '390', '--equity' => '10000', '--request' => $request];
        $this->artisan('mcp:intraday-replay', $args)->expectsOutputToContain(StrategyReplay::MULTISESSION_VERSION)->assertSuccessful();
        $run = StrategyReplayRun::firstOrFail();
        $this->assertSame('2026-10-19', $run->start_date->toDateString());
        $this->assertSame('2026-10-20', $run->end_date->toDateString());
        $this->assertCount(2, $run->payload['result']['sessions']);
        $count = count(Http::recorded());
        $this->artisan('mcp:intraday-replay', $args)->assertSuccessful();
        $this->assertCount($count, Http::recorded());
        $this->assertSame($p->cash, $p->fresh()->cash);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertSame(1, StrategyReplayRun::count());
    }
}
