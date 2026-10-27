<?php

namespace App\Http\Controllers;

use App\Services\Crypto\CryptoPaperDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CryptoPaperDashboardController extends Controller
{
    public function __invoke(Request $request, CryptoPaperDashboard $dashboard)
    {
        return DB::transaction(function () use ($request, $dashboard) {
            // One coherent ledger snapshot, without locking or blocking the 24/7 paper cycle.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $asOf = CarbonImmutable::now();
            $data = $dashboard->read($request->user()->id, $asOf);

            return response()->view('mcp.paper.crypto', compact('asOf', 'data'));
        });
    }
}
