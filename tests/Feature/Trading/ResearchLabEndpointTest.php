<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\UniverseVersion;
use App\Services\Research\ExperimentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchLabEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_experiment_list_detail_and_runs_are_bounded_and_measurement_aware(): void
    {
        $experiment = $this->experiment();
        $experiment->runs()->oldest('id')->firstOrFail()->update([
            'status' => 'completed',
            'run_completed_at' => now(),
            'result_json' => [
                'equity' => [['equity' => 100], ['equity' => 90], ['equity' => 108]],
                'stressed_equity' => [['equity' => 100], ['equity' => 85], ['equity' => 97]],
                'gate' => ['status' => 'passed', 'reasons' => []],
            ],
        ]);

        $this->getJson('/api/ops/v1/research-lab/experiments?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.experiments.0.name', 'research-lab-contract')
            ->assertJsonPath('data.experiments.0.performance.measurement_state', 'incomplete')
            ->assertJsonCount(4, 'data.experiments.0.candidates');

        $this->getJson('/api/ops/v1/research-lab/experiments/'.$experiment->id)
            ->assertOk()
            ->assertJsonPath('data.experiment.holdout.status', 'locked')
            ->assertJsonPath('data.experiment.holdout.values_revealed', false)
            ->assertJsonPath('data.experiment.holdout.result', null)
            ->assertJsonPath('data.experiment.candidates.0.runs.0.series.normal_drawdown.1', -10)
            ->assertJsonPath('data.experiment.candidates.0.runs.0.series.stressed_drawdown.1', -15)
            ->assertJsonStructure(['data' => ['experiment' => ['candidates', 'constraints', 'policies', 'lineage']]]);

        $this->getJson('/api/ops/v1/research-lab/runs?experiment_id='.$experiment->id.'&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonCount(2, 'data.runs')
            ->assertJsonPath('data.runs.0.performance.measurement_state', 'not_measured');
    }

    public function test_research_lab_endpoints_are_loopback_only(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.15'])
            ->getJson('/api/ops/v1/research-lab/experiments')
            ->assertForbidden();
    }

    private function experiment()
    {
        $universe = UniverseVersion::query()->create([
            'name' => 'lab-test', 'version' => 'v1', 'status' => 'research',
            'content_hash' => hash('sha256', 'lab-universe'), 'symbols_json' => ['BTC', 'ETH', 'SOL'],
        ]);
        $assets = collect(['BTC', 'ETH', 'SOL'])->map(fn (string $symbol): array => Asset::query()->create([
            'broker' => 'coinbase', 'symbol' => $symbol, 'asset_type' => 'crypto', 'is_tradable' => true, 'is_enabled' => true,
        ])->only(['id', 'symbol']));

        return app(ExperimentService::class)->create([
            'name' => 'research-lab-contract',
            'universe_version_id' => $universe->id,
            'development_start' => '2019-01-01T00:00:00Z',
            'development_end' => '2025-01-01T00:00:00Z',
            'holdout_start' => '2025-01-01T00:00:00Z',
            'holdout_end' => '2026-01-01T00:00:00Z',
            'initial_capital' => 100_000,
            'assets' => $assets->all(),
        ]);
    }
}
