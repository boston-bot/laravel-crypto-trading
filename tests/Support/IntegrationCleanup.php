<?php

namespace Tests\Support;

use App\Models\Monitoring\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Removes rows a PostgreSQL integration fixture committed through worker processes, which RefreshDatabase cannot
 * roll back. Dedicated test database only; deletes are scoped to one synthetic connection, its portfolios and user.
 */
final class IntegrationCleanup
{
    public static function connection(?int $connectionId): void
    {
        if ($connectionId === null || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $incidentIds = DB::table('mcp_incidents')->where('mcp_connection_id', $connectionId)->pluck('id')->all();
        foreach (Outbox::where('kind', 'notification')->get() as $event) {
            if (in_array($event->payload['incident_id'] ?? null, $incidentIds, true)) {
                $event->delete();
            }
        }
        $userId = DB::table('mcp_connections')->where('id', $connectionId)->value('user_id');
        DB::table('mcp_holdings')->whereIn('snapshot_id', DB::table('mcp_snapshots')->where('mcp_connection_id', $connectionId)->select('id'))->delete();
        $scopes = ['mcp_connection_id' => [$connectionId], 'paper_portfolio_id' => DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $connectionId)->pluck('id')->all()];
        $scopes['setting_id'] = DB::table('mcp_strategy_watchlist_settings')->where('mcp_connection_id', $connectionId)->pluck('id')->all();
        $scopes['sim_order_id'] = DB::table('mcp_paper_sim_orders')->whereIn('paper_portfolio_id', $scopes['paper_portfolio_id'])->pluck('id')->all();
        $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name IN ('mcp_connection_id', 'paper_portfolio_id', 'setting_id', 'sim_order_id')");
        // Child tables reference each other; retry until foreign keys allow every scoped delete.
        for ($pass = 0; $pass < 12; $pass++) {
            $blocked = 0;
            foreach ($columns as $column) {
                if ($scopes[$column->column_name] === []) {
                    continue;
                }
                try {
                    DB::table($column->table_name)->whereIn($column->column_name, $scopes[$column->column_name])->delete();
                } catch (QueryException) {
                    $blocked++;
                }
            }
            if ($blocked === 0) {
                break;
            }
        }
        DB::table('mcp_connections')->where('id', $connectionId)->delete();
        if ($userId !== null) {
            DB::table('users')->where('id', $userId)->delete();
        }
        // Seed fixtures also store an unscoped synthetic session; later tests must not inherit it.
        DB::table('mcp_market_sessions')->where('source', 'synthetic_fixture')->delete();
    }
}
