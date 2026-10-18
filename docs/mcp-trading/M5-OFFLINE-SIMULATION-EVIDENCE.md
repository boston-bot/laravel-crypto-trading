# M5 offline paper orders, fills and accounting

October 18, 2026. Source implementation with synthetic inputs and isolated database tests. **M5 remains partial.** No model, market-data or brokerage request, runtime migration, deployment, service activation, credential change or Git commit was performed.

Later October 18: [execution/handoff evidence](M5-EXECUTION-HANDOFF-EVIDENCE.md) adds versioned owner execution controls, normalized input provenance and a separately gated proposal-to-synthetic-order path. Earlier statements below about no handoff describe this simulator increment; real-provider execution remains unavailable.

## Delivered behavior

`OfflineSimulator` extends M5's virtual portfolio and cash ledger with separate orders, positions, immutable observations and immutable lifecycle/fill events. It does not use the legacy float-based `PaperExecutionEngine`, broker-confirmed orders or legacy crypto ledgers.

- Explicit synthetic intents carry a stable UUID, symbol, sleeve, BUY/SELL side, quantity, market/limit type, price bound, expiry and original rationale. Accepted intent/actor/policy/authorization/assumptions cannot be edited through the Eloquent model. Duplicate submission returns the same order; changed intent under the same key conflicts. Rejected scenarios retain intent and reason without reservations.
- Buy acceptance reserves the bounded price plus per-share fees. Sell acceptance reserves available shares in the specified sleeve. Connection/portfolio row locks serialize both; ledger versions reject stale concurrent submissions. No borrowing, shorts or invented starting holdings.
- Acceptance and fills check scope/operator authorization, applied policy, cash/shares, pending buys, combined symbol exposure across sleeves, sleeve allocation, cash floor, explicit synthetic session-open loss baseline, marks for every positive holding and verified calendar coverage. Changed policy or authorization cancels an open order before filling and releases the remainder.
- Every scenario explicitly supplies quoted per-share fees/slippage, fractional permissions, quote-age tolerance and synthetic session-open equity/date. The first accepted order fixes that day's synthetic baseline. These are **fixture assumptions**, not verified daily market/account evidence or owner-selected production settings.
- Only explicitly synthetic stock/ETF observations are accepted. Unknown/ineligible or leveraged/inverse classifications cannot authorize fills. Decimal boundaries reject floats/scientific notation. Existing Brick-backed `Decimal` calculates prices with eight-place adverse rounding, plus exact per-share fees. PostgreSQL `numeric(38,18)` is authoritative; SQLite is a secondary stand-in.
- Submission never fills immediately. Source time must be strictly after acceptance and the last processed source watermark; retrieval follows source time and precedes local evaluation. A later unmarketable sample prevents replaying an older favorable observation. Missing/stale/future/crossed quotes or unknown size record unfilled events.
- Buys use ask plus adverse slippage; sells use bid minus adverse slippage. Limits remain unfilled when that price violates the limit. Market scenarios require a price bound too. Regular-session DAY orders only: unknown calendar, holidays, early closes and invalid expiry fail closed. Fractional support is rechecked for orders and partial fills.
- Displayed side size caps fills. Stable source keys and symbol/source-time uniqueness preserve immutable samples per portfolio; renaming/re-polling cannot replenish liquidity. Competing orders share remaining size; duplicate workers cannot fill twice. This simplified synthetic model does not predict exchange queue priority or real execution.
- Partial fills release only their corresponding cash/shares. Fill, position/basis/P&L, cash entry, revision and audit event commit atomically. Full exits remove remaining cost basis exactly; partial exits allocate basis with explicit decimal rounding. Winning and losing scenarios retain original rationale.
- Idempotent cancellation/explicit expiry sweeps release only the unfilled remainder and remain available when the simulator gate is off. Expiry is also checked before observations; no scheduled expiry consumer was added.
- Known unresolved corporate actions persist a simulation block, even if a later quote claims clear. No split/dividend adjustment is invented; an audited adjustment/unblock workflow remains unfinished. Unknown action coverage cannot authorize fills.

Accepted/rejected events capture marks. Fill/unfilled events preserve evaluation marks, target observation, calendar/source, reason and status. Fills additionally record position/cash before and after. Request/assumption/observation/event payloads are encrypted. PostgreSQL constraints protect cash/quantity bounds and uniqueness. Immutability is enforced by application models, not database append-only triggers that could prevent administrative SQL changes.

## Entry points and browser report

