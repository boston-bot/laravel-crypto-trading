<?php

namespace Tests\Feature\PaperTrading;

use App\Jobs\PaperDecisionJob;
use App\Models\BrokerOrder;
use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\MandateVersion;
use App\Models\PaperTrading\Portfolio;
use App\Models\Research\Budget;
use App\Services\PaperTrading\DecisionInputs;
use App\Services\PaperTrading\DecisionRunner;
use App\Services\PaperTrading\DecisionScheduler;
use App\Services\PaperTrading\PaperMandates;
use App\Services\PaperTrading\PaperPolicies;
use App\Services\Research\ModelRequest;
use App\Services\Research\ModelResponse;
use App\Services\Research\OpenAiResearchModel;
use App\Services\Research\ResearchBudget;
use App\Services\Research\ResearchError;
use App\Services\Research\ResearchFailure;
use App\Services\Research\ResearchModel;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeDecisionInputs;
use Tests\Support\FakeDecisionModel;
use Tests\Support\PaperDecisionFixture;
use Tests\TestCase;

class DecisionRunnerTest extends TestCase
{
    use RefreshDatabase;

    private McpConnection $connection;

    private Portfolio $portfolio;

    private FakeDecisionModel $model;

    private FakeDecisionInputs $inputs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, , $this->portfolio] = PaperDecisionFixture::seed();
        $this->model = new FakeDecisionModel;
        $this->inputs = new FakeDecisionInputs;
        $this->app->instance(ResearchModel::class, $this->model);
        $this->app->instance(DecisionInputs::class, $this->inputs);
        Queue::fake();
    }

    private function queued(): DecisionRun
    {
        app(DecisionScheduler::class)->tick();

        return DecisionRun::latest('created_at')->latest('id')->firstOrFail();
    }

    public function test_wait_retains_actual_rules_request_usage_and_evidence_without_ledger_or_broker_writes(): void
    {
        $run = $this->queued();
        $result = app(DecisionRunner::class)->process($run->id);
        $this->assertSame('wait', $result->status);
        $this->assertSame(1, count($this->model->requests));
        $this->assertSame('Synthetic multi-week guidance.', $result->request_snapshot['input']['paper_policy']['rules']['ai_guidance']);
        $this->assertSame(1, $result->request_snapshot['input']['paper_policy']['policy_revision']);
        $this->assertSame(1, $result->request_snapshot['input']['paper_portfolio']['ledger_version']);
        $this->assertFalse($result->result['validated']['executable']);
        $this->assertSame(1000, $result->input_tokens);
        $this->assertSame('provider_usage', $result->cost_basis);
        $this->assertSame('0.000000000000000000', Budget::sole()->reserved);
        $this->assertSame(0, Decimal::from('0.0018')->compareTo(Decimal::from($result->actual_cost)));
        $this->assertStringNotContainsString('Synthetic', DB::table('mcp_paper_decision_runs')->where('id', $run->id)->sole()->request_snapshot);
        $encoded = json_encode($result->request_snapshot);
        $this->assertStringNotContainsString('synthetic-agentic', $encoded);
        $this->assertStringNotContainsString('synthetic-key', $encoded);
        $this->assertSame('10000.000000000000000000', $this->portfolio->fresh()->cash);
        $this->assertSame(0, BrokerOrder::count());
        Http::assertNothingSent();
        $job = new PaperDecisionJob($run->id, $run->scope);
        $job->handle(app(DecisionRunner::class));
        $this->assertCount(1, $this->model->requests);
    }

    public function test_disabled_defaults_and_missing_credentials_do_not_call_a_model_or_dispatch_work(): void
    {
        config(['paper.decisions_enabled' => false]);
        $this->assertSame(0, app(DecisionScheduler::class)->tick());
        $this->assertSame(0, DecisionRun::count());
        config(['paper.decisions_enabled' => true, 'research.api_key' => '']);
        $this->assertSame(0, app(DecisionScheduler::class)->tick());
        $this->assertSame('unavailable', DecisionRun::sole()->status);
        $this->assertSame('credential_missing', DecisionRun::sole()->reason);
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->model->requests);
    }

    public function test_schedule_deduplicates_current_windows_and_never_backfills_or_repeats_a_paid_attempt(): void
    {
        $first = $this->queued();
        app(DecisionScheduler::class)->tick();
        Queue::assertPushed(PaperDecisionJob::class, 1);
        $this->assertSame('research', (new PaperDecisionJob($first->id, $first->scope))->queue);
        $this->assertSame('mcp_research', (new PaperDecisionJob($first->id, $first->scope))->connection);
        $this->travel(16)->minutes();
        app(DecisionScheduler::class)->tick(true);
        $this->assertSame(2, DecisionRun::count());
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame(1, DecisionRun::where('status', 'wait')->count());
        $this->assertCount(1, $this->model->requests);
        app(DecisionRunner::class)->process($first->id);
        $this->assertCount(1, $this->model->requests);
    }

    public function test_current_applied_interval_controls_new_windows_and_policy_changes_invalidate_queued_work(): void
    {
        $first = $this->queued();
        $old = MandateVersion::sole();
        $draft = app(PaperMandates::class)->append($this->connection, $this->connection->user_id, 1, (string) Str::uuid(),
            ['decision_minutes' => 10] + $old->payload, 'Synthetic longer decision cadence.');
        app(PaperPolicies::class)->apply($this->connection, $this->connection->user_id, $draft->id, 2, 1, (string) Str::uuid());
        $this->assertSame('superseded', app(DecisionRunner::class)->process($first->id)->status);
        app(DecisionScheduler::class)->tick(true);
        $run = DecisionRun::where('status', 'wait')->sole();
        $this->assertSame('2026-10-19T14:10:00+00:00', $run->expires_at->toIso8601String());
        $this->travel(6)->minutes();
        app(DecisionScheduler::class)->tick();
        $this->assertSame(2, DecisionRun::count());
    }

    public function test_model_cannot_override_rules_or_invent_symbols_sources_positions_or_expiry(): void
    {
        foreach (['extra_policy', 'symbol', 'source', 'hold', 'expiry', 'float', 'weight'] as $bad) {
            $this->model->respond = fn ($request) => FakeDecisionModel::answer($request, function ($data) use ($bad) {
                if ($bad === 'extra_policy') {
                    $data['policy'] = ['mode' => 'bounded_auto'];
                } elseif ($bad === 'symbol') {
                    $data['decisions'][0]['symbol'] = 'UNKNOWN';
                } elseif ($bad === 'source') {
                    $data['decisions'][0]['source_ids'] = ['S999'];
                } elseif ($bad === 'hold') {
                    $data['decisions'][0]['action'] = 'HOLD';
                } elseif ($bad === 'expiry') {
                    $data['decisions'][0]['expires_at'] = now()->addDay()->utc()->format(DATE_ATOM);
                } else {
                    $data['decisions'][0]['action'] = 'BUY';
                    $data['decisions'][0]['target_weight_pct'] = $bad === 'float' ? 10.5 : '20.00000001';
                }

                return $data;
            });
            $run = $this->queued();
            $this->assertSame('rejected', app(DecisionRunner::class)->process($run->id)->status, $bad);
            $this->travel(5)->minutes();
        }
        $this->assertSame(0, BrokerOrder::count());
        $this->assertSame('10000.000000000000000000', $this->portfolio->fresh()->cash);
    }

    public function test_buy_proposal_requires_eligibility_fresh_quotes_and_still_cannot_create_orders(): void
    {
        $this->model->respond = fn ($request) => FakeDecisionModel::answer($request, function ($data) {
            $data['decisions'][0]['action'] = 'BUY';
            $data['decisions'][0]['target_weight_pct'] = '10.00';

            return $data;
        });
        $this->inputs->mutate = fn ($input) => array_replace_recursive($input, ['subjects' => ['SYNTH' => ['eligibility' => 'unknown']]]);
        $run = $this->queued();
        $this->assertSame('instrument_eligibility_unknown', app(DecisionRunner::class)->process($run->id)->reason);
        $this->travel(5)->minutes();
        $this->inputs->mutate = fn ($input) => array_replace_recursive($input, ['subjects' => ['SYNTH' => ['quote' => ['source_as_of' => now()->subMinutes(2)->utc()->format(DATE_ATOM)]]]]);
        $run = $this->queued();
        $this->assertSame('quote_unknown_or_stale', app(DecisionRunner::class)->process($run->id)->reason);
        $this->travel(5)->minutes();
        $this->inputs->mutate = fn ($input) => array_replace_recursive($input, ['subjects' => ['SYNTH' => ['quote' => ['source_as_of' => null]]]]);
        $run = $this->queued();
        $this->assertSame('quote_unknown_or_stale', app(DecisionRunner::class)->process($run->id)->reason);
        $this->travel(5)->minutes();
        $this->inputs->mutate = null;
        $run = $this->queued();
        $this->assertSame('proposed', app(DecisionRunner::class)->process($run->id)->status);
        $this->assertSame(0, BrokerOrder::count());
    }

    public function test_inflight_policy_ledger_authorization_or_disable_changes_supersede_the_paid_result(): void
    {
        foreach (['policy', 'ledger', 'authorization', 'disable'] as $change) {
            $this->model->respond = function ($request) use ($change) {
                if ($change === 'policy') {
                    $old = MandateVersion::sole();
                    $new = app(PaperMandates::class)->append($this->connection, $this->connection->user_id, 1, (string) Str::uuid(),
                        ['max_position_pct' => '10'] + $old->payload, 'Synthetic in-flight policy change.');
                    app(PaperPolicies::class)->apply($this->connection, $this->connection->user_id, $new->id, 2, 1, (string) Str::uuid());
                } elseif ($change === 'ledger') {
                    $this->portfolio->increment('ledger_version');
                } elseif ($change === 'authorization') {
                    $this->connection->increment('auth_generation');
                } else {
                    config(['paper.decisions_enabled' => false]);
                }

                return FakeDecisionModel::answer($request);
            };
            $run = $this->queued();
            $result = app(DecisionRunner::class)->process($run->id);
            $this->assertSame('superseded', $result->status, $change);
            $this->assertNotNull($result->result['raw_output']);
            $this->assertSame('provider_usage', $result->cost_basis);
            $this->assertSame('0.000000000000000000', Budget::sole()->reserved);
            config(['paper.decisions_enabled' => true]);
            $this->travel(5)->minutes();
        }
    }

    public function test_timeouts_charge_reservation_but_provider_rejections_are_unbilled(): void
    {
        foreach ([ResearchError::Timeout, ResearchError::Authentication, ResearchError::RateLimited] as $failure) {
            $this->model->respond = fn () => throw new ResearchFailure($failure);
            $run = $this->queued();
            $result = app(DecisionRunner::class)->process($run->id);
            $this->assertSame('failed', $result->status);
            $this->assertSame($failure === ResearchError::Timeout ? 'reservation_unreconciled' : 'provider_rejected', $result->cost_basis);
            $this->assertSame('0.000000000000000000', Budget::sole()->reserved);
            $this->travel(5)->minutes();
        }
    }

    public function test_shared_budget_enforces_the_stricter_paper_envelope_before_the_call(): void
    {
        config(['research.fixed_monthly_costs' => '10.00']);
        $old = MandateVersion::sole();
        $new = app(PaperMandates::class)->append($this->connection, $this->connection->user_id, 1, (string) Str::uuid(),
            ['monthly_budget' => '5.00'] + $old->payload, 'Synthetic stricter envelope.');
        app(PaperPolicies::class)->apply($this->connection, $this->connection->user_id, $new->id, 2, 1, (string) Str::uuid());
        $run = $this->queued();
        $result = app(DecisionRunner::class)->process($run->id);
        $this->assertSame('unavailable', $result->status);
        $this->assertSame('budget_exhausted', $result->reason);
        $this->assertSame('no_call', $result->cost_basis);
        $this->assertCount(0, $this->model->requests);
        $this->assertSame(0, Budget::count()); // Failed reservation is rolled back, not left committed.
    }

    public function test_recorded_request_prices_are_used_if_configuration_changes_during_the_call(): void
    {
        $this->model->respond = function ($request) {
            config(['research.input_cost_per_million' => '9000', 'research.output_cost_per_million' => '9000']);

            return FakeDecisionModel::answer($request);
        };
        $run = $this->queued();
        $result = app(DecisionRunner::class)->process($run->id);
        $this->assertSame(0, Decimal::from('0.0018')->compareTo(Decimal::from($result->actual_cost)));
        $this->assertSame('1.00', $result->price_basis['input_rate']);
    }

    public function test_tighter_paper_ceiling_does_not_relabel_the_shared_budget_history(): void
    {
        $old = MandateVersion::sole();
        $new = app(PaperMandates::class)->append($this->connection, $this->connection->user_id, 1, (string) Str::uuid(),
            ['monthly_budget' => '50.00'] + $old->payload, 'Synthetic lower paper ceiling.');
        app(PaperPolicies::class)->apply($this->connection, $this->connection->user_id, $new->id, 2, 1, (string) Str::uuid());
        app(DecisionScheduler::class)->tick(true);
        $this->assertSame('wait', DecisionRun::sole()->status);
        $this->assertSame('100.000000000000000000', Budget::sole()->envelope);
        $this->travel(1)->months();
        config(['research.monthly_envelope' => '150.00']);
        $this->assertSame(0, Decimal::from('100')->compareTo(Decimal::from(app(ResearchBudget::class)->status('2026-10')['envelope'])));
    }

    public function test_quote_and_proposal_expiry_are_rechecked_when_the_model_returns(): void
    {
        $this->model->respond = function ($request) {
            $this->travel(70)->seconds();

            return FakeDecisionModel::answer($request, function ($data) {
                $data['decisions'][0]['action'] = 'BUY';
                $data['decisions'][0]['target_weight_pct'] = '10';

                return $data;
            });
        };
        $run = $this->queued();
        $this->assertSame('quote_unknown_or_stale', app(DecisionRunner::class)->process($run->id)->reason);
    }

    public function test_the_existing_openai_transport_sends_the_new_schema_without_tools_or_storage(): void
    {
        $this->app->instance(ResearchModel::class, new OpenAiResearchModel);
        Http::fake([OpenAiResearchModel::ENDPOINT => function ($http) {
            $body = $http->data();
            $this->assertSame('paper_decision_v1', $body['text']['format']['name']);
            $this->assertTrue($body['text']['format']['strict']);
            $this->assertFalse($body['store']);
            $this->assertArrayNotHasKey('tools', $body);
            $answer = FakeDecisionModel::answer(new ModelRequest($body['model'], $body['instructions'], $body['input'][0]['content'][0]['text'], $body['text']['format']['schema'], 'paper_decision_v1', $body['max_output_tokens']));

            return Http::response(['status' => 'completed', 'model' => $body['model'], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $answer->text]]]]]);
        }]);
        $run = $this->queued();
        $this->assertSame('wait', app(DecisionRunner::class)->process($run->id)->status);
        Http::assertSentCount(1);
    }

    public function test_expired_worker_is_charged_once_and_a_late_response_is_not_promoted_or_replayed(): void
    {
        $this->model->respond = function ($request) {
            $this->travel(4)->minutes();
            app(DecisionRunner::class)->sweep();

            return FakeDecisionModel::answer($request);
        };
        $run = $this->queued();
        $result = app(DecisionRunner::class)->process($run->id);
        $this->assertSame('abandoned', $result->status);
        $this->assertTrue($result->result['late_response']);
        $charged = Budget::sole()->actual;
        $this->assertSame('reservation_unreconciled', $result->cost_basis);
        app(DecisionRunner::class)->process($run->id);
        app(DecisionRunner::class)->sweep();
        $this->assertSame($charged, Budget::sole()->actual);
        $this->assertCount(1, $this->model->requests);
        $this->assertNull($result->active_portfolio_id);
    }

    public function test_refusals_incomplete_and_oversized_outputs_remain_visible_and_missing_usage_is_not_zero(): void
    {
        foreach (['refused', 'incomplete', 'oversized', 'usage_unknown'] as $case) {
            $this->model->respond = function ($request) use ($case) {
                $answer = FakeDecisionModel::answer($request);

                return new ModelResponse($case === 'refused' || $case === 'incomplete' ? $case : 'completed',
                    $case === 'oversized' ? str_repeat('x', 40000) : $answer->text,
                    $case === 'usage_unknown' ? null : 1000, $case === 'usage_unknown' ? null : 200, $request->model);
            };
            $run = $this->queued();
            $result = app(DecisionRunner::class)->process($run->id);
            $this->assertSame(match ($case) {
                'usage_unknown' => 'wait', 'oversized' => 'rejected', default => 'failed'
            }, $result->status);
            $this->assertSame('0.000000000000000000', Budget::sole()->reserved);
            if ($case === 'oversized') {
                $this->assertTrue($result->result['raw_output_truncated']);
                $this->assertSame(32768, strlen($result->result['raw_output']));
            }
            if ($case === 'usage_unknown') {
                $this->assertSame('reservation_unreconciled', $result->cost_basis);
                $this->assertSame(1, Decimal::from($result->actual_cost)->compareTo(Decimal::from('0')));
                $this->assertNull($result->input_tokens);
            }
            $this->travel(5)->minutes();
        }
    }
}
