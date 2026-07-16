# Trading Operations Console Design

Date: 2026-07-16
Status: Approved for implementation planning
Scope: Local-first research, paper-trading, and runtime operations UI

## 1. Problem

The current `/dashboard` exposes many portfolio, backtest, risk, research, and infrastructure metrics on one long page. It does not quickly answer the operator's primary question: **what happened, why did it happen, and what was the result?** Empty paper metrics also look like an inactive strategy even when synchronization or evaluation work occurred.

The domain representation contributes to the ambiguity. A `HOLD` evaluation can appear as `blocked_by_policy`, paper cash is inherited from the connected Coinbase account, and there is no durable minute-cycle record linking data refresh, eligibility, evaluation, proposals, execution, and reconciliation.

The application also lacks one local control surface for starting, observing, pausing, restarting, and safely triggering the scheduler, Laravel queue worker, Python strategy engine, and public feed collector.

## 2. Goals

1. Make the first screen explain the latest completed or active pipeline cycle.
2. Separate strategy, asset, activity, paper, research, and operational detail into focused pages.
3. Show each asset's strategy performance beside buy-and-hold performance for the same period.
4. Distinguish evaluation, actionable proposal, and execution outcome.
5. Support virtual-capital and Coinbase-equity-mirrored paper sessions.
6. Run a visible minute heartbeat while preserving once-per-newly-closed-4-hour-bar evaluation.
7. Provide safe UI controls for synchronization, evaluation, paper cycles, worker lifecycle requests, retries, maintenance, and local paper approvals/rejections.
8. Refresh visible status automatically without full-page reloads.
9. Preserve Coinbase-only, human-approved live execution and all existing risk controls.

## 3. Non-goals

1. Public or multi-user access in this release.
2. Enabling live trading from the dashboard.
3. Arbitrary command execution from an HTTP request.
4. Kraken credentials, Kraken execution, spread execution, DEX, derivatives, leverage, shorts, or autonomous live orders.
5. Replacing PostgreSQL or the canonical Python research engine.
6. Changing the initial strategy's closed-4-hour-bar behavior.

## 4. Product Decisions

1. The initial deployment is local-only and single-user.
2. The primary overview question is “what happened and why?”
3. Strategy performance and runtime health remain prominent summaries, not primary content.
4. The UI uses focused routes rather than one collapsible dashboard.
5. Paper funding supports virtual capital and mirrored Coinbase equity when a session is created.
6. A minute heartbeat performs operational work; strategy evaluation occurs only on a newly final 4-hour bar unless the operator explicitly requests a diagnostic evaluation.
7. Operator actions are audited even before authentication is introduced.
8. Authentication and operator roles are mandatory before AWS or live-capital deployment.

## 5. Information Architecture

The shared application shell has no more than seven primary destinations:

1. `/dashboard` — latest cycle explanation, strategy summary, system summary, recent activity, asset contribution, and safe quick actions.
2. `/strategies` — immutable strategy versions, active version, paper/backtest results, fold/calibration summaries, cost drag, and promotion gates.
3. `/assets` and `/assets/{symbol}` — BTC, ETH, SOL, LINK, and LTC scorecards with strategy-versus-benchmark performance and decision factors.
4. `/activity` and `/activity/{cycle}` — pipeline cycles, asset evaluations, proposals, approvals, orders, fills, skips, failures, and audit detail.
5. `/paper` — active and historical paper sessions, funding, ledger, cash, positions, trades, proposals, and session controls.
6. `/operations` — processes, schedules, queues, engine jobs, collectors, ingestion freshness, failures, maintenance, and lifecycle controls.
7. `/research` — backtests, manifests, calibration, data health, and Coinbase/Kraken shadow-spread aggregates.

Desktop uses a persistent side navigation. Mobile collapses to a compact top navigation. The current broker/account/window controls move into relevant page headers rather than consuming the top of every screen.

## 6. Overview Hierarchy

The `/dashboard` scan order is:

1. Global paper/live safety state, last successful refresh, and next heartbeat.
2. A narrative latest-cycle card:
   - what the system evaluated;
   - whether an order was placed;
   - the primary reason;
   - a link to complete evidence.
3. Compact strategy and system-health summaries.
4. A chronological “What happened” timeline.
5. Asset contribution against buy-and-hold.
6. Safe actions: `Run paper cycle`, `Sync data`, and `Evaluate now`.

When there is no activity, the page explains the prerequisite that is missing—paper session, fresh data, active strategy, eligible closed bar, or online worker—and provides the appropriate recovery action.

## 7. Explainable Domain Model

### 7.1 Pipeline cycle

