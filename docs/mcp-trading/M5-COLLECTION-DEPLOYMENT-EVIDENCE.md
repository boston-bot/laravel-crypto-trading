# M5 market collection deployment

October 18, 2026 owner instruction: deploy the prepared database/source changes and start collecting read-only market inputs before AI credits are restored. This authorizes local deployment and collection, not AI requests, orders or brokerage writes.

## Collector contract

`MCP_PAPER_MARKET_COLLECTION_ENABLED=false` is a new independent default-off switch. Both it and `MCP_PAPER_MARKET_INPUTS_ENABLED` are required. `mcp:paper-collect --once [--sync] [--account=<local-id>] [--limit=1..5]` selects at most five symbols across allowed operators, only from the currently applied execution allowlist. Oldest stored broker observations come first; synthetic fixtures do not suppress collection. Repeating within five minutes does not refresh already observed symbols.

When enabled, the existing Laravel scheduler invokes `mcp:paper-collect --once --scheduled` every five minutes. Scheduled work selects up to five symbols during verified regular sessions and only at the top of each hour otherwise, including unknown calendar coverage. This is a rotating observation baseline, not a 60-second scan of the entire list or a guarantee of execution freshness. A 44-symbol list takes at least nine batches for a full cycle. No new LaunchAgent or worker is required.

Each read uses the existing monitoring queue. Unique symbol jobs, a dispatcher lock, stable scoped slot request IDs, a five-minute queued-work age limit, one attempt, current binding/auth generation and execution-version checks prevent stale replay and limit duplication. Local operator authorization is checked again by the input service. Jobs never call models, handoff, order fills, corporate-action accounting or broker mutations. Provider failures remain incomplete/unknown evidence; no record implies strategy eligibility.

The collector stores Robinhood prices/identity, independently gated tradability/book/reference subsets and optional Alpaca corporate-action query evidence in the existing encrypted `mcp_paper_market_inputs` payload. The separate action-audit migration creates one additive table. Collection itself needs no new table or backfill. Observed corporate-action events are input evidence; action-ledger rows appear only when the separately enabled reconciler runs. Empty order/proposal/action tables are expected while those workflows remain disabled.

## Pre-deployment verification

Runtime identity was confirmed as `postgres` / `mcp_trading`, restricted role `mcp_trading_runtime`: one bound connection, one applied portfolio, zero market inputs and zero paper orders. AI/research, all paper execution, legacy schedules and brokerage writes were disabled. A fresh encrypted pre-deployment backup `mcp-trading-20261019T013006Z.dump.enc` was copied to the configured encrypted offsite destination.

Collector tests verify bounded rotation, replay suppression, encrypted payloads, independent gates, operator scoping/lock, queued authorization/settings/age checks, hourly off-session behavior and CLI bounds. Existing corporate-action and PostgreSQL concurrent-worker tests also pass. Full SQLite: 354 passed / 2,502 assertions, 25 PostgreSQL-only skips. Full PostgreSQL: 378 passed / 2,712 assertions, 1 existing PHP-FPM environment skip (379 total tests in each suite). Deployment observations are recorded below when verified. Composer validation, changed-file Pint, frontend build and diff checks passed. No live mutations are tests.

## Deployment and activation observations

Pending verification. Runtime must retain its existing APP_KEY, database identity, bound account, policy and $500 paper cash. Only app-owned Alpaca credentials and reviewed read-only collection flags may be configured. AI, decisions, handoff, all simulation/fill modes, action accounting, legacy schedules and live writes remain disabled. No provider subscription or external repository push is included.