`MCP_PAPER_OFFLINE_SIMULATION_ENABLED=false` is an independent default. PHPUnit forces it off; synthetic fixtures opt in inside tests. The service has no AI/HTTP/brokerage dependencies and adds **no write route, console command, scheduled job, model-to-order conversion or activation button**. AI credits, applying a policy or enabling research cannot enable it.

Internal PHP entry points:

```php
submit($connection, $actor, $policyId, $expectedLedgerVersion, $intent, $assumptions, $marks);
observe($connection, $actor, $orderId, $quote, $otherPositionMarks = []);
cancel($connection, $actor, $orderId);
expire($connection, $actor);
```

These support controlled synthetic scenarios. Wiring real provider/model output into them requires the remaining mandate, provenance and risk work. No synthetic portfolio was created in the runtime database.

The existing authenticated, account-scoped Control Center now shows a read-only report: positions/reserved shares, historical basis, realized simulated P&L, fees, paginated order intent/assumptions and paginated lifecycle/fill/observation evidence. Current valuation, unrealized P&L, total return, benchmarks and measured strategy results remain unavailable without suitable marks/comparison series. Historical fill prices are not current marks. Synthetic results are labeled separately from broker-confirmed activity. Existing authorization and no-store responses apply; there is no new mutation or CSRF exemption.

## Migration and verification

`2026_10_18_000013_create_mcp_paper_simulation.php` adds **four tables**: `mcp_paper_sim_orders`, `mcp_paper_sim_positions`, `mcp_paper_sim_observations` and `mcp_paper_sim_events`. No backfill or existing runtime table alteration.

Read-only catalog inspection confirmed database **`mcp_trading_test`**, role/schema owner **`joe`**, and existing schema **`mcp_trading`**. Laravel's guard verifies database identity/search path before migrations; integration assertions verify all four tables in the correct schema with fixture ownership. No runtime migration occurred. Deployment of this and earlier unreleased M5 migrations, runtime grants and restore verification for populated new encrypted records remain separate work.

| Check | Final result |
|---|---|
| `composer validate --no-check-publish` | Passed |
| Full guarded PostgreSQL PHPUnit | 269 tests: **268 passed**, 1 environment skip; **1,947 assertions** |
| Full SQLite PHPUnit | 269 tests: **248 passed**, 21 PostgreSQL-only skips; **1,774 assertions** |
| Targeted Pint | Passed |
| `npm run build` | Passed, locked Vite 7.3.7 |
| `git diff --check` | Passed |
| Synthetic component review | Rendered/inspected at 1440/500 px; existing scrolling tables, escaped evidence and unknown valuation labels |

The PostgreSQL skip is the existing `FpmPostgresStartupTest`: **matching PHP-FPM executable unavailable** for CLI PHP 8.5.10. All three new PostgreSQL integration tests ran: stale concurrent reservations, shared liquidity/duplicate fill workers, and constraints/schema placement. Twenty new feature tests cover accounting/profit/loss, partial buys/sells, release, rollback/restart, float rejection, stale/future/unknown data, chronology, fractional/session limits, policy/auth changes, risk limits, persistent corporate-action blocks and scoped/escaped reports. Stray HTTP requests are rejected; no real external calls were used as tests.

A combined shell invocation initially lacked sandbox access to the PostgreSQL socket; the approved direct rerun passed. The first full SQLite run exposed a pre-existing environment leak: the research dashboard expected unreviewed prices but inherited newly configured private development rates. Both PHPUnit configurations now force input/output rates and fixed costs blank; fixtures set synthetic prices explicitly. Subsequent suites passed without application-budget/private-environment changes.

The visual review is an isolated component render, not deployed interactive acceptance. Temporary artifacts: `/private/tmp/m5-offline-simulation-preview.html`, `/private/tmp/m5-offline-desktop.png`, `/private/tmp/m5-offline-mobile.png`.

## Remaining M5 work

API calls can stay disabled while execution-policy/initial-holdings specifications and offline inputs advance. Still required: actual owner capital/allocation/eligibility/order assumptions; reviewed real instrument/market/news provenance and broad screening; verified daily valuation baselines; corporate-action adjustment evidence; general fee schedules; proposal-to-order sizing/authorization/scheduling; provider request/token quota admission; populated-table deployment/recovery verification; and forward paper/performance/baseline/cadence evaluation. This increment does not add historical replay/walk-forward backtesting or prove investment performance. Real-model generation access remains unverified; no retry occurred here. **M6 remains unstarted; live brokerage mutations remain unauthorized.**