A `PipelineCycle` represents one automatic minute heartbeat or one manual cycle. It has an idempotency key, trigger type, mode, state, start/end timestamps, latest error, and links to ordered `PipelineCycleStep` records.

Steps use `queued`, `running`, `completed`, `skipped`, or `failed`. Each stores timings, a plain-language reason, structured context, and a link to any queued job or source record. A cycle never holds a database transaction while doing network or engine work.

Only one full cycle—automatic or manually requested—may be active for a broker account and mode. An overlapping trigger coalesces into the active cycle and records a `coalesced` activity event rather than starting duplicate work. A cycle step uses a lease, heartbeat, bounded attempts, and an expiry. An expired lease is recoverable only from that step's idempotent boundary; terminal side effects are checked before retry.

### 7.2 Asset evaluation: what was possible

Every considered asset receives an append-only `AssetEvaluation`, including:

- strategy/universe/manifest identity and `as_of`;
- eligibility and data freshness;
- recommended `HOLD|ENTER|EXIT` action;
- score, calibrated probability, and expected value;
- required thresholds and observed values;
- factor attribution, warnings, and ordered reason codes;
- a human-readable primary explanation.

`HOLD` is a valid evaluation result. It is not represented as a policy failure.

Evaluation finality is point-in-time: a 4-hour bar is eligible only when its canonical component candles have `is_final = true`, acceptable quality state, and `available_at <= cycle.as_of`. The trading-evaluation idempotency key covers strategy version, universe version, asset, logical UTC 4-hour bar close, and mode; the source engine result is additionally unique per asset. Component revision/content hashes are preserved as evidence but are not part of the live/paper trading key. A corrected revision of the same logical bar may produce a new research or diagnostic evaluation, but never a second trade-eligible evaluation, proposal, or order.

Manual diagnostics use `evaluation_kind = diagnostic`. They may compute and display factors, but can never create a proposal, order, fill, promotion-gate credit, paper performance, or live performance attribution.

### 7.3 Trade proposal: what was attempted

Only actionable `ENTER` or `EXIT` evaluations can create a `TradeDecision`. The decision retains its relationship to the evaluation and records sizing, risk, policy, approval, expiry, and fresh-price revalidation.

### 7.4 Execution outcome: what happened

A proposal may produce an order, fill, partial fill, rejection, expiry, or cancellation. Existing order, paper-event, position, attribution, and reconciliation records remain the source of truth, with explicit links back to the evaluation and pipeline cycle.

### 7.5 Activity projection

An append-only `activity_events` projection provides the chronological feed. It is unique by `source_type`, `source_id`, and `event_type`, and stores category, severity, title, explanation, event timestamp, and structured detail. It does not replace canonical records. Canonical owners are:

- cycle and step state: `pipeline_cycles` and `pipeline_cycle_steps`;
- signal evidence: `asset_evaluations` and immutable engine results;
- proposal/risk/approval: `trade_decisions` and policy/risk records;
- execution: broker orders, paper order events, fills, positions, and attribution;
- UI/process mutations: `operator_actions` and runtime control requests.

## 8. Paper Sessions and Accounting

1. A `PaperSession` belongs to a broker account and pins its immutable strategy version, universe version, fee scenario, and slippage scenario.
2. Funding mode is `virtual` or `mirror`.
3. Virtual mode uses an operator-entered starting balance; mirror mode snapshots current Coinbase equity when the session starts.
4. One session is active per broker account at a time.
5. Ending or resetting a session closes it and creates a new session; history is never deleted or rewritten.
6. An append-only cash ledger records opening capital, buys, sells, fees, realized P&L, and adjustments.
7. Paper positions, orders, fills, attributions, and snapshots are session-scoped.
8. Paper buys enforce available cash; sells enforce available quantity.
9. Fees, slippage, exposure, position-count, loss brakes, and drawdown controls remain enforced.
10. Session controls require an explicit confirmation that names the session and consequence.

Mirror mode converts a fresh Coinbase total-equity snapshot into opening USD paper cash; it does not copy Coinbase positions. The opening entry records the source account snapshot ID, valuation time, and currency. If equity is non-positive, older than the configured account-freshness limit, or cannot be valued in USD from observed prices, session creation fails closed and explains the missing input.

The ledger posts opening cash, buy principal, buy fee, sell proceeds, sell fee, reservations, releases, and reversal adjustments. Realized P&L is derived from proceeds minus relieved cost basis and fees; it is never posted again as a cash credit. Cost basis uses weighted-average cost including buy fees. Coinbase fee snapshots or the immutable backtest/paper fee scenario supply fees; a missing required fee snapshot fails the order closed.

