<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\Monitoring\ThesisVersion;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\Monitor;
use App\Services\Monitoring\RiskObservations;
use App\Services\Research\ResearchOverview;
use Illuminate\Http\Request;
use Throwable;

class MonitoringController extends Controller
{
    public function show(Request $request, string $page = 'overview')
    {
        $pages = ['overview' => 'Overview', 'holdings' => 'Holdings / Theses', 'research' => 'Research / Watchlist',
            'orders' => 'Decisions / Orders', 'performance' => 'Performance', 'health' => 'System Health / Settings'];
        abort_unless(isset($pages[$page]), 404);
        $connection = McpConnection::where('user_id', $request->user()->id)->first();
        $scope = $connection?->bound_account_id ? AccountScope::key($connection) : null;
        $snapshot = $scope ? Snapshot::where('mcp_connection_id', $connection->id)->where('scope', $scope)->where('status', 'complete')->latest('publication_version')->first() : null;
        $runs = $scope ? Run::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('created_at')->limit(20)->get() : collect();
        $incidents = $scope ? Incident::where('mcp_connection_id', $connection->id)->where('scope', $scope)->latest('last_seen_at')->get() : collect();
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

        return response()->view('mcp.dashboard', compact('pages', 'page', 'connection', 'snapshot', 'runs', 'incidents', 'observations', 'freshness', 'theses', 'pendingNotifications', 'research'));
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
