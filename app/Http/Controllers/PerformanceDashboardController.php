<?php

namespace App\Http\Controllers;

use App\Enums\BrokerType;
use App\Services\Analytics\PerformanceDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerformanceDashboardController extends Controller
{
    public function __invoke(Request $request, PerformanceDashboardService $service): JsonResponse
    {
        $broker = BrokerType::tryFrom((string) $request->string('broker'))
            ?? BrokerType::default();

        $payload = $service->build(
            broker: $broker->value,
            accountId: $request->filled('account_id') ? (int) $request->integer('account_id') : null,
            windowDays: (int) $request->integer('window_days', 30),
            limitTrades: (int) $request->integer('limit_trades', 100),
            limitRiskEvents: (int) $request->integer('limit_risk_events', 50),
        );

        if ($payload === null) {
            return response()->json([
                'message' => 'No broker account snapshots found. Run broker sync first.',
                'data' => null,
            ], 404);
        }

        return response()->json($payload);
    }
}
