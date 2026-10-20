<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\SessionValuation;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\PaperSessionValuations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperActionFixture as A;
use Tests\TestCase;

class PaperSessionValuationsTest extends TestCase
{
    use RefreshDatabase;

    private function openingFixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T13:00:00Z'));
        [$c, $p] = E::seed();
        config(['paper.session_valuations_enabled' => true, 'paper.corporate_actions_enabled' => true]);
        SimulationPosition::create(['paper_portfolio_id' => $p->id, 'symbol' => 'DEMO', 'sleeve' => 'active', 'quantity' => '2',
            'reserved_quantity' => '0', 'cost_basis' => '200', 'realized_pnl' => '0', 'updated_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T13:30:10Z'));

        return [$c, $p];
    }

    public function test_opening_bid_marks_are_immutable_exact_scoped_and_reused_after_later_cash_movements(): void
    {
        [$c, $p] = $this->openingFixture();
        $input = A::input($c, [], ['auth_generation' => $c->auth_generation, 'quote.bid' => '99.12345678']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $service = app(PaperSessionValuations::class);
        $result = $service->capture($c, $c->user_id);
        $this->assertSame('complete', $result['status'], json_encode($result));
        $v = $result['valuation'];
        $this->assertSame('10198.246913560000000000', $v->equity);
        $this->assertSame($input->id, $v->payload['market_input_ids']['DEMO']);
        $this->assertStringNotContainsString('market_input_ids', DB::table('mcp_paper_session_valuations')->value('payload'));
        CashEntry::create(['paper_portfolio_id' => $p->id, 'actor_id' => $c->user_id, 'reference' => 'synthetic-test', 'kind' => 'synthetic_test', 'amount' => '-1', 'balance_after' => '9999', 'ledger_version' => 2, 'recorded_at' => now()]);
        $this->assertSame($v->id, $service->capture($c, $c->user_id)['valuation']->id);
        $resolved = $service->resolve($c, $p, app(MarketCalendar::class)->at(CarbonImmutable::now()));
        $this->assertSame($v->equity, $resolved['equity']);
        $this->assertSame(1, SessionValuation::count());
        Http::assertNothingSent();
    }

    public function test_a_later_quote_cannot_substitute_for_missing_opening_evidence(): void
    {
        [$c] = $this->openingFixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T13:31:00Z'));
        A::input($c, [], ['auth_generation' => $c->auth_generation]);
        $r = app(PaperSessionValuations::class)->capture($c, $c->user_id);
        $this->assertSame('opening_quote_missing_or_unverified', $r['reason']);
        $this->assertSame(0, SessionValuation::count());
    }

    public function test_first_capture_refuses_a_changed_ledger_or_changed_authorization(): void
    {
        [$c, $p] = $this->openingFixture();
        A::input($c, [], ['auth_generation' => $c->auth_generation]);
        CashEntry::create(['paper_portfolio_id' => $p->id, 'actor_id' => $c->user_id, 'reference' => 'synthetic-test', 'kind' => 'synthetic_test', 'amount' => '-1', 'balance_after' => '9999', 'ledger_version' => 2, 'recorded_at' => now()]);
        $this->assertSame('ledger_changed_since_session_open', app(PaperSessionValuations::class)->capture($c, $c->user_id)['reason']);
        $c->increment('auth_generation');
        $this->assertSame('ledger_changed_since_session_open', app(PaperSessionValuations::class)->capture($c->fresh(), $c->user_id)['reason']);
        $this->assertSame(0, SessionValuation::count());
    }

    public function test_unknown_actions_future_source_and_old_authorization_are_not_opening_evidence(): void
    {
        [$c] = $this->openingFixture();
        A::input($c, [], ['auth_generation' => $c->auth_generation - 1]);
        A::input($c, [], ['auth_generation' => $c->auth_generation, 'quote.bid_as_of' => now()->addSecond()->toISOString()]);
        A::input($c, [], ['auth_generation' => $c->auth_generation, 'corporate_action_reads.alpaca' => ['complete' => false]]);
        $this->assertSame('opening_quote_missing_or_unverified', app(PaperSessionValuations::class)->capture($c, $c->user_id)['reason']);
        config(['paper.session_valuations_enabled' => false]);
        $this->assertSame('session_valuations_disabled', app(PaperSessionValuations::class)->capture($c, $c->user_id)['reason']);
    }

    public function test_capture_refuses_a_blocked_position_and_does_not_adopt_a_previous_generations_valuation(): void
    {
        [$c] = $this->openingFixture();
        A::input($c, [], ['auth_generation' => $c->auth_generation]);
        $service = app(PaperSessionValuations::class);
        $this->assertSame('complete', $service->capture($c, $c->user_id)['status']);
        $c->increment('auth_generation');
        $this->assertSame('opening_quote_missing_or_unverified', $service->capture($c, $c->user_id)['reason']);
        SimulationPosition::sole()->update(['blocked_reason' => 'synthetic_action_review']);
        $this->assertSame('opening_accounting_unverified', $service->capture($c, $c->user_id)['reason']);
        $this->assertSame(1, SessionValuation::count());
    }
}
