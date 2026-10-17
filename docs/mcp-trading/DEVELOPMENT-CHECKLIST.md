# Codex development checklist

All milestones below are initially **not implemented/verified by this documentation task**. Work in order. A green checkbox requires test evidence, not only code presence. Use [the implementation plan](IMPLEMENTATION-PLAN.md) as the specification.

## Session workflow

1. Open the repository root in VS Code. Read `AGENTS.md` and the target milestone.
2. Inspect the working tree and existing implementations. Report conflicts before overwriting anything.
3. Make a small coherent change; update migrations, tests, and docs together.
4. Run targeted tests, then the broader relevant suite once. Use an isolated PostgreSQL test database for database/concurrency behavior.
5. Record the outcome, commands, limitations, and next step under the milestone. Commit only when requested or consistent with the user's established workflow.

Suggested first prompt:

> Read AGENTS.md and docs/mcp-trading/README.md. Implement milestone M0 from DEVELOPMENT-CHECKLIST.md. Inspect the local environment and existing code first. Preserve unrelated changes. Use the existing mcp_trading PostgreSQL schema after verifying its containing database and ownership. Do not enable scheduler jobs or brokerage writes. Complete independent work when a credential is missing, and report the precise remaining setup requirement. Run the applicable tests and update this checklist with evidence.

Suggested continuation prompt:

> Read AGENTS.md and the current milestone evidence in docs/mcp-trading/DEVELOPMENT-CHECKLIST.md. Implement the next unfinished milestone only, following IMPLEMENTATION-PLAN.md. Reuse existing services where their behavior fits, add meaningful failure-path tests, and document what remains unverified. No real brokerage mutations or live-mode activation are authorized by this development request.

## M0 — Safe local foundation

- [x] Inventory PHP/Composer/PostgreSQL/Nginx/PHP-FPM/Node versions and paths; check lockfile compatibility. Keep Python optional.
- [x] Establish existing test baseline without external broker/AI calls. Document failures before changing behavior.
- [x] Add configurable PostgreSQL `DB_SCHEMA`; use it in `search_path`. Verify `current_database()`, `current_schema()`, and actual migration table placement.
- [x] Create an isolated test database configuration. Add a guard that refuses destructive test setup when database matches runtime or lacks the designated test identity.
- [x] Gate existing crypto schedule dispatch independently. Default new integrations and brokerage writes off.
- [x] Secure all existing broker/decision routes, including reads. Add authenticated server-derived approver identity and authorization enforcement. Use local session authentication and CSRF-protected browser mutation routes.
- [x] Introduce decimal value handling for the new financial domain and prevent float coercion at integration boundaries.
- [x] Add structured logging/redaction and a minimal readiness endpoint; do not expose secrets in health checks.

Acceptance: migration runs in `mcp_trading` in a dedicated fixture database; nothing lands in `public`; unauthorized users cannot read financial data or approve decisions; forged `actor` input cannot impersonate an approver; a fresh scheduler setup does not dispatch legacy trading jobs. Existing tests pass or pre-existing failures are precisely documented.

Evidence: implemented and verified October 16, 2026; see [M0 foundation evidence](M0-FOUNDATION-EVIDENCE.md) for environment paths, initial failures, behavior, database identity/ownership, commands, migration impact and limitations. Final PostgreSQL suite: **31 tests / 185 assertions passed**; SQLite suite: **29 passed / 172 assertions, 2 PostgreSQL-only skips**. Composer validation/platform checks and frontend build passed. Runtime `postgres` / `mcp-trading` / owner `admin` was verified read-only through Laravel; migrations ran only in `mcp_trading_test`, with zero tables in `public`. No schedules or brokerage writes were enabled.

Follow-up verification October 16, 2026: private `.env` now exists; all five runtime migrations are applied in `postgres` / `mcp-trading` as schema owner `admin`, and `mcp:doctor` succeeds. No scheduler jobs are registered; legacy schedules, monitoring and brokerage writes remain disabled. Subsequent setup verification confirmed a valid application key (encryption round trip passed) and one provisioned user matching the operator allowlist. M1 local prerequisites are complete; application-owned OAuth and eligible account binding remain M1 acceptance work. No unattended deployment was performed. Existing dependency audits report 43 Composer advisories across 12 packages and 12 npm vulnerabilities; remediation is a separate reviewed change before unattended deployment. Next product milestone: M1.

