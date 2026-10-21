<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Models\PaperTrading\StrategyExperiment;
use App\Models\PaperTrading\StrategyExperimentStep;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\Experiments\ExperimentAccess;
use App\Services\PaperTrading\Experiments\ExperimentDefinition;
use App\Services\PaperTrading\Experiments\StrategyExperiments;
use App\Services\PaperTrading\ExperimentTradeTable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExperimentController extends Controller
{
    public function index(Request $request, ExperimentAccess $access, StrategyExperiments $experiments)
    {
        $connection = McpConnection::where('user_id', $request->user()->id)->first();
        $ready = $connection && $connection->status === 'connected' && $connection->bound_account_id && $connection->account_role === 'agentic_monitoring';
        $runs = null;
        if ($ready) {
            $access->authorize($connection, $request->user()->id);
            $runs = StrategyExperiment::where('mcp_connection_id', $connection->id)->where('actor_id', $request->user()->id)->where('scope', AccountScope::key($connection))
                ->latest('id')->paginate(12)->withQueryString();
            $runs->setCollection($runs->getCollection()->filter(fn ($run) => $run->definition['auth_generation'] === $connection->auth_generation));
        }
        $example = json_decode(file_get_contents(base_path('docs/mcp-trading/examples/strategy-experiment.json')), true, flags: JSON_THROW_ON_ERROR);

        if ($request->filled('reuse')) {
            abort_unless($ready && is_string($request->query('reuse')) && Str::isUuid($request->query('reuse')), 404);
            $source = $experiments->find($connection, $request->user()->id, $request->query('reuse'));
            abort_unless($source->status === 'complete', 409, 'Only a completed dataset can be reused.');
            $example = $source->definition['spec'];
            $example['name'] = Str::limit($example['name'], 100, '').' · follow-up';
            $example['dataset_experiment'] = $source->experiment_id;
        }

        return response()->view('mcp.experiments.index', compact('connection', 'ready', 'runs', 'example') + ['enabled' => $this->enabled()]);
    }

    public function store(Request $request, StrategyExperiments $experiments)
    {
        $connection = $this->context($request);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'hypothesis' => ['required', 'string', 'max:2000'],
            'symbols' => ['required', 'string', 'max:64'], 'sessions' => ['required', 'string', 'max:500'], 'baseline_index' => ['required', 'regex:/^\d{1,3}$/D'],
            'variants' => ['required', 'array', 'max:8'], 'variants.*' => ['array:id,equity,parameters'],
            'variants.*.id' => ['nullable', 'string', 'max:32'], 'variants.*.equity' => ['nullable', 'string', 'max:16'],
            'variants.*.parameters' => ['sometimes', 'array:initial_stop_atr,trailing_stop_atr,max_chase_atr,time_exit_sessions,cooldown_sessions'],
            'variants.*.parameters.*' => ['nullable', 'string', 'max:16'], 'dataset_experiment' => ['nullable', 'uuid']]);
        $variants = [];
        $baseline = $data['variants'][$data['baseline_index']]['id'] ?? null;
        foreach ($data['variants'] as $index => $variant) {
            $p = array_filter($variant['parameters'] ?? [], fn ($value) => $value !== null && $value !== '');
            if (empty($variant['id']) && empty($variant['equity']) && $p === []) {
                continue;
            }
            foreach (['time_exit_sessions', 'cooldown_sessions'] as $key) {
                if (isset($p[$key])) {
                    if (! preg_match('/^[1-9]\d{0,2}$/D', $p[$key])) {
                        throw ValidationException::withMessages(['variants.'.$index.'.parameters.'.$key => 'Session counts must be whole numbers.']);
                    }
                    $p[$key] = (int) $p[$key];
                }
            }
            $variants[] = ['id' => $variant['id'] ?? '', 'equity' => $variant['equity'] ?? '', 'parameters' => $p];
        }
        $split = fn ($text) => preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $spec = ['name' => $data['name'], 'hypothesis' => $data['hypothesis'], 'symbols' => $split($data['symbols']),
            'sessions' => $split($data['sessions']), 'baseline' => $baseline ?? '', 'variants' => $variants];
        if (! empty($data['dataset_experiment'])) {
            $spec['dataset_experiment'] = $data['dataset_experiment'];
        }
        try {
            $run = $experiments->start($connection, $request->user()->id, $data['request_id'], $spec);
        } catch (HttpExceptionInterface $e) {
            return back()->withInput()->withErrors(['experiment' => $e->getMessage() ?: 'The experiment could not be started.']);
        }

        return redirect()->route('mcp.experiments.show', ['experiment' => $run->experiment_id])->with('experiment_status', 'Experiment queued. Processing continues independently of this page.');
    }

    public function show(Request $request, StrategyExperiments $experiments, ExperimentTradeTable $table, string $experiment)
    {
        $request->validate(['trades_page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'orders_page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $connection = $this->connection($request);
        $run = $experiments->find($connection, $request->user()->id, $experiment);
        $progress = $experiments->report($connection, $request->user()->id, $experiment);
        $variants = array_column($run->definition['variants'], null, 'id');
        $selected = $request->query('variant', $run->definition['spec']['baseline']);
        abort_unless(is_string($selected) && isset($variants[$selected]), 404);
        $result = null;
        $rows = [];
        $checkpoint = StrategyExperimentStep::where('experiment_id', $run->id)->where('completed_key', 'variant:'.$selected)->first();
        if ($checkpoint) {
            $payload = $this->payload($checkpoint);
            $terminal = StrategyExperimentStep::where('experiment_id', $run->id)->where('completed_key', 'session:'.last($run->definition['spec']['sessions']))->firstOrFail();
            $result = $payload['result'];
            $rows = $table->rows($result, $this->payload($terminal)['data'], $run->definition['settings']);
        }
        $trades = new LengthAwarePaginator(array_slice($rows, ($page = max(1, (int) $request->query('trades_page', 1))) * 20 - 20, 20), count($rows), 20, $page,
            ['path' => $request->url(), 'pageName' => 'trades_page', 'query' => $request->except('trades_page')]);
        $orders = collect($result['orders'] ?? [])->reverse()->values();
        $orderPage = max(1, (int) $request->query('orders_page', 1));
        $orderRows = new LengthAwarePaginator($orders->slice(($orderPage - 1) * 20, 20)->all(), $orders->count(), 20, $orderPage,
            ['path' => $request->url(), 'pageName' => 'orders_page', 'query' => $request->except('orders_page')]);
        $spec = $run->definition['spec'];
        $totalSteps = count($spec['sessions']) + count($variants) + 1;

        return response()->view('mcp.experiments.show', compact('connection', 'run', 'progress', 'variants', 'selected', 'result', 'trades', 'orderRows', 'totalSteps') + ['enabled' => $this->enabled()]);
    }

    public function report(Request $request, StrategyExperiments $experiments, string $experiment)
    {
        return response()->json($experiments->report($this->connection($request), $request->user()->id, $experiment));
    }

    public function resume(Request $request, StrategyExperiments $experiments, string $experiment)
    {
        $connection = $this->context($request);
        $experiments->find($connection, $request->user()->id, $experiment);
        try {
            $experiments->resume($connection, $request->user()->id, $experiment);
        } catch (HttpExceptionInterface $e) {
            return back()->withErrors(['experiment' => $e->getMessage() ?: 'The experiment could not be resumed.']);
        }

        return redirect()->route('mcp.experiments.show', ['experiment' => $experiment])->with('experiment_status', 'Unfinished work queued; completed checkpoints and failure history are retained.');
    }

    private function payload(StrategyExperimentStep $step): array
    {
        abort_unless($step->fingerprint === ExperimentDefinition::hash($step->payload), 409, 'Checkpoint integrity check failed.');

        return $step->payload;
    }

    private function connection(Request $request): McpConnection
    {
        $connection = McpConnection::where('user_id', $request->user()->id)->where('status', 'connected')->whereNotNull('bound_account_id')->firstOrFail();
        app(ExperimentAccess::class)->authorize($connection, $request->user()->id);

        return $connection;
    }

    private function context(Request $request): McpConnection
    {
        $data = $request->validate(['account_scope' => ['required', 'string', 'size:64'], 'auth_generation' => ['required', 'integer']]);
        $connection = $this->connection($request);
        abort_unless(hash_equals(AccountScope::key($connection), $data['account_scope']) && $connection->auth_generation === (int) $data['auth_generation'], 409, 'Account context changed. Reload the page.');

        return $connection;
    }

    private function enabled(): bool
    {
        return config('strategy_experiments.enabled') && config('alpaca_data.historical_quote_replay_enabled');
    }
}
