# M6 automatic execution and UI stop controls — October 26, 2026

## Owner scope

The owner explicitly requested automatic trades, enabling live writes and a quick UI off switch. The owner confirmed this app will be the sole executor for the bound Robinhood agentic account. The existing live policy v1 is authoritative: $500 sleeve, $25 planned maximum order, 0.25% planned risk, 10% position cap, two open positions, 2% daily loss pause, two session-order admissions, market/fractional orders, 10 bps slippage, 5 bps limit offset, 20-second quotes and broker approval off. No crypto execution, brokerage approval-setting change, watchlist mutation or other executor activation is authorized by this increment.

The runtime brokerage write flag was enabled as requested, after engaging the existing emergency disable. A private environment backup was preserved; both pre-existing duplicate write-flag entries were set consistently true, with unrelated settings unchanged. Configuration/workers/FPM were refreshed. No order was submitted during development or verification.

## Automatic cycle

`MCP_LIVE_AUTOMATION_ENABLED` defaults false. When deliberately enabled it gives `mcp:live-cycle --once` ownership of shared batch collection, replacing the scheduled paper/standalone collector without starting legacy crypto or AI schedules. It runs in the background every five minutes at minute 1, 6, 11, etc., after the watchlist/monitoring slot, and does not hold up health/heartbeat dispatch. The read gateway reuses its existing scoped MCP session across account reconciliation and collection. The execution gateway retains a separate review/place-only session.

Each active account gets one persisted run per five-minute slot, shared across connections. The run is recorded before any broker read or order. Overlap locks and durable uniqueness prevent duplicate/restarted runs; unfinished runs are never replayed. Processing has a 240-second monotonic budget, checks the current slot before dispatch, and permits at most one submission attempt per account/slot. Current policy session limits remain additional, stricter admission checks.

The cycle first refreshes/reconciles broker holdings, orders, account eligibility and approval mode. Incomplete/coalesced-running reconciliation yields no submissions. It then consumes each stored quote immediately; live-held symbols have collection priority. The shared PHP `StrategySignals` engine now records live entries as well as exits, using actual broker-confirmed app fills, the live policy's quote age, deterministic profiles/rules and fresh nominating-list membership. Data-only lists cannot nominate entries; exits remain independent of membership and buy pauses. Paper positions, open paper orders, cash and sizing do not control live decisions; live evidence omits paper ledger/execution versions. Shared quotes can independently feed the existing paper consumer after the live evaluation.

Every automatic intent pins an immutable activation ID. Stage, claim and post-preview checks require that same current activation, account authorization and policy fingerprint. Signals recorded before a new activation are refused. The existing durable checkpoint, stable idempotency key, broker preview, full last-boundary checks, exact-decimal sizing and unknown-outcome handling remain mandatory. No model chooses, sizes or modifies an order.

## UI and stop semantics

Every bound-account console page shows an Automatic trading panel:

- **Enable automatic trading** records an append-only owner activation for the current account generation and policy. It requires explicit automatic-order and sole-executor acknowledgments, valid configuration, current account evidence, matching broker approval and a valid recovery checkpoint. The HTTP request never submits an order.
- **Pause new buys / Resume new buys** preserves the current full-stop state; exits remain available while automation is enabled. Resuming buys cannot silently release an emergency stop.
- **Stop all submissions** disables automation and engages emergency disable in one transaction. It is authenticated, operator/account scoped, CSRF protected, independent of the start throttle, and remains available after disconnect or checkpoint damage. It blocks both manual and automatic submissions. A stale/replayed ON request cannot release a later stop.

Stop cannot recall a request already in submission or an accepted broker order. Such orders can still fill; cancellation currently requires Robinhood. No cancel/approval-decline adapter is added. Any open/unknown/partial order blocks the next claim, including an exit, until broker reconciliation/owner resolution. This limitation is displayed through cycle status and the existing order journal; it is not represented as automatic cancellation.

Policy/authorization changes block automation until explicitly enabled again. The UI shows actual enabled/blocked/buy-paused status and the latest cycle's time, result, evaluation count and attempt count. Operations health now detects overdue/failed active cycles and unresolved reconciliation, using the existing incident/notification path; actual delivery of a new automatic-trading alert has not been tested against an external service.

## Persistence and recovery

Additive migration `000034` adds `mcp_execution_automation` (immutable owner events, per-account versions and unique requests) and `mcp_execution_cycles` (durable unique account/slot runs and results). It does not create intents, change policy, enable an account or backfill financial state. It is forward-only to retain activation audit. The execution-controls account lock is now acquired before reading the latest control state, including cross-connection updates.

The independent local checkpoint now also pins the latest activation/stop request. It is synced before the corresponding database event commits. An older restored database cannot silently restore an earlier ON after the owner stopped trading. Existing checkpoints without an activation marker remain valid only while no automation event exists. Missing/corrupt storage never prevents recording OFF; it already blocks dispatch. Failed file/database commits conservatively fence further execution. Neither a failed run nor a restored checkpoint is automatically reset. Losing or rolling back both stores still requires owner-reviewed reconstruction with writes disabled.

## Verification