USD values use PostgreSQL `numeric(20,8)`, asset quantity uses `numeric(24,12)`, prices use `numeric(20,8)`, and calculations retain database precision until display rounding. Accepting a paper order reserves cash or asset quantity. Terminal completion releases unused reservation. Fill posting, ledger entries, order event, position update, and attribution occur in one database transaction and are exactly once by fill ID. Adjustments are reversal entries linked to the original entry; posted ledger rows are never updated or deleted.

PostgreSQL enforces one active session per broker account with a partial unique index. A session cannot end while a cycle or order is non-terminal. It also cannot end with an open position; the UI offers `Liquidate and end`, which uses normal costed paper fills and ends only after reconciliation. A reset is an end followed by a new session and never crosses pending work between sessions.

## 9. Runtime and Automation

The local runtime contains four supervised workloads:

1. Laravel scheduler.
2. Laravel queue worker.
3. Python strategy worker.
4. Public Coinbase/Kraken feed collector.

The local deployment exposes one documented bootstrap workflow. An out-of-band supervisor remains alive independently of every workload it controls. A typed `RuntimeControl` interface provides status and `start|pause|resume|restart` requests for a fixed process allowlist. Workload-to-command mappings are static server-side configuration; HTTP requests cannot supply an executable, shell fragment, argument, working directory, or environment value. The local adapter delegates to the supervisor; an AWS adapter can later map the same interface to ECS services.

Pause first stops new claims and drains the active unit of work up to a configured timeout. Restart is drain, terminate, and supervisor restart. Conflicting requests are serialized per workload and duplicates return the existing request. A timeout marks the request failed, leaves the supervisor responsible for observed process state, and never escalates to an arbitrary kill command from the web process.

Each process writes a heartbeat containing identity, desired/observed state, current task, version, PID or task identity where applicable, last success, last error, and metadata. A restart request is durable and auditable. If the control agent/process manager itself is offline, the UI explains that it cannot fulfill controls and shows the bootstrap command.

Laravel owns orchestration, credentialed refreshes, cycle/evaluation requests, risk, approvals, orders, audit, paper accounting, and the UI. Python receives evaluation and backtest work only through the existing PostgreSQL `engine_jobs` lease/heartbeat/idempotent-result contract. Python workers and public collectors receive no Coinbase credentials, private keys, broker-order interface, paper-ledger write authority, or live-control capability.

### 9.1 Minute heartbeat

The minute cycle performs, in order:

1. Refresh due account, fee, quote, candle, sentiment, and position data according to source-specific cadences.
2. Validate data freshness and finality.
3. Detect a newly closed 4-hour bar for the active strategy.
4. Submit an idempotent evaluation only when eligible.
5. Consume engine results and create evaluations/proposals.
6. Apply risk, policy, and paper execution or human-approval behavior.
7. Reconcile orders and paper fills.
8. Snapshot the active paper session.
9. Record cycle status and the next expected action.

Manual “Evaluate now” can generate a diagnostic evaluation using the latest eligible final bar, but cannot create a duplicate proposal for an already evaluated strategy/bar. “Run paper cycle” runs the full sequence in paper mode only.

## 10. UI Controls

### 10.1 Paper

- Start a virtual or mirror-funded session.
- End an active session and start a replacement.
- Run a paper cycle.
- Synchronize then evaluate.
- Review positions, available cash, ledger, proposals, orders, and results.

### 10.2 Operations

- View process heartbeat, queue depth, current task, version, and last error.
- Request start, pause, resume, or restart for an allowlisted workload.
- Run a complete paper cycle, sync data, evaluate, retry failures, or run retention/maintenance.
- Inspect the latest cycle step-by-step and link to failures.

### 10.3 Approvals

- Display pending, expired, rejected, and filled proposals.
- Permit audited local paper approval and rejection. A paper approval can submit only to the paper broker adapter and can never cross into live execution.
- Paper approval still checks current session, cash/quantity reservation, quote freshness, sizing, risk, policy, expiry, and price drift.
- The interface never exposes a “turn on live trading” action.

Live approval and live execution actions are disabled until authentication and an operator/approver role model exist. Once that separate prerequisite is implemented, live submission additionally requires both live environment switches, an immutable strategy version that passed the promotion gates, an unexpired human approval, fresh account/fee/quote/risk/policy revalidation, and Coinbase market IOC execution. No UI route may change live environment switches or promote a strategy automatically.

## 11. HTTP and Service Boundaries

Read endpoints return focused versioned projections under `/api/ops/v1` for overview, strategies, assets, activity, paper, operations, and research. The existing schemas and semantics for these legacy endpoints remain unchanged during rollout:

