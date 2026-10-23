<?php

namespace App\Http\Controllers;

use App\Models\McpConnection;
use App\Models\Monitoring\MarketSession;
use App\Services\PaperTrading\PaperDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PaperDashboardController extends Controller
{
    public function __invoke(Request $request, PaperDashboard $dashboard)
    {
        $request->validate(['date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now('America/New_York')->toDateString()],
            'symbol' => ['nullable', 'string', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'state' => ['nullable', Rule::in(['open', 'partial', 'filled', 'cancelled', 'expired', 'rejected'])],
            'orders_page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'signals_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'readiness_page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return DB::transaction(function () use ($request, $dashboard) {
            // One coherent ledger snapshot, without locking or blocking the trading loop.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $connection = McpConnection::where('user_id', $request->user()->id)->first();
            $date = $request->query('date') ?? MarketSession::where('exchange', 'XNYS')->whereIn('status', ['regular', 'early_close'])
                ->where('opens_at', '<=', now())->orderByDesc('session_date')->value('session_date') ?? now('America/New_York')->toDateString();
            $asOf = CarbonImmutable::now();
            $data = $connection && $connection->bound_account_id && $connection->account_role === 'agentic_monitoring'
                ? $dashboard->read($connection, $date, $asOf, $request->query('symbol'), $request->query('state')) : null;

            return response()->view('mcp.paper.index', compact('connection', 'date', 'asOf', 'data'));
        });
    }
}
