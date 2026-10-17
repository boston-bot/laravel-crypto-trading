<?php

namespace Tests\Feature\Research;

use App\Jobs\ResearchJob;
use App\Models\McpConnection;
use App\Models\Monitoring\ThesisVersion;
use App\Models\Research\Event;
use App\Models\Research\Source;
use App\Models\Research\WatchlistItem;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\Monitor;
use App\Services\Research\ResearchEvents;
use App\Services\Research\ResearchModel;
use App\Services\Research\ResearchRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeResearchModel;
use Tests\Support\McpFixture;
use Tests\Support\MonitoringGateway;
use Tests\Support\ResearchFixture;
use Tests\TestCase;

class ResearchDashboardTest extends TestCase
{
    use RefreshDatabase;

    private McpConnection $connection;

    private McpConnection $other;

    private User $user;

    private FakeResearchModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->model = new FakeResearchModel;
        $this->app->instance(BrokerGateway::class, new MonitoringGateway);
        $this->app->instance(ResearchModel::class, $this->model);
        $this->connection = McpFixture::connection();
        $this->other = McpFixture::connection();
        $this->user = User::findOrFail($this->connection->user_id);
        config(['mcp.operator_emails' => [$this->user->email, User::find($this->other->user_id)->email]]);
        app(Monitor::class)->sync($this->connection->id, 'seed');
        app(Monitor::class)->sync($this->other->id, 'seed-other');
    }

    public function test_research_page_requires_operator_and_shows_unavailable_state_and_coverage(): void
    {
        $this->get('/mcp/dashboard/research')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/mcp/dashboard/research')->assertForbidden();
        $this->actingAs($this->user)->get('/mcp/dashboard/research')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('unavailable (research_disabled)')->assertSee('Budget unavailable: provider_cost_unreviewed')
            ->assertSee('Model not configured')->assertSee('No (unknown)')->assertSee('no licensed news source configured')->assertSee('non-executable notes')->assertDontSee('synthetic-agentic');
        $this->actingAs($this->user)->get('/mcp/dashboard/health')->assertSee('mcp_research');
    }

    public function test_evidence_and_requests_are_scoped_csrf_protected_and_use_the_session_actor(): void
    {
        Queue::fake();
        $this->actingAs($this->user)->post('/mcp/research/evidence', ResearchFixture::evidence('SYNTH', ['published_at' => '2026-10-16T08:00', 'actor_id' => $this->other->user_id]))
            ->assertRedirect()->assertSessionHas('monitor_status', 'Evidence recorded as untrusted data.');
        $source = Source::sole();
        $this->assertSame($this->user->id, $source->actor_id);
        $this->assertSame($this->connection->id, $source->mcp_connection_id);
        $this->assertSame('2026-10-16T13:00:00+00:00', $source->source_as_of->toIso8601String()); // America/Chicago input
        $this->post('/mcp/research/evidence', ResearchFixture::evidence('NOTHELD'))->assertSessionHas('monitor_status', 'Not recorded: input is invalid or outside this account scope.');
        $this->post('/mcp/research/evidence', ResearchFixture::evidence('SYNTH', ['url' => 'http://insecure.test/x']))->assertSessionHas('monitor_status', 'Not recorded: input is invalid or outside this account scope.');
        $this->post('/mcp/research/evidence', ResearchFixture::evidence('SYNTH', ['published_at' => now()->addDay()->toIso8601String()]))->assertSessionHas('monitor_status', 'Not recorded: input is invalid or outside this account scope.');
        $this->assertSame(1, Source::count());

        $this->post('/mcp/research/request', ['symbol' => 'SYNTH', 'request_id' => (string) Str::uuid()])->assertSessionHas('monitor_status', 'Research request recorded; research unavailable: research_disabled.');
        $this->postJson('/mcp/research/request', ['symbol' => 'SYNTH', 'request_id' => 'undefined'])->assertUnprocessable();
        Queue::assertNothingPushed();
        ResearchFixture::enable();
        $repeat = ['symbol' => 'SYNTH', 'request_id' => (string) Str::uuid()];
        $this->post('/mcp/research/request', $repeat)->assertSessionHas('monitor_status', 'Research request queued for the research worker.');
        $this->post('/mcp/research/request', $repeat)->assertSessionHas('monitor_status', 'Research request already recorded.');
        $this->post('/mcp/research/request', ['thesis_key' => (string) Str::uuid()] + $repeat)->assertSessionHas('monitor_status', 'Not recorded: input is invalid or outside this account scope.');
        Queue::assertPushed(ResearchJob::class, 1);
        Queue::assertPushedOn('research', ResearchJob::class);
        $this->assertSame(0, Event::where('mcp_connection_id', $this->other->id)->count());

        $this->actingAs(User::find($this->other->user_id))->get('/mcp/dashboard/research')->assertDontSee('Synthetic fixture article');
        app()->instance('env', 'local');
        $this->actingAs($this->user)->post('/mcp/research/evidence', ResearchFixture::evidence())->assertStatus(419);
    }

    public function test_local_watchlist_adds_subjects_and_cannot_remove_another_accounts_item(): void
    {
        $this->actingAs($this->user)->post('/mcp/research/watchlist', ['symbol' => 'watch.a', 'note' => 'Synthetic note'])->assertRedirect();
        $this->post('/mcp/research/watchlist', ['symbol' => 'WATCH.A'])->assertRedirect();
        $item = WatchlistItem::sole();
        $this->assertSame(['WATCH.A', 'Synthetic note', $this->user->id], [$item->symbol, $item->note, $item->added_by]);
        $this->assertContains('WATCH.A', app(ResearchEvents::class)->subjects($this->connection));
        $this->post('/mcp/research/watchlist', ['symbol' => '1BAD'])->assertSessionHas('monitor_status', 'Not recorded: input is invalid or outside this account scope.');
        $this->actingAs(User::find($this->other->user_id))->post('/mcp/research/watchlist/'.$item->id.'/remove')->assertNotFound();
        $this->actingAs($this->user)->post('/mcp/research/watchlist/'.$item->id.'/remove')->assertRedirect();
        $this->assertNull($item->fresh()->active_symbol);
        $this->assertSame($this->user->id, $item->fresh()->removed_by);
        $this->assertNotContains('WATCH.A', app(ResearchEvents::class)->subjects($this->connection));
    }

    public function test_thesis_authoring_is_append_only_validated_and_scoped(): void
    {
        $event = app(ResearchEvents::class)->addEvidence($this->connection, $this->user->id, ResearchFixture::evidence());
        $foreign = app(ResearchEvents::class)->addEvidence($this->other, $this->other->user_id, ResearchFixture::evidence());
        $form = ['symbol' => 'SYNTH', 'sleeve' => 'long_term', 'status' => 'active', 'rationale' => 'Synthetic operator thesis.', 'horizon' => 'years',
            'next_review_at' => now()->addWeek()->format('Y-m-d\TH:i'), 'invalidation_type' => 'price', 'invalidation_operator' => 'lte', 'invalidation_threshold' => '120.50',
            'sources' => [$event->source_id], 'actor_id' => $this->other->user_id];
        $this->actingAs($this->user)->post('/mcp/theses', $form)->assertSessionHas('monitor_status', 'Thesis version recorded.');
        $thesis = ThesisVersion::sole();
        $this->assertSame([$this->user->id, 'SYNTH', 'operator', [$event->source_id]], [$thesis->actor_id, $thesis->payload['symbol'], $thesis->payload['origin'], $thesis->payload['sources']]);
        $rejected = 'Not recorded: input is invalid or outside this account scope.';
        $this->post('/mcp/theses', ['sources' => [$foreign->source_id]] + $form)->assertSessionHas('monitor_status', $rejected);
        $this->post('/mcp/theses', ['invalidation_threshold' => '1e3'] + $form)->assertSessionHas('monitor_status', $rejected);
        $this->post('/mcp/theses', ['next_review_at' => now()->setTimezone('America/Chicago')->subMinute()->format('Y-m-d\TH:i')] + $form)->assertSessionHas('monitor_status', $rejected);
        $this->post('/mcp/theses', ['thesis_key' => (string) Str::uuid()] + $form)->assertSessionHas('monitor_status', $rejected);
        $this->post('/mcp/theses', ['thesis_key' => $thesis->payload['thesis_key'], 'rationale' => 'Revised synthetic thesis.'] + $form)->assertSessionHas('monitor_status', 'Thesis version recorded.');
        $this->assertSame(2, ThesisVersion::count());
        $this->assertSame(1, ThesisVersion::all()->pluck('payload.thesis_key')->unique()->count());
        $this->get('/mcp/dashboard/holdings')->assertSee('Revised synthetic thesis.')->assertSee('Record thesis version');
    }

    public function test_operator_adopts_validated_research_as_a_new_thesis_version(): void
    {
        ResearchFixture::enable();
        $event = app(ResearchEvents::class)->addEvidence($this->connection, $this->user->id, ResearchFixture::evidence());
        $run = app(ResearchRunner::class)->process($event->id);
        $this->assertSame('succeeded', $run->status);
        $this->actingAs(User::find($this->other->user_id))->post('/mcp/research/runs/'.$run->id.'/adopt')->assertNotFound();
        $this->assertSame(0, ThesisVersion::count());
        $this->actingAs($this->user)->get('/mcp/dashboard/research')->assertSee('Adopt as new thesis version');
        $this->post('/mcp/research/runs/'.$run->id.'/adopt')->assertSessionHas('monitor_status', 'Research adopted as a new thesis version by the signed-in operator.');
        $thesis = ThesisVersion::sole();
        $this->assertSame(['research_run:'.$run->id, $this->user->id, 'unclassified', 'manual_review'],
            [$thesis->payload['origin'], $thesis->actor_id, $thesis->payload['sleeve'], $thesis->payload['invalidation']['type']]);
        $this->assertSame($run->result['material_facts'][0]['source_record_ids'], $thesis->payload['sources']);
        $this->post('/mcp/research/runs/not-a-uuid/adopt')->assertNotFound();
    }
}
