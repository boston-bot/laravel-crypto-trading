# Performance And Risk Dashboard Design (v1)

Date: 2026-04-21  
Status: Approved for implementation planning  
Scope: Paper trading + backtest performance and risk visibility, Coinbase default

## 1. Problem Statement

The current codebase has strong sync, strategy, and paper-trading infrastructure, but no dedicated interface to evaluate strategy quality over time with visual context. Metrics exist in API/database tables, but there is no single operational screen to interpret paper performance, risk behavior, and backtest alignment.

## 2. Goals

1. Provide a single dashboard page for paper + backtest strategy evaluation.
2. Make risk and performance interpretation immediate using charts and KPI summaries.
3. Keep implementation lightweight and aligned with the existing Laravel + Blade + Vite stack.
4. Support Coinbase-first workflows while preserving Robinhood-on-demand filtering.

## 3. Non-Goals (v1)

1. Full portfolio management or trade execution UI.
2. Real-time websocket streaming.
3. Advanced drilldown analytics (factor decomposition, regime attribution pages, optimization UI).
4. Live-capital mode controls.

## 4. Product Decisions (Locked)

1. Primary objective: Strategy performance and risk.
2. Data scope: Paper + backtest.
3. Default broker: Coinbase.
4. UI architecture: Blade + lightweight JavaScript charts.
5. Delivery mode: Text-first design, no browser companion.

## 5. Information Architecture

Route: `/dashboard`

Sections:

1. Top control bar
- Broker selector (`coinbase` default, optional `robinhood`)
- Account selector (latest account default)
- Window selector (`7d`, `30d`, `90d`)
- Auto-refresh selector (`off`, `30s`, `60s`)
- Manual refresh button

2. KPI strip
- Paper equity
- Paper return %
- Paper max drawdown %
- Paper hit rate %
- Paper expectancy
- Paper trade count
- Latest backtest Sharpe / drawdown / win rate / profit factor
- Promotion badge from scorecard payload (if available)

3. Charts
- Paper equity curve (line)
- Paper drawdown curve (area)
- Backtest vs paper comparison (bar chart)

4. Detail tables
- Recent paper trades with realized vs expected attribution fields
- Recent risk events with severity/event type/time
- Latest backtest run metrics + run metadata

## 6. Data Sources

Reuse existing endpoints:

1. `GET /api/broker/accounts?broker=...`
2. `GET /api/broker/overview?broker=...`
3. `GET /api/broker/accounts/{id}/paper-performance?snapshot_limit=...`
4. `GET /api/broker/risk-events?...`

Add a dedicated endpoint:

1. `GET /api/broker/performance-dashboard`

### Query Params

1. `broker=coinbase|robinhood` (default `coinbase`)
2. `account_id` (optional, default latest account for broker)
3. `window_days=7|30|90` (default `30`)
4. `limit_trades` (default `100`, hard cap enforced)
5. `limit_risk_events` (default `50`, hard cap enforced)

### Response Contract

```json
{
  "meta": {
    "broker": "coinbase",
    "account_id": 1,
    "window_days": 30,
    "generated_at": "2026-04-21T12:00:00Z"
  },
  "kpis": {
    "paper_equity": 0,
    "paper_return_pct": 0,
    "paper_max_drawdown_pct": 0,
    "paper_hit_rate_pct": 0,
    "paper_expectancy": 0,
    "paper_trade_count": 0
  },
  "paper_series": {
    "equity": [{ "t": "...", "v": 0 }],
    "drawdown_pct": [{ "t": "...", "v": 0 }]
  },
  "backtest_latest": {
    "run_id": 0,
    "completed_at": "...",
    "metrics": {
      "sharpe": null,
      "max_drawdown_pct": null,
      "win_rate_pct": null,
      "profit_factor": null
    }
  },
  "comparison": {
    "paper": { "hit_rate_pct": 0, "max_drawdown_pct": 0 },
    "backtest": { "win_rate_pct": 0, "max_drawdown_pct": 0 }
  },
  "recent_trades": [],
  "recent_risk_events": []
}
```

Notes:

1. Backtest metric aliases must align with `config/scorecard.php`.
2. Series should be normalized server-side for straightforward chart rendering.
3. Optional endpoint cache of 10-30 seconds by `(broker, account_id, window_days)`.

## 7. Backend Architecture

1. Add controller action in `BrokerDataController` or dedicated performance controller.
2. Add service class for aggregation and metric normalization.
3. Pull from:
- `paper_portfolio_snapshots`
- `trade_attributions`
- `risk_events`
- `backtest_runs`
- `backtest_run_metrics`
4. Provide resilient partial payloads when some datasets are missing.

## 8. Frontend Architecture

1. Blade page: `resources/views/dashboard.blade.php`
2. JS modules:
- controls
- data fetch/polling
- chart rendering
- table rendering
3. Chart library: Chart.js (minimal dependency footprint).
4. State:
- In-memory runtime state for current filters and payload.
- `localStorage` for persisted filter preferences.

## 9. Visual Direction

Quant-focused visual language:

1. Slate/graphite base palette.
2. Warm amber + teal accents for directional data.
3. Strong KPI typography hierarchy.
4. Dense but readable tables.
5. Subtle update animation for changed KPI values.

Explicit constraints:

1. No purple-default design language.
2. No excessive glassmorphism.
3. Desktop and mobile responsive behavior required.

## 10. Reliability Rules

1. Keep last successful payload on fetch failure; show warning state.
2. Show explicit empty states when datasets are absent.
3. Support partial rendering if one payload section fails.
4. Abort in-flight request when filters change.
5. Backoff polling after repeated failures.

## 11. Performance Rules

1. `window_days` restricted to `7|30|90`.
2. Series point cap (example: 2,000), with server downsampling if needed.
3. Hard caps for `limit_trades` and `limit_risk_events`.
4. In-place chart dataset updates to avoid full chart re-init.

## 12. Testing Plan

1. Feature tests for `performance-dashboard` endpoint:
- response schema
- broker/account/window filtering
- backtest metric alias mapping
- empty-state responses
2. UI smoke test:
- `/dashboard` loads
- filters trigger data refresh
- charts/tables hydrate from response
3. Regression checks:
- existing broker API endpoints unchanged

## 13. Rollout Plan

1. Build backend endpoint + service.
2. Build `/dashboard` route + Blade + JS + charts.
3. Add tests and docs.
4. Optionally guard route by environment or auth.
5. Observe in paper mode for 24-48 hours.

## 14. Acceptance Criteria

1. `/dashboard` renders KPIs, charts, and tables for Coinbase by default.
2. User can switch broker/account/window without full-page reload.
3. Auto-refresh updates UI safely with visible last-updated state.
4. Empty and error states are explicit and non-destructive.
5. Endpoint and UI pass defined tests.