- `GET /api/broker/performance-dashboard`
- `GET /api/broker/overview`
- `GET /api/broker/accounts`
- `GET /api/broker/accounts/{brokerAccount}/paper-performance`
- `GET /api/broker/accounts/{brokerAccount}/paper-positions`
- `GET /api/broker/trade-decisions`
- `GET /api/broker/risk-events`
- `GET /api/research/data-health`
- `GET /api/research/backtests`
- `GET /api/research/calibration`
- `GET /api/research/spreads`

All console pages, all `/api/ops/v1` read and mutation routes, and the existing broker/research endpoints used by the console require loopback binding and loopback-only middleware (`127.0.0.1` or `::1` with untrusted forwarded-host/address headers rejected) while local-only mode is active. Loopback responses preserve the named legacy schemas; the intentional compatibility change is that non-loopback requests receive `403`. CSRF-protected mutations use dedicated request validation, accept an idempotency key, and return `202 Accepted` with an operator-action or pipeline-cycle identifier. The UI polls the identifier until terminal state.

Business logic belongs in services:

- `PipelineCycleService`
- `AssetEvaluationService`
- `PaperSessionService`
- `PaperLedgerService`
- `RuntimeControlService`
- page-specific query/projection services

Controllers validate, authorize the local action policy, invoke a service, and serialize the response. They do not execute arbitrary commands or contain trading logic.

## 12. Refresh and Interaction Behavior

1. Overview, Activity, Paper, and Operations poll lightweight status endpoints every 10 seconds while the document is visible.
2. Polling slows when hidden and returns to immediate refresh when visible.
3. Filter changes abort in-flight requests.
4. Repeated failures use exponential backoff.
5. The last valid state remains visible with an explicit stale timestamp.
6. Changed values update in place without resetting scroll or filters.
7. Every action immediately shows queued/running/success/failure feedback.
8. Destructive session actions require explicit consequence-oriented confirmation.

## 13. Visual and Accessibility Direction

The approved direction is a restrained industrial operations console: graphite/slate foundation, warm amber for operator attention, teal for healthy/completed state, and red only for failures or destructive actions. Typography remains compact and technical without turning every value into a card.

Requirements:

- semantic navigation, headings, tables, buttons, and forms;
- visible labels and focus states;
- 44px minimum touch targets;
- status conveyed by icon/text as well as color;
- WCAG AA contrast;
- keyboard-operable controls and dialogs;
- `aria-live` updates for operation state;
- reduced-motion support;
- usable layouts at 200% zoom and mobile widths.

## 13.1 Asset performance and benchmark definitions

The asset page separates actual portfolio contribution from a normalized research comparison:

1. Actual paper contribution is the asset's **net** realized plus **net** unrealized P&L change for the selected session/window, divided by session opening equity. Buy fees are already in weighted-average cost basis and sell fees are already in net realized proceeds, so fees are not subtracted again. Dollar contribution and percent of total portfolio P&L are also shown.
2. The normalized strategy series starts with `$1.00` cash at the selected window start and replays the immutable strategy's decisions for only that asset using the canonical simulator and selected fee/slippage scenario. Cash earns zero.
3. The normalized buy-and-hold series invests `$1.00`, less the same taker fee scenario, at the next eligible final Coinbase 1-hour close at or after the window start, then liquidates at the final eligible close at or before the window end and pays the exit taker fee.
4. Both normalized series use the same manifest, UTC timestamps, point-in-time availability, start/end dates, and no external cash flow. If either boundary price is unavailable, the comparison is labeled unavailable rather than filled with an invented price.

The UI labels actual contribution, normalized strategy return, buy-and-hold return, and their difference separately; it does not present return on deployed capital as directly comparable to buy-and-hold.

## 14. Failure and Safety Behavior

1. Stale or incomplete data skips evaluation and names the failing source or bar.
2. Worker/process outages show last heartbeat, affected tasks, and the supported recovery action.
3. A failed cycle preserves completed steps and retries only an idempotent safe boundary.
4. Duplicate action submissions return the existing operation.
5. Paper insufficient-cash or insufficient-quantity conditions reject the fill with a visible explanation.
6. Ending a paper session never deletes its records.
7. Runtime commands come from a fixed server-side allowlist.
8. Shadow spread observations cannot create proposals or orders.
9. Live execution remains Coinbase-only, human-approved, capped, and freshly revalidated.
10. Local-only mode is clearly labeled; AWS/live deployment is blocked in documentation until authentication and roles exist.
11. Local mutation and runtime-control routes reject non-loopback requests.

## 15. Testing

### PHP

