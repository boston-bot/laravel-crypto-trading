<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\StrategyExperiment;
use App\Models\PaperTrading\StrategyExperimentStep as Step;
use App\Services\PaperTrading\Experiments\ExperimentDefinition;
use App\Services\PaperTrading\Experiments\StrategyExperiments;
use App\Services\PaperTrading\SignalExecutionPolicies;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\ExperimentFixture as F;
use Tests\Support\SignalExecutionFixture;
use Tests\TestCase;

class StrategyExperimentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
        F::enable();
    }

    private function start(?array $spec = null): array
    {
        [$c, $p] = E::seed();
        $row = app(StrategyExperiments::class)->start($c, $c->user_id, (string) Str::uuid(), $spec ?? F::spec());

        return [$c, $p, $row];
    }

    private function drain(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $job = Queue::connection('mcp_experiments')->pop('experiments');
            if (! $job) {
                return;
            }
            $job->fire();
        }
        $this->fail('Experiment did not drain its bounded queue.');
    }

    public function test_database_queue_runs_complete_comparisons_without_an_llm_or_forward_ledger(): void
    {
        F::sources();
        [$c, $p, $row] = $this->start();
        $this->assertSame('queued', $row->status);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'experiments')->count());
        Http::assertNothingSent();
        $this->drain();
        $row->refresh();
        $this->assertSame('complete', $row->status, $row->reason ?? '');
        $this->assertSame(5, Step::where('experiment_id', $row->id)->count());
        $this->assertTrue($row->report['comparisons']['capital']['same_dataset']);
        $this->assertTrue($row->report['comparisons']['capital']['capital_changed']);
        $this->assertSame('passed', $row->report['variants']['capital']['verification']['status']);
        $this->assertSame('500.000000000000000000', $row->report['variants']['baseline']['final_equity']);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertSame($p->cash, $p->fresh()->cash);
        $this->assertSame(160, count(Http::recorded(fn ($q) => str_starts_with($q->url(), 'https://data.alpaca.markets/v2/stocks/quotes'))));
        $this->assertStringNotContainsString('hypothesis', DB::table('mcp_strategy_experiments')->where('id', $row->id)->value('definition'));
        $this->assertStringNotContainsString('daily_reads', DB::table('mcp_strategy_experiment_steps')->where('experiment_id', $row->id)->value('payload'));
        $this->artisan('mcp:experiment', ['action' => 'report', '--account' => (string) $c->id, '--experiment' => $row->experiment_id])
            ->expectsOutputToContain('retrospective_sampled_quote_scenario_not_forward_or_point_in_time')->assertSuccessful();
    }

    public function test_start_request_is_idempotent_but_changed_specification_conflicts(): void
    {
        [$c, , $row] = $this->start();
        $runner = app(StrategyExperiments::class);
        $this->assertSame($row->id, $runner->start($c, $c->user_id, $row->request_id, F::spec())->id);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->expectException(HttpException::class);
        $runner->start($c, $c->user_id, $row->request_id, F::spec(['hypothesis' => 'Changed question.']));
    }

    public function test_experiments_freeze_the_applied_fractional_market_policy_without_creating_forward_orders(): void
    {
        [$c, $p] = SignalExecutionFixture::seed();
        E::apply($c, ['order_type' => 'market', 'allow_fractional' => true]);
        app(SignalExecutionPolicies::class)->apply($c, $c->user_id, 1, (string) Str::uuid(),
            array_replace(SignalExecutionPolicies::DEFAULTS, ['allow_fractional' => true, 'slippage_bps' => '5']));
        Http::swap((new Factory)->preventStrayRequests());
        F::sources();
        $before = $p->fresh()->cash;
        $row = app(StrategyExperiments::class)->start($c, $c->user_id, (string) Str::uuid(), F::spec());
        $this->assertTrue($row->definition['settings']['allow_fractional']);
        $this->drain();
        $row->refresh();
        $this->assertSame('complete', $row->status, $row->reason ?? '');
        $this->assertGreaterThan(0, $row->report['variants']['baseline']['fills']);
        $this->assertSame('passed', $row->report['variants']['baseline']['verification']['status']);
        $this->assertContains('symbol_fractional_eligibility_assumed', $row->report['variants']['baseline']['limitations']);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertSame($before, $p->fresh()->cash);
    }

    public function test_transient_reads_retry_boundedly_and_resume_keeps_prior_checkpoints_and_attempt_history(): void
    {
        $deny = true;
        F::sources(function () use (&$deny) {
            return $deny ? Http::response('', 429) : null;
        });
        [$c, , $row] = $this->start();
        for ($i = 0; $i < 3; $i++) {
            $this->drain();
            $this->travel(4)->minutes();
        }
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(3, Step::where('experiment_id', $row->id)->where('status', 'failed')->count());
        $deny = false;
        app(StrategyExperiments::class)->resume($c, $c->user_id, $row->experiment_id);
        $this->drain();
        $this->assertSame('complete', $row->fresh()->status, $row->fresh()->reason ?? '');
        $this->assertSame(8, Step::where('experiment_id', $row->id)->count());
        $this->assertSame(4, Step::where('experiment_id', $row->id)->where('completed_key', 'session:2026-10-19')->value('attempt'));
    }

    public function test_completed_dataset_can_be_reused_for_a_new_variant_without_provider_requests(): void
    {
        F::sources();
        [$c, , $row] = $this->start();
        $this->drain();
        $count = count(Http::recorded());
        $spec = F::spec(['dataset_experiment' => $row->experiment_id, 'variants' => [['id' => 'baseline', 'equity' => '500'],
            ['id' => 'wider_stop', 'equity' => '500', 'parameters' => ['initial_stop_atr' => '3']], ['id' => 'capital', 'equity' => '10000']]]);
        $next = app(StrategyExperiments::class)->start($c, $c->user_id, (string) Str::uuid(), $spec);
        $this->drain();
        $this->assertSame('complete', $next->fresh()->status, $next->fresh()->reason ?? '');
        $this->assertCount($count, Http::recorded());
        $this->assertTrue($next->fresh()->report['reference']['comparisons']['wider_stop']['comparable']);
        $this->assertFalse($next->fresh()->report['reference']['comparisons']['capital']['comparable']);
        $this->assertNull($next->fresh()->report['reference']['comparisons']['capital']['return_difference_percentage_points']);
        $this->assertSame($row->experiment_id, $next->fresh()->report['reference']['comparisons']['baseline']['reference_experiment']);
        $this->assertSame($row->fresh()->report['dataset_hash'], $next->fresh()->report['dataset_hash']);
    }

    public function test_gate_and_authorization_are_rechecked_after_http_and_before_checkpoint_commit(): void
    {
        [$c, , $row] = $this->start();
        F::sources(function () {
            config(['strategy_experiments.enabled' => false]);

            return null;
        });
        $this->drain();
        $this->assertSame('paused', $row->fresh()->status);
        $this->assertSame(0, Step::where('experiment_id', $row->id)->count());
        $this->assertNull($row->fresh()->lease_token);
        $this->assertSame(0, SimulationOrder::count());
        $this->artisan('mcp:experiment', ['action' => 'report', '--account' => (string) $c->id, '--experiment' => $row->experiment_id])->assertSuccessful();
    }

    public function test_expired_claim_restarts_the_unfinished_step_and_completed_sessions_are_not_refetched(): void
    {
        F::sources();
        [$c, , $row] = $this->start();
        app(StrategyExperiments::class)->work($row->id);
        $count = count(Http::recorded());
        $row->refresh()->update(['status' => 'running', 'current_step' => 'session:2026-10-20', 'step_attempt' => 1,
            'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subSecond()]);
        app(StrategyExperiments::class)->resume($c, $c->user_id, $row->experiment_id);
        $this->drain();
        $this->assertSame('complete', $row->fresh()->status, $row->fresh()->reason ?? '');
        $this->assertSame($count * 2, count(Http::recorded()));
        $this->assertSame(2, Step::where('experiment_id', $row->id)->where('completed_key', 'session:2026-10-20')->value('attempt'));
    }

    public function test_a_changed_source_snapshot_pauses_work_without_http_and_cannot_be_resumed(): void
    {
        [$c, , $row] = $this->start();
        $definition = $row->definition;
        $definition['source_hashes']['composer.lock'] = str_repeat('0', 64);
        // Use Laravel's encrypted-array cast, while bypassing model immutability to simulate corruption.
        $copy = new StrategyExperiment;
        $copy->definition = $definition;
        DB::table('mcp_strategy_experiments')->where('id', $row->id)->update(['definition' => $copy->getAttributes()['definition'], 'definition_hash' => ExperimentDefinition::hash($definition)]);
        $this->drain();
        $this->assertSame('paused', $row->fresh()->status);
        Http::assertNothingSent();
        $this->expectException(HttpException::class);
        app(StrategyExperiments::class)->resume($c, $c->user_id, $row->experiment_id);
    }

    public function test_a_late_worker_cannot_commit_or_release_a_newer_lease(): void
    {
        [, , $row] = $this->start();
        $replacement = (string) Str::uuid();
        $changed = false;
        F::sources(function () use ($row, $replacement, &$changed) {
            if (! $changed) {
                $changed = true;
                DB::table('mcp_strategy_experiments')->where('id', $row->id)->update(['lease_token' => $replacement, 'lease_expires_at' => now()->addMinutes(5)]);
            }

            return null;
        });
        app(StrategyExperiments::class)->work($row->id);
        $this->assertSame($replacement, $row->fresh()->lease_token);
        $this->assertSame(0, Step::where('experiment_id', $row->id)->count());
        $this->assertGreaterThan(0, app(StrategyExperiments::class)->work($row->id));
    }

    public function test_queue_insertion_failure_rolls_back_the_experiment_definition(): void
    {
        [$c] = E::seed();
        config(['queue.connections.mcp_experiments.table' => 'missing_experiment_jobs']);
        try {
            app(StrategyExperiments::class)->start($c, $c->user_id, (string) Str::uuid(), F::spec());
            $this->fail('Missing queue table accepted.');
        } catch (QueryException) {
            $this->assertSame(0, StrategyExperiment::count());
        }
        Http::assertNothingSent();
    }

    public function test_authorization_generation_change_during_acquisition_discards_the_checkpoint(): void
    {
        [$c, , $row] = $this->start();
        $changed = false;
        F::sources(function () use ($c, &$changed) {
            if (! $changed) {
                $changed = true;
                $c->increment('auth_generation');
            }

            return null;
        });
        app(StrategyExperiments::class)->work($row->id);
        $this->assertSame('paused', $row->fresh()->status);
        $this->assertSame(0, Step::where('experiment_id', $row->id)->count());
    }

    public function test_cli_start_reads_a_json_spec_and_disabled_start_does_not_create_work(): void
    {
        [$c] = E::seed();
        $file = tempnam(sys_get_temp_dir(), 'mcp-experiment-spec-');
        try {
            file_put_contents($file, json_encode(F::spec(), JSON_THROW_ON_ERROR));
            chmod($file, 0600);
            $this->artisan('mcp:experiment', ['action' => 'start', '--account' => (string) $c->id, '--spec' => $file])
                ->expectsOutputToContain('experiment_id')->assertSuccessful();
            $this->assertSame(1, StrategyExperiment::count());
            config(['strategy_experiments.enabled' => false]);
            $this->artisan('mcp:experiment', ['action' => 'start', '--account' => (string) $c->id, '--spec' => $file])->assertFailed();
            $this->assertSame(1, StrategyExperiment::count());
            Http::assertNothingSent();
        } finally {
            unlink($file);
        }
    }
}
