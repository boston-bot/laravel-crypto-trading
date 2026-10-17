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

## Pre-M4 dependency remediation

Completed October 16, 2026 as a separate change on top of commit `deff98e`; only `composer.lock` and `package-lock.json` changed.
- **Before:** 43 Composer advisories across 12 packages and 12 npm vulnerabilities (1 low, 1 moderate, 8 high, 2 critical), the same as the M0 inventory.
- **Composer:** targeted `composer update` of the affected packages with their dependencies. Laravel moved 12.56.0 → 12.70.0, Guzzle 7.10.0 → 7.15.5, PSR-7 2.9.0 → 2.13.1, CommonMark 2.8.2 → 2.10.3 and Flysystem 3.33.0 → 3.36.0, with Symfony components on 7.4.x patches. Six Symfony components that dependency resolution would have moved to 8.1.x were held at `^7.4` with temporary `--with` constraints, because the advisories are fixed in 7.4. No major-version change; `composer.json` is unchanged.
- **npm:** `npm audit fix` without `--force`, all within existing ranges; `package.json` is unchanged. Vite is now 7.3.7. `@coinbase/cdp-sdk` (1.59.0) is unused by the frontend and is documented as optional legacy Node tooling; removing it is a possible follow-up.
- **After:** `composer audit` and `npm audit` report no vulnerabilities. `composer validate` and `composer check-platform-reqs` pass. SQLite PHPUnit: 152 passed / 843 assertions, 6 PostgreSQL-only skips. PostgreSQL PHPUnit: 152 / 912. `npm run build` passes, and the running local app's `/ready` returned 200.

## M4 — Unattended operations and Release 1

- [x] Configure Nginx/PHP-FPM and supervised workers/scheduler with verified absolute paths. (Six user LaunchAgents installed October 17; restricted runtime database login applied and verified.)
- [x] Add process health, data-age, queue-age, dead-letter, and disk-space checks.
- [ ] Configure one external alert destination and independent heartbeat monitoring; verify delivery. (ntfy phone receipt is owner-confirmed and recurring Healthchecks acknowledgements are verified; external outage alert receipt remains pending.)
- [x] Implement daily digest and incident deduplication/acknowledgement/escalation.
- [x] Automate encrypted backups with retention; perform an isolated restore drill. (Daily backup/monthly drill schedules registered; deployed launchd backup and 54-table restore passed. Remote iCloud upload/recovery remains unverified.)
- [ ] Test logout/restart/sleep/network outage behavior on the actual MacBook. Record limitations and recovery steps.
- [ ] Perform seven consecutive trading sessions of unattended monitoring; record availability, stale periods, incidents, costs, and recovery outcomes.

Acceptance: restarting a worker cannot duplicate a business event; scheduler downtime is visible; external monitoring notices a stopped Mac; no secrets appear in logs; restore produces a usable monitoring instance with execution disabled; no brokerage mutation occurred during the proving period.

Evidence: in progress; see [M4 operations evidence](M4-OPERATIONS-EVIDENCE.md). Resumed the interrupted M4 changes October 16, 2026. The baseline passed; follow-up repairs add uniquely correlated queue probes, serialized/no-overwrite backups, full-schema restore counts and all-credential decryption checks, correct PostgreSQL passfile escaping, and a dirty-checkout deployment guard. Final SQLite: **167 passed / 998 assertions, 8 PostgreSQL-only skips** (175 discovered); PostgreSQL: **175 tests / 1082 assertions passed**. Composer validation, frontend build, changed-file Pint, shell/plist checks and native rendered nginx/PHP-FPM configuration tests passed.

Additive migration `2026_10_16_000009_create_mcp_operations_tables` applied as batch 6 in reverified `postgres` / `mcp_trading`, owner `admin`; no unrelated rows were changed. A private backup key was created once at `~/.config/investing-ops/backup.key`. A real encrypted local backup and isolated restore drill verified all **54 tables**, encrypted OAuth readability and disabled restored policies; the scratch database was dropped. Preserve the backup key and `APP_KEY` separately from the encrypted backups.