Schema reference update, October 16, 2026: the current target is `mcp_trading`. Runtime catalog inspection found `postgres` / `mcp_trading`, owned by `admin`, with 34 tables and no `mcp-trading` schema. Configuration (including private `.env`), readiness checks, PostgreSQL placement assertions and setup instructions now use the underscore name. Historical evidence below and in milestone evidence files retains the schema names observed at the time. Verification: `composer validate --no-check-publish` passed; SQLite PHPUnit passed (67 passed, 363 assertions, 3 PostgreSQL-only skips); PostgreSQL PHPUnit passed (70 tests, 387 assertions); targeted Pint and `git diff --check` passed. `php artisan mcp:doctor` confirmed `postgres` / `mcp_trading`, owner `admin`, with migrations present. Only the isolated `mcp_trading_test` fixture schema was renamed from `"mcp-trading"` to `"mcp_trading"` before testing. No runtime DDL, application migration or backfill was needed; runtime already used the underscore schema. No frontend assets changed. Next product milestone remains **M2**.

## M1 — Read-only Robinhood MCP integration

- [x] Implement broker interfaces/DTOs, fake gateway, error taxonomy, and capability manifest.
- [x] Conduct bounded PHP SDK feasibility spike. If unsuitable, document the failure and implement the narrow Python bridge described in the plan; pin dependencies and add contract tests.
- [x] Implement supported OAuth discovery, authorization callback, secure token storage, serialized renewal, reconnect UX, and revoked-token handling.
- [x] Bind the connection to the operator-selected eligible account; preserve separate advisory account roles.
- [x] Implement complete reads for portfolio, positions, orders, instruments, and quotes; paginate until complete with bounded resource limits that report incomplete rather than silently truncate.
- [x] Store sanitized captured response fixtures only with private identifiers removed.
- [x] Add explicit capability handling for cash flows, holidays, approvals, and unsupported fields; no invented endpoint paths.

Acceptance: user completes the real OAuth consent flow; app retrieves the intended account using its own authorization, renews or reconnects as supported, survives a restart, and reports complete results. Automated tests cover partial pagination, 401/403/429, schema changes, timeouts, and account mismatch. No write tool is available to monitoring jobs.

Evidence: **completed October 16, 2026** for the selected existing account and explicit equity read coverage. See [final M1 evidence](M1-INTEGRATION-EVIDENCE.md) and [PHP SDK decision](M1-PHP-SDK-SPIKE.md). App-owned OAuth negotiated MCP 2025-11-25 and discovered 83 tool schemas. After the proposed Electron onboarding failed, the operator explicitly selected the existing active agentic-enabled account; it was revalidated and bound locally for monitoring. No creation retry or silent account substitution occurred.

Six reviewed read-tool mappings are pinned. Fresh-process reads returned complete portfolio (1), equity positions (2), equity orders (3), exact symbol lookup (1), and quotes (1). Source time is preserved when supplied; missing portfolio/position/order source times remain unknown. Equity coverage is labeled; other asset classes and optional flow/calendar capabilities are not falsely claimed. Real OAuth renewal rotated the refresh token, and another process read successfully with the saved credentials/binding. Sanitized real contract fixtures replace private IDs, amounts, symbols and guidance.

Final verification: PostgreSQL **70 tests / 387 assertions passed**; SQLite **67 passed / 363 assertions, 3 PostgreSQL-only skips**; Composer validation/platform checks, frontend build, formatting and diff checks passed. Two PostgreSQL workers refresh once and restart persistence is tested with fakes. The three-table additive M1 migration is applied in runtime `postgres` / `mcp-trading` as batch 2; there were no further migrations/backfills. Manual integration is enabled on `127.0.0.1:8080`; monitoring, legacy schedules, legacy trading and brokerage writes remain **off**, with no scheduler jobs registered. No brokerage mutation occurred. Existing dependency advisories remain a pre-deployment remediation item. Next milestone: **M2**; not started.

## M2 — Durable monitoring and risk observations

- [x] Add run records, staged/complete snapshots, thesis versions, sleeve attribution, policy versions, incidents, and outbox records.
- [x] Implement idempotent snapshot publication and conservative position reconciliation.
- [x] Implement account/sleeve exposure, pending commitments, data freshness, market sessions, typed invalidations, and pause reasons.
- [x] Add precise flow accounting and performance availability flags; do not infer missing historical baselines.
- [x] Add deduplicated schedules and monitoring/reconciliation queue separation.
- [x] Implement the six dashboard views progressively, beginning with Overview/Holdings/System Health.
- [x] Add archive import preview/report, source hashes, duplicate handling, and quarantine for malformed rows. Commit import only through an explicit operator action.

Acceptance: incomplete sync cannot erase holdings; repeated jobs do not duplicate snapshots; `undefined`/empty run IDs are rejected; a completed-close rule cannot fire from an intraday price; deposits are not gains; unknown flows make return-dependent risk gates unavailable; source/retrieval timestamps and stale status appear in the UI.

