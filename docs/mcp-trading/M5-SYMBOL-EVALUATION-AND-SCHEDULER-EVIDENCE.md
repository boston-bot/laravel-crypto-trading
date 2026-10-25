# Immediate symbol evaluation and wall-clock scheduling — October 25, 2026

M5 source increment; not committed or deployed by this task. Builds on the [Friday review](M5-OCT23-FORWARD-PAPER-REVIEW.md). No live execution authorization.

## Diagnosed causes

Friday's 1,926 post-deployment evaluations included 125 with source age over 20 seconds. Scoped, read-only PostgreSQL inspection separated these into **75 already stale when the input was recorded** and **50 that were fresh at recording but aged while waiting for other symbols in their quote batch**. For example, TMO input 6546 was 17.059 seconds old when recorded and waited another 8.378 seconds before evaluation; LMT input 6619 was 13.850 seconds old and waited another 8.263 seconds. This supports removing the local batch wait; it does not justify extending freshness limits or treating stale provider prices as current.

Both missing Friday cycles are explained by scheduler startup timing, not observed order errors. The UTC scheduler log shows an invocation beginning at **18:24:59** with monitoring/health work continuing into 18:25:00, followed by its next invocation at **18:26:01**. The same pattern occurred at **19:29:59**, followed by **19:31:01**. Laravel selects the due events for the invocation before processing them, so the five-minute tasks were absent in both invocations. PostgreSQL has no market inputs or strategy cycle for 18:25 or 19:30 UTC. The installed LaunchAgent uses `StartInterval=60`; its elapsed cadence drifts across wall-clock minute boundaries as work runs. Other longer foreground paper cycles also delay subsequent scheduler invocations.

## Source changes

- `PaperMarketInputs::collectMany` retains batched quote requests and its existing after-batch consumer, and adds an optional after-record consumer. It runs only after each input is persisted, before gathering the next symbol. Retried requests deliver the existing immutable row to the consumer without refetching it. A consumer failure leaves that recorded input available for a safe retry.
- The combined forward-paper collector uses the after-record consumer. Existing fills, opening valuation checks, signal recording and paper handoff therefore run per stored symbol. Existing scoped locks, same-window signal uniqueness, immutable evidence, held-symbol priority, read budget/backoff and the shared MCP session remain in force. There are no additional provider reads from this change. More frequent local transactions may add overhead; measure it after deployment.
- The scheduler LaunchAgent template uses `StartCalendarInterval` with all calendar fields omitted, rather than `StartInterval=60`. The installed macOS `launchd.plist(5)` documentation defines omitted calendar fields as wildcards, giving every wall-clock minute. `RunAtLoad` is preserved. This is a template change only: ordinary source deployment does not rewrite the installed LaunchAgent.

All price equality, quote-age, eligibility, risk, order and live-write gates remain unchanged. A quote already stale when recorded is still rejected. Sleeping/unavailable hosts, commands lasting across further due minutes, unavailable provider evidence and unresolved corporate actions remain separate limitations. This does not replay missed historical trading windows.

## Verification

The new regression uses one quote batch for four symbols, with seven-second identity reads. It asserts evaluation happens before the next symbol read: source ages are 7, 14, 21 and 28 seconds, rather than deferring all symbols until 28 seconds. The first quote passes the existing 20-second reader and the last fails it. One broker session/quote batch is retained; retry preserves original signal fingerprints and makes no new broker read or paper order. Another regression crashes the per-record consumer after the first persisted input and verifies retry resumes with that same row and fetches only the remaining symbol.

Focused SQLite and PostgreSQL each passed **18 tests / 189 assertions**. Complete SQLite: **716 tests / 4,655 assertions, 675 passed / 41 skips**. Complete PostgreSQL: **716 tests / 5,033 assertions, 715 passed / one existing environment skip**. Composer validation and installed lockfile inspection passed (Laravel 12.70.0, MCP SDK 0.8.1). Scoped Pint, diff whitespace checks and `plutil -lint` passed. No unresolved test failures.

The runtime diagnosis read stored observations/cycles and monitoring outcomes under a read-only PostgreSQL transaction and inspected scheduler logs/installed plist without changing them. No provider call or manual paper evaluation was triggered. No application frontend asset, dependency, migration or backfill changed. Live firing of the new LaunchAgent and market-session latency improvements are unverified until deployment/observation.

## Deployment and next acceptance work

Deploy a reviewed committed source revision using the existing application deploy procedure. Separately back up the installed scheduler plist, replace only its elapsed scheduling key with the wildcard calendar dictionary, validate it, and reload only `local.investing-ops.scheduler` through launchctl. Preserve its dedicated PHP path, environment, log paths and all private runtime configuration. Do not rerun the full installer merely to update scheduling; the installer could overwrite the existing LAN/Nginx configuration. This source task did not deploy code or change the installed LaunchAgent.

Observe several minute boundaries and a full market session: scheduler starts, expected five-minute cycles, quote source age at recording/evaluation, local processing time, quote/book matches and provider backoff. Measure rather than asserting a recovered cycle or freshness improvement. Next M5 work also includes verifying the PLTR/ELV profiles against their newer reference data and reviewing TMO/ELV corporate-action reconciliation. M6 remains unstarted.