The separate runtime checkout/LaunchAgents are **not installed**. Operations, monitoring, research, legacy schedules, legacy trading and brokerage writes remain off; no scheduler tasks are registered. No external messages, broker calls, AI requests or brokerage mutations occurred. Owner alert/heartbeat configuration, an off-Mac backup destination and upload verification, runtime installation, Mac restart/sleep/outage checks and the seven-session proving period remain open. M4 remains the active milestone; M5 is not started. Release 1 date: unset.

Service setup continuation: added and tested an authenticated, CSRF-protected phone-subscription reveal screen; topic/token stay off the normal health page. Final PostgreSQL **177 / 1098 assertions**; SQLite **169 passed / 1014 assertions, 8 PostgreSQL-only skips**. Build/Composer/format/diff checks passed. Healthchecks sign-in is open for owner completion. A proposed private ntfy topic and encrypted iCloud backup copy were blocked before execution by automatic approval review because those specific choices were not authorized; explicit destination/configuration approval is pending. No ntfy configuration or iCloud upload was performed. See the M4 evidence for the exact proposal and review reason.


Heartbeat setup, October 17, 2026: the operator supplied a Healthchecks ping URL. It was saved only in private `.env` as `MCP_HEALTHCHECKS_PING_URL`; an application-owned manual connectivity ping returned **acknowledged**, recorded as `heartbeat` component status with `manual_connectivity_test` provenance. The URL is omitted from tracked evidence/output. Composer validation and targeted Operations/PhoneSetup tests passed (**13 tests / 111 assertions**). No migration, background-service startup, account creation, broker call or brokerage mutation occurred. Operations/monitoring/write flags remain false with zero scheduled tasks: this verifies endpoint acceptance only, not recurring heartbeat or alert delivery. A Down indication can occur once this one-time ping ages out. Prior specific ntfy/iCloud choices remain awaiting approval; this update did not configure or transmit to either.


Approved service choices, October 17, 2026: the owner explicitly approved initializing an ntfy topic without messages and copying encrypted backups into iCloud Drive. A random topic was saved in private `.env`; existing topic values would be preserved. `MCP_BACKUP_OFFSITE_DIR` now points to `~/Library/Mobile Documents/com~apple~CloudDocs/Investing Ops Backups`. A fresh encrypted database backup plus manifest was copied there; SHA-256 and manifest hashes match the local files. The folder contains only encrypted backups/manifests; the backup key and `.env` containing APP_KEY remain outside it. macOS reports both files as iCloud items with no upload error, but does not expose a completed-upload flag: remote durability remains unverified. No ntfy message, scheduler or brokerage mutation occurred; operations/monitoring/writes remain disabled. Composer validation and targeted backup/phone/credential tests passed (**7 tests / 44 assertions**); no migration or frontend change was needed. Next operator step: open System Health, select “Show phone subscription setup,” and subscribe in the ntfy phone app. Delivery testing remains a separate action.


Phone delivery check, October 17: owner reported subscription complete and explicitly approved one test. Exactly one test was sent with the existing ntfy client; the provider acknowledged it and its delivery record was saved. Pending alerts were not dispatched. The owner confirmed the test appeared on their phone. ntfy delivery is verified end to end; schedules and brokerage writes remain disabled.


Background-service activation, October 17, 2026: the operator authorized installation and recurring read-only monitoring/operations. A frozen release `d92f0d6a102e06e06016e58729b2a5aacba9e04e` runs at `~/Services/investing-ops/app`; its private local deployment commit did not change the development branch/index (`deff98e`). Homebrew PHP 8.5.11 and Nginx 1.31.7 serve loopback port 8080. Six `local.investing-ops.*` user LaunchAgents supervise PHP-FPM, Nginx, scheduler and monitoring/reconciliation/research-probe workers. Research execution is disabled; that worker handles liveness probes. Production uses `postgres` / `mcp_trading`, owner/login `admin`, with existing data/key/account binding preserved. Development now uses the new empty `mcp_trading_development` / `mcp_trading` database, its own APP_KEY, disabled external integrations and port 8081; no production user/broker credentials were copied into its database.

