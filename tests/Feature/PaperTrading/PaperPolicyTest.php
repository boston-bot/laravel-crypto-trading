<?php

namespace Tests\Feature\PaperTrading;

use App\Models\BrokerOrder;
use App\Models\McpConnection;
use App\Models\Monitoring\PolicyVersion;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\MandateVersion;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\PaperMandates;
use App\Services\PaperTrading\PaperPolicies;
use App\Support\Mcp\Decimal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\McpFixture;
use Tests\TestCase;

class PaperPolicyTest extends TestCase
{
    use RefreshDatabase;

    private McpConnection $connection;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = McpFixture::connection();
        $this->user = User::findOrFail($this->connection->user_id);
        config(['mcp.operator_emails' => [$this->user->email]]);
        Queue::fake();
        Http::fake();
    }

    private function draft(array $changes = [], int $base = 0): MandateVersion
    {
        return app(PaperMandates::class)->append($this->connection, $this->user->id, $base, (string) Str::uuid(), array_replace([
            'initial_cash' => '10000.00', 'long_term_pct' => '40', 'active_pct' => '50', 'cash_pct' => '10',
            'max_position_pct' => '20', 'daily_loss_pause_pct' => '2', 'monthly_budget' => '100.00',
            'scan_seconds' => 60, 'decision_minutes' => 5, 'news_minutes' => 5, 'ai_guidance' => 'Synthetic multi-week preference.',
        ], $changes), 'Synthetic policy fixture.');
    }

    private function form(int $draft = 1, int $policy = 0): array
    {
        return ['base_version' => $draft, 'base_policy_revision' => $policy, 'request_id' => (string) Str::uuid(),
            'account_scope' => AccountScope::key($this->connection), 'confirm_paper' => '1'];
    }

    private function apply(MandateVersion $draft, array $form = [])
    {
        return $this->actingAs($this->user)->post('/mcp/control/versions/'.$draft->id.'/apply-paper', $form ?: $this->form($draft->version));
    }

    public function test_apply_requires_operator_csrf_and_explicit_paper_confirmation(): void
    {
        $draft = $this->draft();
        $url = '/mcp/control/versions/'.$draft->id.'/apply-paper';
        $this->post($url, $this->form())->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post($url, $this->form())->assertForbidden();
        $this->actingAs($this->user)->postJson($url, ['confirm_paper' => '0'] + $this->form())->assertUnprocessable();
        app()->instance('env', 'local');
        $this->post($url, $this->form())->assertStatus(419);
        $this->assertSame(0, Portfolio::count());
    }

    public function test_applying_initializes_only_the_virtual_ledger_once_and_saves_exact_applied_rules(): void
    {
        $draft = $this->draft(['initial_cash' => '0.10000001']);
        $form = $this->form();
        $this->apply($draft, $form)->assertRedirect('/mcp/dashboard/control');
        $this->apply($draft, $form)->assertRedirect();
        $this->assertSame(1, Portfolio::count());
        $this->assertSame(1, CashEntry::count());
        $this->assertSame(1, PolicyApplication::count());
        $portfolio = Portfolio::sole();
        $application = PolicyApplication::sole();
        $this->assertSame(0, Decimal::from('0.10000001')->compareTo(Decimal::from($portfolio->cash)));
        $this->assertSame($portfolio->cash, CashEntry::sole()->balance_after);
        $this->assertSame($this->user->id, $application->actor_id);
        $this->assertSame($draft->payload, $application->snapshot['rules']);
        $this->assertSame('paper', $application->snapshot['mode']);
        $this->assertStringNotContainsString('Synthetic', DB::table('mcp_paper_policy_applications')->sole()->snapshot);
        $this->assertSame(0, PolicyVersion::count());
        $this->assertSame(0, BrokerOrder::count());
        $this->assertFalse(config('research.enabled'));
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $state = app(PaperPolicies::class)->state($this->connection);
        $this->assertSame(0, Decimal::from($state['available_cash'])->compareTo(Decimal::from($portfolio->cash)));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Applied paper plan and virtual cash')->assertSee('paper policy revision 1')
            ->assertSee('automatic execution is unavailable')->assertSee('Synthetic multi-week preference.');
    }

    public function test_incomplete_drafts_cannot_create_a_portfolio(): void
    {
        $draft = $this->draft(['max_position_pct' => null]);
        $this->apply($draft)->assertSessionHasErrors('max_position_pct');
        $this->assertSame(0, Portfolio::count());
        $this->assertSame(0, CashEntry::count());
        $this->assertSame(0, PolicyApplication::count());
    }

    public function test_new_drafts_and_restore_do_not_replace_applied_rules_until_explicit_application(): void
    {
        $first = $this->draft();
        $this->apply($first)->assertRedirect();
        $second = $this->draft(['decision_minutes' => 10, 'ai_guidance' => 'Synthetic new preference.'], 1);
        $state = app(PaperPolicies::class)->state($this->connection);
        $this->assertSame($first->id, $state['application']->mandate_version_id);
        $this->assertSame(5, $state['application']->snapshot['rules']['decision_minutes']);
        $this->apply($second, $this->form(2, 1))->assertRedirect();
        $restored = app(PaperMandates::class)->append($this->connection, $this->user->id, 2, (string) Str::uuid(), [], 'Synthetic restoration.', $first->id);
        $this->assertSame($second->id, app(PaperPolicies::class)->state($this->connection)['application']->mandate_version_id);
        $this->apply($restored, $this->form(3, 2))->assertRedirect();
        $this->assertSame(3, PolicyApplication::count());
        $this->assertSame(1, CashEntry::count());
        $this->assertSame(1, Portfolio::sole()->ledger_version);
        $this->assertSame(5, app(PaperPolicies::class)->state($this->connection)['application']->snapshot['rules']['decision_minutes']);
    }

    public function test_policy_changes_preserve_current_cash_reservations_and_the_original_capital(): void
    {
        $this->apply($this->draft())->assertRedirect();
        // A committed synthetic prior cash event stands in for future accounting; no fill is claimed.
        $portfolio = Portfolio::sole();
        $portfolio->update(['cash' => '9000.01', 'reserved_cash' => '100.01', 'ledger_version' => 2]);
        CashEntry::create(['paper_portfolio_id' => $portfolio->id, 'actor_id' => $this->user->id, 'reference' => 'fixture:prior-cash-event',
            'kind' => 'fixture_cash_event', 'amount' => '-999.99', 'balance_after' => '9000.01', 'ledger_version' => 2, 'recorded_at' => now()]);
        $changed = $this->draft(['decision_minutes' => 10], 1);
        $this->apply($changed, $this->form(2, 1))->assertRedirect();
        $state = app(PaperPolicies::class)->state($this->connection);
        $this->assertSame(0, Decimal::from('8900.00')->compareTo(Decimal::from($state['available_cash'])));
        $this->assertSame(2, $state['portfolio']->ledger_version);
        $this->assertSame(2, CashEntry::count());
        $invalid = $this->draft(['initial_cash' => '20000'], 2);
        $this->apply($invalid, $this->form(3, 2))->assertSessionHasErrors('initial_cash');
        $this->assertSame(2, Portfolio::sole()->policy_revision);
        $this->assertSame(2, PolicyApplication::count());
    }

    public function test_stale_policy_draft_scope_and_request_reuse_are_rejected(): void
    {
        $first = $this->draft();
        $form = $this->form();
        $this->apply($first, $form)->assertRedirect();
        $second = $this->draft(['decision_minutes' => 10], 1);
        $this->apply($first, $this->form(1, 1))->assertConflict();
        $this->apply($second, $this->form(2, 0))->assertConflict();
        $this->apply($second, ['base_version' => 2, 'base_policy_revision' => 1] + $form)->assertConflict();
        $oldBinding = $this->form(2, 1);
        $staleConnection = $this->connection->fresh();
        $this->connection->update(['bound_account_id' => 'different-fixture-binding']);
        $this->apply($second, $oldBinding)->assertConflict();
        $this->assertNull(app(PaperPolicies::class)->state($this->connection));
        $this->assertNull(app(PaperPolicies::class)->state($staleConnection));
        $this->apply($second, $this->form(2, 0))->assertNotFound();
        $this->assertSame(1, PolicyApplication::count());
    }

    public function test_foreign_draft_cannot_be_applied_and_no_identity_comes_from_the_form(): void
    {
        $foreign = McpFixture::connection();
        $draft = app(PaperMandates::class)->append($foreign, $foreign->user_id, 0, (string) Str::uuid(), [], 'Foreign fixture.');
        $this->apply($draft)->assertNotFound();
        $own = $this->draft();
        $this->apply($own, ['actor_id' => $foreign->user_id, 'mode' => 'bounded_auto', 'brokerage_writes_enabled' => true] + $this->form())->assertRedirect();
        $this->assertSame($this->user->id, PolicyApplication::sole()->actor_id);
        $this->assertSame('paper', PolicyApplication::sole()->snapshot['mode']);
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
    }

    public function test_failure_during_application_rolls_back_opening_cash_and_portfolio(): void
    {
        $draft = $this->draft();
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into "mcp_paper_policy_applications"')) {
                throw new \RuntimeException('Synthetic application failure.');
            }
        });
        try {
            app(PaperPolicies::class)->apply($this->connection, $this->user->id, $draft->id, 1, 0, (string) Str::uuid());
            $this->fail('The synthetic failure was not observed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic application failure.', $error->getMessage());
        }
        $this->assertSame(0, Portfolio::count());
        $this->assertSame(0, CashEntry::count());
        $this->assertSame(0, PolicyApplication::count());
    }

    public function test_authoritative_postgres_rejects_negative_available_cash_at_the_database_boundary(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL decimal/check-constraint behavior.');
        }
        $this->apply($this->draft())->assertRedirect();
        try {
            DB::transaction(fn () => Portfolio::query()->update(['cash' => '0', 'reserved_cash' => '0.000000000000000001']));
            $this->fail('Invalid reservation passed the database constraint.');
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->errorInfo[0]);
        }
        $this->assertSame('0.000000000000000000', Portfolio::sole()->reserved_cash);
    }
}