Evidence: implemented October 16, 2026; see [M2 monitoring evidence](M2-MONITORING-EVIDENCE.md) for behavior, exact commands, failures/limitations and migration impact. PostgreSQL **93 tests / 562 assertions passed**; SQLite **89 passed / 515 assertions, 4 PostgreSQL-only skips**. Composer validation, frontend build, targeted Pint and diff checks passed. Tests prove competing workers/restart idempotency, stale-worker fencing, complete-only holdings publication, valid UUIDs, completed-close separation, deposit-neutral unitization, unknown-flow availability, source/retrieval freshness UI, scoped access, provider cooldown and archive quarantine/deduplication.

The twelve-table additive migration is applied as batch 3 in verified `postgres` / `mcp_trading`, owner `admin`; no existing records were backfilled/deleted. Manual broker sync published 2 holdings and 3 equity orders. Actual missing source times, stale quote coverage, unsupported flow/calendar history and unknown sleeve mappings remain visible unknowns. All six secured dashboard routes exist; Research is explicitly reserved for M3. Archive commits store historical evidence only and require an explicit operator command; no real archive was supplied/imported. No unattended monitoring, scheduler activation, external notification delivery or brokerage mutation occurred. Monitoring, legacy schedules, legacy trading and brokerage writes remain disabled. Next milestone: **M3**.

## M3 — Research and decision support