Eight expected Laravel tasks are registered. A fresh manual read and a scheduled monitoring run completed. Scheduler ticks, all queue probes, recurring acknowledged heartbeats, `/ready` and all current operational health checks passed. Force-restarting the monitoring worker and replaying a completed key left exactly one run/snapshot. A launchd backup copied encrypted files to the approved iCloud folder; a non-repeating restore agent verified 54 tables, readable encrypted credentials, disabled policy and scratch-database removal. Temporary smoke-test jobs were removed. Current AC power already reports sleep=0; no power settings were changed. Research, legacy schedules, legacy trading and brokerage writes remain off.

Tests after isolation: PostgreSQL **178 tests / 1110 assertions**; SQLite **170 passed / 1026 assertions, 8 PostgreSQL-only skips**. Build, Composer validation, shell/format/diff checks passed. Snapshot regression proves developer changes/index and private environment separation. No runtime migration or strategy/policy activation occurred; only the new development database was initialized.

Database-login hardening, October 17: after automatic approval review initially rejected the unapproved change, the operator explicitly approved [the exact role/grant plan](../../deploy/macos/runtime-role-recovery.sql). It is now applied. Production connects as `mcp_trading_runtime`; schema and all 54 tables remain owned by `admin`, and the development login is unchanged. The runtime has data permissions on all 54 tables and 46 sequences, matching default grants for future objects created by `admin`, no administrative privileges or memberships, and no schema-create permission. Transactional CRUD passed and was rolled back; attempted schema DDL was denied (`42501`). Private runtime credentials/config were updated, workers/FPM reloaded, and `/ready`, all health checks, scheduler ticks, queue probes and recurring heartbeat acknowledgements passed. A new encrypted backup under this login was copied to the approved iCloud folder; isolated restore verified **54 tables / 94 rows**, readable encrypted credentials, disabled policy and scratch-database removal. All ten migrations remain applied; no application migration/backfill ran. Composer validation and targeted PostgreSQL operations/backup tests passed (**26 tests / 196 assertions**). Runtime source remains clean; unrelated development changes are preserved.

Remaining M4 work: external heartbeat outage receipt, user-session/logout/reboot/network recovery, remote iCloud durability and seven trading sessions. The deployed snapshot cannot perform DDL; the development checkout now provides the separate owner migration workflow described below. Calendar coverage remains unknown, so monitoring conservatively uses hourly observations rather than claiming verified session cadence. Research, legacy schedules, legacy trading and brokerage writes remain disabled. M5 remains unstarted.

Separate migration connection follow-up, October 17: added a one-off deployment helper using an operator-owned `0600` credentials file outside the checkout. It pins host/database/schema to verified runtime configuration, requires the actual schema owner distinct from runtime, serializes schema deployments with a PostgreSQL advisory lock and purges owner configuration afterward. `deploy.sh --migrate` now uses that helper and stops on status/migration errors before service reload. Owner credentials never enter runtime `.env` or cached config. See [operator setup](MAC-SETUP-AND-OPERATIONS.md#separate-owner-connection-for-reviewed-migrations) and [test evidence](M4-OPERATIONS-EVIDENCE.md#separate-owner-migration-workflow--october-17-2026). This is prepared source work, not a new runtime release; the exact remaining setup is the private file with the existing `admin` database password, then inclusion in a reviewed deployment. No production schema/role/credential change was performed.

Verification for the migration follow-up: full PostgreSQL **185 tests / 1151 assertions**; targeted PostgreSQL operations/deployment/backup **33 / 237**; SQLite credential/deployment guards **4 / 23**. Composer validation, PHP/shell syntax, targeted formatting and diff checks passed. Tests include DDL failure rollback, owner/runtime separation and configuration cleanup, lock contention/recovery, unsafe credential-file rejection, secret-output suppression and fail-closed deployment. No frontend or application migration was added.

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
| `php artisan mcp:health [--no-ping]` | M4 available | Operational checks; opens and resolves health incidents; pings the heartbeat (`--scheduled` is for the scheduler only) |
| `php artisan mcp:notify --once\|--test\|--digest` | M4 available | Escalate and dispatch alert intents to ntfy; delivered only with provider acknowledgement |
| `php artisan mcp:backup [--init-key]` | M4 available | Encrypted, verified schema backup with retention and an off-Mac copy; `--init-key` creates the key once |
| `php artisan mcp:restore-drill [--file=<path>]` | M4 available | Restore into a scratch database, verify counts, decryption and disabled policy, then drop it |
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
