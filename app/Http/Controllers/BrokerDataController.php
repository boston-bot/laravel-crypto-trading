<?php

namespace App\Http\Controllers;

use App\Enums\BrokerType;
use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerOrder;
use App\Models\DailyPortfolioSnapshot;
use App\Models\MarketCandle;
use App\Models\MarketQuote;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperPosition;
use App\Models\Position;
use App\Models\RiskEvent;
use App\Models\TradeAttribution;
use App\Models\TradeDecision;
use App\Services\Broker\BrokerSyncJobFactory;
use Illuminate\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrokerDataController extends Controller
{
    public function marketQuotes(Request $request): JsonResponse
    {
        $query = MarketQuote::query()
            ->with('asset')
            ->latest('snapshot_time');

        $this->applyMarketDataAssetFilters($query, $request);

        if ($request->filled('from')) {
            $query->where('snapshot_time', '>=', (string) $request->string('from'));
        }

        if ($request->filled('to')) {
            $query->where('snapshot_time', '<=', (string) $request->string('to'));
        }

        if ($request->filled('source')) {
            $query->where('source', (string) $request->string('source'));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function marketCandles(Request $request): JsonResponse
    {
        $query = MarketCandle::query()
            ->with('asset')
            ->latest('candle_open_time');

        $this->applyMarketDataAssetFilters($query, $request);

        if ($request->filled('timeframe')) {
            $query->where('timeframe', (string) $request->string('timeframe'));
        }

        if ($request->filled('from')) {
            $query->where('candle_open_time', '>=', (string) $request->string('from'));
        }

        if ($request->filled('to')) {
            $query->where('candle_open_time', '<=', (string) $request->string('to'));
        }

        if ($request->filled('source')) {
            $query->where('source', (string) $request->string('source'));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function overview(Request $request): JsonResponse
    {
        $broker = $this->resolveBroker($request);
        $account = BrokerAccount::query()
            ->where('broker', $broker)
            ->latest('snapshot_at')
            ->first();

        if ($account === null) {
            return response()->json([
                'message' => 'No broker account snapshots found. Run broker sync first.',
                'data' => null,
            ], 404);
        }

        $recentOrderLimit = max(1, min(100, (int) $request->integer('recent_orders', 10)));
        $recentDecisionLimit = max(1, min(100, (int) $request->integer('recent_decisions', 10)));

        $openPositions = Position::query()
            ->with('asset')
            ->where('broker_account_id', $account->id)
            ->where('quantity', '>', 0)
            ->orderByDesc('market_value')
            ->get();

        $recentOrders = BrokerOrder::query()
            ->with('asset')
            ->where('broker_account_id', $account->id)
            ->latest('submitted_at')
            ->limit($recentOrderLimit)
            ->get();

        $recentDecisions = TradeDecision::query()
            ->with('asset')
            ->where('broker_account_id', $account->id)
            ->latest()
            ->limit($recentDecisionLimit)
            ->get();

        $latestSnapshot = DailyPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->latest('snapshot_date')
            ->first();

        $latestRiskEvents = RiskEvent::query()
            ->where(function ($query) use ($account): void {
                $query->whereHas('tradeDecision', function ($decisionQuery) use ($account): void {
                    $decisionQuery->where('broker_account_id', $account->id);
                })->orWhereHas('brokerOrder', function ($orderQuery) use ($account): void {
                    $orderQuery->where('broker_account_id', $account->id);
                });
            })
            ->latest('triggered_at')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'account' => $account,
                'latest_snapshot' => $latestSnapshot,
                'open_positions' => $openPositions,
                'recent_orders' => $recentOrders,
                'recent_decisions' => $recentDecisions,
                'latest_risk_events' => $latestRiskEvents,
                'metrics' => [
                    'open_positions_count' => $openPositions->count(),
                    'open_positions_market_value' => (float) $openPositions->sum('market_value'),
                    'assets_total' => Asset::query()->where('broker', $broker)->count(),
                    'assets_enabled' => Asset::query()->where('broker', $broker)->where('is_enabled', true)->count(),
                    'open_orders_count' => BrokerOrder::query()
                        ->where('broker_account_id', $account->id)
                        ->whereIn('status', ['submitted', 'partially_filled', 'reconciliation_required'])
                        ->count(),
                ],
            ],
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $broker = $this->resolveBroker($request);
        $accounts = BrokerAccount::query()
            ->where('broker', $broker)
            ->orderByDesc('snapshot_at')
            ->paginate($this->perPage($request));

        return response()->json($accounts);
    }

    public function account(BrokerAccount $brokerAccount): JsonResponse
    {
        return response()->json([
            'data' => $brokerAccount->load('brokerCredential'),
        ]);
    }

    public function accountPositions(Request $request, BrokerAccount $brokerAccount): JsonResponse
    {
        $query = Position::query()
            ->with('asset')
            ->where('broker_account_id', $brokerAccount->id)
            ->orderByDesc('market_value');

        if ($request->has('open_only') && $request->boolean('open_only')) {
            $query->where('quantity', '>', 0);
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function paperPositions(Request $request, BrokerAccount $brokerAccount): JsonResponse
    {
        $query = PaperPosition::query()
            ->with('asset')
            ->where('broker_account_id', $brokerAccount->id)
            ->orderByDesc('market_value');

        if ($request->has('open_only') && $request->boolean('open_only')) {
            $query->where('quantity', '>', 0);
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function paperPerformance(Request $request, BrokerAccount $brokerAccount): JsonResponse
    {
        $snapshotLimit = max(1, min(1000, (int) $request->integer('snapshot_limit', 200)));
        $snapshots = PaperPortfolioSnapshot::query()
            ->where('broker_account_id', $brokerAccount->id)
            ->latest('snapshot_time')
            ->limit($snapshotLimit)
            ->get()
            ->reverse()
            ->values();

        $latestSnapshot = $snapshots->last();
        $attributions = TradeAttribution::query()
            ->whereHas('tradeDecision', function ($query) use ($brokerAccount): void {
                $query->where('broker_account_id', $brokerAccount->id);
            })
            ->latest('attributed_at')
            ->limit(500)
            ->get();

        $realizedTrades = $attributions->filter(fn (TradeAttribution $a): bool => $a->realized_pnl !== null);
        $wins = $realizedTrades->filter(fn (TradeAttribution $a): bool => (float) $a->realized_pnl > 0)->count();
        $losses = $realizedTrades->filter(fn (TradeAttribution $a): bool => (float) $a->realized_pnl < 0)->count();
        $avgPnl = $realizedTrades->count() > 0
            ? (float) ($realizedTrades->sum(fn (TradeAttribution $a): float => (float) $a->realized_pnl) / $realizedTrades->count())
            : 0.0;

        return response()->json([
            'data' => [
                'latest_snapshot' => $latestSnapshot,
                'snapshots' => $snapshots,
                'trade_count' => $realizedTrades->count(),
                'hit_rate' => $realizedTrades->count() > 0 ? round(($wins / $realizedTrades->count()) * 100, 2) : 0.0,
                'wins' => $wins,
                'losses' => $losses,
                'average_realized_pnl' => round($avgPnl, 8),
                'total_realized_pnl' => round((float) $realizedTrades->sum('realized_pnl'), 8),
                'total_unrealized_pnl' => (float) ($latestSnapshot->unrealized_pnl ?? 0.0),
                'current_drawdown_pct' => (float) ($latestSnapshot->drawdown_pct ?? 0.0),
            ],
        ]);
    }

    public function accountOrders(Request $request, BrokerAccount $brokerAccount): JsonResponse
    {
        $query = BrokerOrder::query()
            ->with('asset')
            ->where('broker_account_id', $brokerAccount->id)
            ->latest('submitted_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('side')) {
            $query->where('side', (string) $request->string('side'));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function assets(Request $request): JsonResponse
    {
        $broker = $this->resolveBroker($request);
        $query = Asset::query()
            ->where('broker', $broker)
            ->orderBy('symbol');

        if ($request->has('tradable')) {
            $query->where('is_tradable', $request->boolean('tradable'));
        }

        if ($request->has('enabled')) {
            $query->where('is_enabled', $request->boolean('enabled'));
        }

        if ($request->filled('symbol')) {
            $query->where('symbol', strtoupper((string) $request->string('symbol')));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function tradeDecisions(Request $request): JsonResponse
    {
        $query = TradeDecision::query()
            ->with(['asset', 'strategyRun'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('broker_account_id')) {
            $query->where('broker_account_id', (int) $request->integer('broker_account_id'));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function riskEvents(Request $request): JsonResponse
    {
        $query = RiskEvent::query()
            ->with(['asset', 'brokerOrder', 'tradeDecision'])
            ->latest('triggered_at');

        if ($request->filled('severity')) {
            $query->where('severity', (string) $request->string('severity'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', (string) $request->string('event_type'));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function sync(Request $request, BrokerSyncJobFactory $jobFactory, Dispatcher $dispatcher): JsonResponse
    {
        $broker = BrokerType::tryFrom((string) $request->string('broker'))
            ?? BrokerType::default();
        $credentialId = $request->filled('credential_id')
            ? (int) $request->integer('credential_id')
            : null;
        $timeframe = (string) $request->string('timeframe', '1d');
        $sync = $request->boolean('sync', false);

        foreach ($jobFactory->make($broker, $credentialId, $timeframe) as $job) {
            if ($sync) {
                $dispatcher->dispatchSync($job);
            } else {
                dispatch($job);
            }
        }

        return response()->json([
            'message' => ucfirst($broker->value).($sync ? ' sync jobs executed synchronously.' : ' sync jobs queued.'),
        ], $sync ? 200 : 202);
    }

    private function resolveBroker(Request $request): string
    {
        $candidate = (string) $request->string('broker', BrokerType::default()->value);

        return BrokerType::tryFrom($candidate)?->value ?? BrokerType::default()->value;
    }

    private function perPage(Request $request): int
    {
        return max(1, min(200, (int) $request->integer('per_page', 50)));
    }

    private function applyMarketDataAssetFilters($query, Request $request): void
    {
        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->integer('asset_id'));
        }

        if ($request->filled('symbol')) {
            $symbol = strtoupper((string) $request->string('symbol'));
            $broker = BrokerType::tryFrom((string) $request->string('broker'))
                ?? BrokerType::default();
            $assetId = Asset::query()
                ->where('broker', $broker->value)
                ->where('symbol', $symbol)
                ->value('id');

            if ($assetId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('asset_id', (int) $assetId);
        }
    }
}