- [x] Add market/news adapter with documented vendor coverage and timestamp quality. (Broker quotes/exact lookup plus untrusted operator evidence; no news vendor selected or authorized.)
- [x] Implement material-event deduplication, research cache expiry, and per-thesis research serialization.
- [x] Add API-backed research with strict response schema, trusted source-reference mapping, prompt versions, usage and cost records. (Verified with fake HTTP; a live provider call is blocked on the owner's model, credential and reviewed pricing.)
- [x] Add atomic budget reservation/reconciliation; include fixed costs and pending calls.
- [x] Build Research/Watchlist and thesis-review UI; proposals stay non-executable in this milestone.
- [x] Add daily and weekly summaries with clearly labeled benchmark and performance limitations.

Acceptance: unchanged events do not repeatedly call AI; malformed output/refusal produces a visible failure; injected article instructions cannot invoke tools/change policy; budget exhaustion stops optional calls but monitoring continues; research claims link to supplied evidence; old quotes cannot be presented as current.

Evidence: implemented October 16, 2026. See [M3 research evidence](M3-RESEARCH-EVIDENCE.md) for behavior, exact commands, failures and limitations, and migration impact.
- **Tests:** SQLite **152 passed / 843 assertions, 6 PostgreSQL-only skips**; PostgreSQL **152 / 912 assertions passed** (run twice). Composer validation, frontend build, Pint on changed files and diff checks passed.
- **Coverage:** all six acceptance criteria use a fake model and fake HTTP. Also tested: per-subject serialization; crashed-worker sweeping after rebinding; lapsed leases; deferral on a temporary disconnect; correct thesis targeting; stale, superseded and repeat adoption refused; typed provider failures with unbilled settlement; summaries for closed periods only; CSRF, operator gate and cross-account isolation.
- **PostgreSQL multi-process:** one model call per subject, and reservations never exceed the envelope.
- **Independent review:** found no overspend, double release or authorization flaw; its confirmed defects were fixed and regression-tested.
- **Runtime:** `000007` was found already applied as batch 4 in verified `postgres` / `mcp_trading`. It was not applied by this session's commands; the operator should confirm. This session applied additive `000008` as batch 5. Research tables are empty, existing rows are unchanged and no schedules are registered.
- **Not done:** no AI call, vendor subscription, runtime broker read or brokerage mutation occurred. Research, monitoring, legacy schedules and brokerage writes remain disabled. Live model compatibility, a news/market vendor and M4 incident re-notification remain open.

Next milestone: **M4**.

## M4 — Unattended operations and Release 1

- [ ] Configure Nginx/PHP-FPM and supervised workers/scheduler with verified absolute paths.
- [ ] Add process health, data-age, queue-age, dead-letter, and disk-space checks.
- [ ] Configure one external alert destination and independent heartbeat monitoring; verify delivery.
- [ ] Implement daily digest and incident deduplication/acknowledgement/escalation.
- [ ] Automate encrypted backups with retention; perform an isolated restore drill.
- [ ] Test logout/restart/sleep/network outage behavior on the actual MacBook. Record limitations and recovery steps.
- [ ] Perform seven consecutive trading sessions of unattended monitoring; record availability, stale periods, incidents, costs, and recovery outcomes.

Acceptance: restarting a worker cannot duplicate a business event; scheduler downtime is visible; external monitoring notices a stopped Mac; no secrets appear in logs; restore produces a usable monitoring instance with execution disabled; no brokerage mutation occurred during the proving period.

Evidence: pending. Release 1 date: unset.

## M5 — Paper evaluation and strategy specification

- [ ] Have the owner define account/sleeve allocation, allowed universe, horizon, and explicit entry/exit/sizing rules. Record policy versions.
- [ ] Adapt existing paper components to equities and the planned order lifecycle.
- [ ] Simulate orders with bid/ask, spread, session rules, fees, slippage, partial/unfilled behavior, and corporate actions where relevant.
- [ ] Preserve point-in-time inputs and separate paper cash/positions from live holdings.
- [ ] Build replay/walk-forward evaluation only if an active rules-based strategy is selected; do not label metrics recording as backtesting.
- [ ] Produce paper decision and performance reports, including costs, baseline comparisons, and rejected/WAIT decisions.

Acceptance: no look-ahead data; limit orders can remain unfilled; impossible fractional/session combinations are rejected; paper and live ledgers cannot mix; cash/position conservation holds; both losing and winning decisions retain their original thesis evidence. A short profitable sample cannot automatically enable live mode.

Evidence: pending.

## M6 — Controlled execution, separately enabled

- [ ] Implement durable immutable intents and lifecycle transitions with database constraints.
- [ ] Add atomic submission ownership, per-account serialization, persisted ambiguous states, and outbox dispatch.
- [ ] Integrate actual broker preview/approval requirements and bind approvals to payload/version/expiry.
- [ ] Revalidate all live gates at the last execution boundary; support separate buy pause and full emergency disable.
- [ ] Implement broker reconciliation and execution-level deduplication, including approval-generated reference mapping.
- [ ] Require explicit owner activation and concrete policy parameters; verify old automated executors are paused.
- [ ] Conduct only the explicitly authorized small live pilot and record brokerage-confirmed results.

Mandatory fault tests: two workers claim the same intent; crash immediately before/after external request; response lost after acceptance; duplicate delivery; refresh-token race; partial fill then cancellation; policy changed while awaiting approval; expired quote; manual buy consumes funds; missing order-history page; emergency disable during queued work; restored backup contains stale intents. Every ambiguous external outcome blocks new exposure until reconciled.

Evidence: pending. Live authorization: none supplied by this documentation.

## Operator commands: available and planned

Unimplemented rows remain proposed interfaces. `--account` on M2 commands is the local connection ID, not a brokerage account number.

| Command | Status | Behavior |
|---|---|---|
| `php artisan mcp:doctor` | M0 available | Redacted database/schema identity checks; read-only |
| `php artisan mcp:sync --account=<local-id> --key=<stable-key>` | M2 available | Foreground broker reads and complete-only snapshot publication |
| `php artisan mcp:reconcile --account=<local-id>` | Standalone command planned | M2 already has a separate read-only reconciliation queue; no submit/cancel |
| `php artisan mcp:monitor --once` | M2 available | One deduplicated dispatch cycle; respects disabled monitoring flag |
| `php artisan mcp:import --file=<path> --dry-run` | M2 available | Bounded JSON archive preview/quarantine report; explicit `--commit --account=<local-id>` archives historical evidence only |
| `php artisan mcp:research --once [--sync]` | M3 available | Sweep crashed runs, detect events, queue (or foreground-process) research; no-op while research is disabled or unready |
| `php artisan mcp:summary --kind=daily\|weekly [--account=<local-id>] [--date=YYYY-MM-DD]` | M3 available | Deterministic summary of a closed New York day or ISO week; no AI call; open periods refused |
| `php artisan mcp:execution-disable --reason=<text>` | Planned | Persist global mutation disable; no automatic liquidation |
| `php artisan mcp:readiness` | Planned | Release gates, latest evidence, and blockers; `/ready` remains the minimal M0 database check |

Do not add an unattended auto-enable command. Live activation belongs to an authenticated operator flow with explicit policy review.

## Test organization

Keep fast pure-function tests independent of databases. Add a PostgreSQL integration suite for schema placement, transactions, uniqueness, advisory locks and concurrency. Test HTTP/controller authorization and broker/AI DTO contracts with fakes. Use an injectable clock for holidays, early closes, DST, freshness, and approval expiry. Reject unmocked network traffic in automated tests. Frontend checks cover empty, loading, stale, partial, error, and healthy states.

At milestone completion record:

```text
Milestone:
Code/commit reviewed:
Behavior delivered:
Tests and results:
Migration/backfill impact:
External checks actually performed:
Remaining blockers:
Next milestone:
```
