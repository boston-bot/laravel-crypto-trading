<?php

namespace App\Services\Operations;

use App\Http\Resources\ResearchRunResource;
use App\Http\Resources\StrategyExperimentResource;
use App\Models\BacktestRun;
use App\Models\StrategyExperiment;
use Illuminate\Http\Request;

class ResearchLabQueryService
{
    /** @return array<string, mixed> */
    public function experiments(int $page = 1, int $perPage = 10): array
    {
        $perPage = max(1, min(25, $perPage));
        $paginator = StrategyExperiment::query()->with($this->relations())->latest('id')->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'meta' => $this->meta($paginator->total() ? 'measured' : 'not_measured'),
            'experiments' => StrategyExperimentResource::collection($paginator->getCollection())->resolve(Request::create('/')),
            'pagination' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function experiment(StrategyExperiment $experiment): array
    {
        $experiment->load($this->relations());

        return [
            'meta' => $this->meta($experiment->runs->isEmpty() ? 'not_measured' : 'measured'),
            'experiment' => (new StrategyExperimentResource($experiment))->resolve(Request::create('/')),
        ];
    }

    /** @return array<string, mixed> */
    public function runs(?int $experimentId = null, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(50, $perPage));
        $query = BacktestRun::query()->with(['candidate', 'metrics']);
        if ($experimentId !== null) {
            $query->where('strategy_experiment_id', $experimentId);
        }
        $paginator = $query->latest('id')->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'meta' => $this->meta($paginator->total() ? 'measured' : 'not_measured'),
            'runs' => ResearchRunResource::collection($paginator->getCollection())->resolve(Request::create('/')),
            'pagination' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ];
    }

    /** @return array<int, string|callable> */
    private function relations(): array
    {
        return [
            'universeVersion',
            'holdoutInterval',
            'runs' => fn ($query) => $query->with(['candidate', 'metrics'])->latest('id')->limit(100),
            'candidates' => fn ($query) => $query->with([
                'strategyVersions',
                'runs' => fn ($runs) => $runs->with(['candidate', 'metrics'])->latest('id')->limit(25),
            ])->orderBy('search_order'),
        ];
    }

    /** @return array<string, mixed> */
    private function meta(string $measurementState): array
    {
        return ['generated_at' => now()->toIso8601String(), 'local_only' => true, 'read_only' => true, 'measurement_state' => $measurementState];
    }
}