- cycle idempotency, step state transitions, retry boundaries, and closed-bar evaluation;
- evaluation/proposal/outcome relationships and HOLD semantics;
- paper virtual/mirror funding, ledger math, resets, insufficient cash/quantity, fills, and portfolio snapshots;
- runtime heartbeat, allowlisted control requests, offline-control behavior, and operator audit;
- mutation validation, CSRF behavior, idempotency, API compatibility, and page projections;
- approval expiry and fresh account/fee/quote/risk/policy revalidation.

### Python and PostgreSQL

- existing engine replay and point-in-time suites remain green;
- pipeline and evaluation uniqueness under concurrent workers;
- append-only and session/accounting constraints;
- native PostgreSQL behavior for leases and immutable records.

### Browser and accessibility

- all routes load and active navigation is correct;
- automatic refresh, backoff, visibility behavior, and stale labels;
- empty, loading, success, partial, offline, and failure states;
- responsive layout at desktop/mobile and 200% zoom;
- keyboard navigation, focus visibility, dialogs, and status announcements;
- production Vite build.

## 16. Rollout

1. Add the new records and query services without removing existing dashboard APIs.
2. Implement the minute-cycle and paper-session accounting behind paper-only controls.
3. Add the shared shell and Overview, Activity, Paper, and Operations pages.
4. Add Strategies, Assets, and Research drilldowns.
5. Run shared PHP/Python/PostgreSQL tests and a captured local runtime soak.
6. Preserve the existing `/dashboard` response until the new pages pass acceptance checks.
7. Do not enable live mode or start a paper session automatically.

## 17. Acceptance Criteria

1. `/dashboard` explains the latest cycle and primary no-trade/trade reason in one screen.
2. A user can trace an asset evaluation through proposal and execution outcome.
3. Asset pages compare strategy and buy-and-hold performance for identical windows.
4. A user can create virtual or mirrored paper sessions and cash accounting is correct.
5. A minute heartbeat records visible cycles while strategy evaluation remains closed-4-hour-bar idempotent.
6. The UI safely triggers paper cycles, syncs, evaluations, retries, maintenance, and allowlisted process controls.
7. Offline, stale, skipped, empty, and failed states explain both cause and recovery.
8. Auto-refresh works without full-page reload and preserves last good data on failure.
9. Existing trading safety constraints and API compatibility remain intact.
10. PHP, PostgreSQL, Python, browser, accessibility, and production-build checks pass.

The following executable examples are acceptance fixtures:

1. Two automatic triggers, or one automatic and one manual full-cycle trigger, for the same account/mode while a cycle is active create one cycle; the later trigger produces one `coalesced` activity event.
2. A final 4-hour bar whose last component becomes available at `16:00:03Z` cannot be evaluated at `16:00:02Z` and is evaluated exactly once at or after `16:00:03Z` for each strategy/universe/asset/mode identity.
3. BTC probability `0.491` against entry threshold `0.520` creates one `HOLD` evaluation with the threshold explanation and creates zero trade decisions, orders, fills, or performance attributions.
4. A diagnostic evaluation with an ENTER result creates zero trade decisions, orders, fills, gate credit, or attribution.
5. Mirror funding from a fresh Coinbase equity snapshot of `$128.55` creates `$128.55` opening paper cash and zero copied positions. A stale snapshot creates no session.
6. Starting with `$10,000.00`, buying `$1,000.00` of principal with a `$6.00` fee leaves `$8,994.00` available cash and `$1,006.00` cost basis. Selling all for `$1,100.00` with a `$6.60` fee leaves `$10,087.40` cash and derives `$87.40` realized P&L without a separate P&L cash posting.
7. Posting the same fill ID twice produces one order event, one set of ledger entries, and one position change.
8. Ending a session with a pending order or open position is rejected. `Liquidate and end` uses normal costed fills, reconciles, and only then closes the session.
9. A runtime-control request containing a command, argument, directory, or environment field is rejected. Two restart clicks for the same pending workload request return the same operation ID.
10. With an offline supervisor, process controls are disabled, no command is attempted by the web process, and the response names the configured bootstrap command.
11. A non-loopback request to a console page, `/api/ops/v1`, or a console-used broker/research endpoint receives `403`; loopback legacy responses retain their existing status codes and response fields.
12. The completed round trip in fixture 6 contributes `$87.40 / $10,000.00 = 0.874%` to session return; attributed fees are not subtracted a second time.
13. A corrected revision of an already traded logical 4-hour bar may create a diagnostic/research evaluation but creates zero additional trade decisions, orders, fills, or attribution.
14. A local paper approval creates an audited action and can reach only the paper adapter. The equivalent live approval route remains unavailable until authentication and the approver role exist.
