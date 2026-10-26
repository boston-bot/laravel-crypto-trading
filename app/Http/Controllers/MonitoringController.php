<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\Monitoring\ThesisVersion;
use App\Models\Operations\NotificationDelivery;
use App\Models\PaperTrading\EligibilityAssessment;
use App\Models\PaperTrading\MandateVersion;
use App\Models\PaperTrading\StrategySignal;
use App\Models\Research\Run as ResearchRun;
use App\Models\Research\Source as ResearchSource;
use App\Services\Execution\LiveExecutionOverview;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\Monitor;
use App\Services\Monitoring\RiskObservations;
use App\Services\Operations\OperationsHealth;
use App\Services\PaperTrading\DecisionOverview;
use App\Services\PaperTrading\ExecutionOverview;
use App\Services\PaperTrading\PaperPolicies;
use App\Services\PaperTrading\RuleEligibilityPolicies;
use App\Services\PaperTrading\SimulationOverview;
use App\Services\PaperTrading\StrategyWatchlists;
use App\Services\Research\ResearchOverview;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

class MonitoringController extends Controller
{
    public const PAGES = ['overview' => 'Overview', 'holdings' => 'Holdings / Theses', 'research' => 'Research / Watchlist',
        'orders' => 'Decisions / Orders', 'performance' => 'Performance', 'control' => 'Control Center', 'health' => 'System Health / Settings'];

    public function show(Request $request, string $page = 'overview')
    {
        $pages = self::PAGES;
        abort_unless(isset($pages[$page]), 404);
        $connection = McpConnection::where('user_id', $request->user()->id)->first();
        $scope = $connection?->bound_account_id ? AccountScope::key($connection) : null;
        $snapshot = $scope ? Snapshot::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'complete')->latest('publication_version')->first() : null;
        $runs = $scope ? Run::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('created_at')->limit(20)->get() : collect();
        $incidents = $scope ? Incident::where('mcp_connection_id', $connection->id)->where('scope', $scope)
            ->orderByRaw('case when resolved_at is null then 0 else 1 end')->latest('last_seen_at')->get() : collect();
        $theses = $scope ? ThesisVersion::where('mcp_connection_id', $connection->id)->where('scope', $scope)->orderBy('id')->get()->groupBy(fn ($t) => $t->payload['thesis_key'])->map->last() : collect();
        $observations = $snapshot?->observations;
        $freshness = [];
        foreach ($observations['freshness'] ?? [] as $name => $state) {
            $freshness[$name] = $state + [];
            $freshness[$name]['status'] = RiskObservations::freshness($state['source_as_of'], $state['retrieved_at']);
        }
        $notificationIds = $incidents->pluck('id')->all();
        $pendingNotifications = Outbox::where('kind', 'notification')->whereNull('dispatched_at')->get()->filter(fn ($e) => in_array($e->payload['incident_id'], $notificationIds, true))->count();
        $research = $scope ? app(ResearchOverview::class)->for($connection, $scope) : null;
        $operations = $page === 'health' ? ['checks' => app(OperationsHealth::class)->evaluate(),
            'deliveries' => NotificationDelivery::whereIn('incident_id', $notificationIds)->orWhereNull('incident_id')->latest('id')->limit(15)->get()] : null;

        $openIncidents = $incidents->where('state', 'open')->count();
        $control = null;
        if ($page === 'control') {
            $watchlist = $scope ? app(StrategyWatchlists::class)->nominations($connection, CarbonImmutable::now()) : null;
            $versions = $scope ? MandateVersion::where('mcp_connection_id', $connection->id)->where('scope', $scope) : null;
            $control = ['latest' => $versions ? (clone $versions)->latest('version')->first() : null,
                'paper' => $scope ? app(PaperPolicies::class)->state($connection) : null,
                'history' => $versions ? (clone $versions)->latest('version')->paginate(10, ['*'], 'versions_page')->withQueryString() : null,
                'runs' => $scope ? ResearchRun::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('created_at')->orderByDesc('id')->paginate(10, ['*'], 'runs_page')->withQueryString() : null,
                'sources' => $scope ? ResearchSource::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('id')->paginate(10, ['*'], 'sources_page')->withQueryString() : null];
            $control['decisions'] = $scope ? app(DecisionOverview::class)->for($connection, $control['paper']) : null;
            $control['simulation'] = $scope ? app(SimulationOverview::class)->for($connection, $control['paper']) : null;
            $control['execution'] = $scope ? app(ExecutionOverview::class)->for($connection, $control['paper']) : null;
            $control['external_orders'] = $scope ? ['pending' => app(ExternalOrders::class)->pending($connection)->latest('id')->paginate(10, ['*'], 'external_orders_page')->withQueryString(),
                'entry_pause_reason' => app(ExternalOrders::class)->entryReason($connection)] : null;
            $control['live'] = $scope ? app(LiveExecutionOverview::class)->for($connection) : null;
            $control['signals'] = $scope ? StrategySignal::where('mcp_connection_id', $connection->id)
                ->where('paper_portfolio_id', $control['paper']['portfolio']->id ?? 0)->latest('id')->paginate(10, ['*'], 'signals_page')->withQueryString() : null;
            $control['eligibility'] = $scope ? ['policy' => RuleEligibilityPolicies::selected($connection),
                'assessments' => EligibilityAssessment::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('id')->paginate(10, ['*'], 'eligibility_page')->withQueryString()] : null;
        }

        return response()->view('mcp.dashboard', compact('pages', 'page', 'connection', 'snapshot', 'runs', 'incidents', 'observations', 'freshness', 'theses', 'pendingNotifications', 'research', 'operations', 'openIncidents', 'control') + ['watchlist' => $watchlist ?? null]);
    }

    public function sync(Request $request, Monitor $monitor)
    {
        $data = $request->validate(['run_id' => ['required', 'uuid']]);
        $connection = McpConnection::where('user_id', $request->user()->id)->firstOrFail();
        try {
            $run = $monitor->sync($connection->id, 'manual:'.$data['run_id'], 'manual', $data['run_id']);

            return redirect()->route('mcp.dashboard')->with('monitor_status', 'Observation '.$run->status.($run->error_code ? ': '.$run->error_code : ''));
        } catch (Throwable) {
            return redirect()->route('mcp.dashboard')->with('monitor_status', 'Observation unavailable; check connection and account binding.');
        }
    }

    public function acknowledge(Request $request, int $incident)
    {
        $connection = McpConnection::where('user_id', $request->user()->id)->firstOrFail();
        $record = Incident::whereKey($incident)->where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->firstOrFail();
        $record->update(['state' => 'acknowledged', 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()]);

        return back();
    }
}