Dependencies remain pinned; Composer validation, scoped Pint, whitespace checks and the frontend build passed. Full suites: SQLite **716 passed / 110 skips, 4,991 assertions**; PostgreSQL **825 passed / one existing environment skip, 5,882 assertions** (826 total each). After moving the schedule one minute behind monitoring and into the background and ensuring an old OFF retry still stops a newer ON, final focused automation/scheduler/restore verification passed **17 tests / 142 assertions**. No unresolved test failures remain.

Tests use synthetic accounts, fake brokerage gateways, guarded `mcp_trading_test` and memoized synthetic inputs through the existing batch collector. They cover automatic BUY, live-fill SELL after buy pause/watchlist expiry, paper/live separation, unarmed/default-off behavior, stale quotes, failed reconciliation, duplicate/restarted slots, ambiguous outcomes without replay, policy changes, activation freshness, stop during preview, stale ON replay, stop after disconnect/checkpoint loss, UI authentication/account scope/CSRF, start-throttle-independent stopping, scheduler ownership/background cadence, overdue-cycle health, and a real encrypted restore from before an owner stop. Existing console form allowlists were deliberately extended for the three reviewed actions. No live broker mutation was used as a test.

## Remaining acceptance

Broker-confirmed live pilot results are not established by fake tests. Track actual scheduled cadence, input freshness, blocked reasons and any genuine broker-confirmed orders under the owner's authorization. Cancellation/approval-decline automation, replacement-machine journal reconstruction and Mac overnight/lid-closed continuity remain open. M6 remains partial; no strategy profitability or automatic loss guarantee is claimed.

## Deployment preparation

Before the additive runtime migration, created encrypted backup `mcp-trading-20261026T155054Z.dump.enc` with manifest hash `1a771cd10e965f80517b99e870ddaa51d699b96868db4ca1c4a0d8a05d567635`, 120,584,587 bytes and offsite copy reported copied (independent remote-device availability was not reverified). The exact newest backup passed a real isolated restore: 102 tables / 31,514 rows, encrypted columns readable, scratch database dropped. An initial drill invocation used a basename where an absolute path is required; the subsequent default-newest invocation succeeded for this exact file. Reviewed migration `000034` in pretend mode through the existing verified runtime/database/schema-owner deployment service and private `0600` credentials, without caching owner credentials. Emergency disable remains engaged throughout preparation.

## Owner-authorized deployment and activation

Committed implementation `01a80f5`. Applied only additive migration `000034` through the verified runtime bootstrap and schema-owner deployment service with that explicitly reviewed real migration path, before installing the code. Existing monitoring/services needed no schema transition pause because this adds independent tables only. The normal `deploy.sh 01a80f5 --migrate` then verified no pending migrations, installed locked dependencies/assets, refreshed services and passed readiness. Runtime and source implementation revision matched; no remote push was needed because runtime's origin is the local source repository.

Preserved another private environment backup and enabled only `MCP_LIVE_AUTOMATION_ENABLED=true`; writes were already explicitly true. Recached config, signaled queue restarts and restarted FPM. Verified the background five-minute phase-one live command is scheduled, the competing paper/standalone collector is absent, and no legacy crypto/paid AI job was introduced. Fresh read-only broker sync `automatic-activation-20261026-session1` completed successfully. No manual live cycle, broker preview or place call was used for verification.

At **16:04:06 UTC / 11:04:06 Central**, recorded owner-authorized automatic activation v1 for live policy v1, after verifying the policy and setup stop had not changed. The owner had explicitly confirmed sole execution in this session. This released setup emergency disable (controls v2), preserved buys unpaused, and pinned the independent activation checkpoint. Both engine/write flags and the account switch are true; activation/recovery reason is null. Readiness is 200 and config cache is `0600`. A read-only rendering of the deployed automatic-trading panel confirms On status and both Stop/Pause controls. The full Control Center read model takes database locks, so the read-only verification rendered only the new panel; existing HTTP integration tests cover the complete page and middleware.

### Native scheduler lifetime correction

The first normal run started at 16:06:06 UTC but its background worker did not survive the short `schedule:run` LaunchAgent parent. The database retained a running cycle and a read-only monitor run at the first portfolio step; process inspection confirmed no remaining live-cycle worker, no evaluations and **zero intents**. Automatic trading was immediately stopped (activation v2 / controls v3); no broker mutation had occurred.

The installed scheduler lacked `AbandonProcessGroup`. Apple's [launchd manual source](https://github.com/apple-oss-distributions/launchd/blob/main/man/launchd.plist.5) documents cleanup of remaining process-group children when a job exits, and the setting that disables it. Added that setting to the checked-in scheduler template and, after preserving a private backup, the installed scheduler plist. Rebootstrapped only the scheduler LaunchAgent. `deploy/macos/check-background-processes.py` is a native local process/file-only drill: a child that waits after its parent exits is killed under the default control and completes under the configured setting. Actual result: `default_control=false`, `configured=true`; temporary agents/files cleaned up. Plist lint and the existing deployment guards passed (2 tests / 16 assertions). No broker/database call occurs in that drill.

With automatic OFF, zero intents and the worker proven absent, narrowly recorded only that interrupted cycle/read run failed (`scheduler_background_terminated`), retired its staged read lease and cleared only its account-cycle and live-schedule mutexes. No order or fill state was altered. A fresh read-only recovery sync completed successfully before resuming. No automatic run was manually replayed. The execution-account slot uniqueness and old activation ID still fence the interrupted run.
