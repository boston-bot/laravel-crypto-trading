# M6 order reconciliation repair — October 28, 2026

## Incident

The ordinary October 26 automatic cycle recorded CRWD BUY intent #1 for `0.045547` shares. It was claimed at 19:41:15 UTC and became unknown at 19:41:19 UTC. Broker history reports a matching filled buy created at 19:41:19 UTC with one execution at `261.859900`, but its reference differs from the immutable intent's key. Automatic reference matching cannot prove ownership. The original placement response and specific failure category were not retained; the history discrepancy does not establish exactly why the placement response was rejected or whether the broker rewrote the submitted reference.

The owner reviewed the external order October 27 at 22:16:06 UTC. That acknowledgment resolved the external-order incident, but left the execution journal unknown. Four October 28 WSM BUY signals between 08:46 and 09:21 Central were blocked by `open_order_requires_reconciliation`. The dashboard nevertheless displayed “On · buys and exits,” and operational health lost its warning whenever a later completed cycle had no signal and a null reason.

Earlier conversational claims that CRWD was definitely outside app execution, and that external review alone restored entries, were incorrect. Ownership needs explicit owner linking here; reference matching is not silently relaxed.

## Changes and limits

- The automatic panel derives its blocking order summary from account-scoped dispatches, independently of the latest cycle, explains that buys and exits are blocked, and links to reconciliation controls.
- Operational health continues reporting a blocked account while any blocking dispatch remains, even after a completed cycle with no signal.
- The reconciliation panel distinguishes alert acknowledgment from intent linking and displays the latest journal reason.
- Owner linking reuses fresh, complete, current-authorization, contract-pinned equity order history to record broker outcomes/fills in the same transaction. Missing or stale evidence leaves the order accepted and blocking until monitoring reconciles it. Quantity/time/account checks, execution-ID deduplication and contradiction checks remain in force.
- Future ambiguous submissions retain only fixed safe failure categories; raw exception messages and broker text are not persisted. Unknown submissions are never retried. Historical events are unchanged.

No migration, financial backfill, dependency upgrade, risk-policy change, broker-approval change or reference-matching relaxation. Unrelated crypto spread work is excluded from the commit/deployment. No live broker mutation is used as a test.

## Verification and rollout

Composer validates; locked Laravel is v12.70.0 and MCP SDK v0.8.1. Frontend build, scoped Pint and whitespace checks pass. Focused PostgreSQL recovery/dispatch/concurrency: 66 tests, 460 assertions before the additional persistent-health regression. Full SQLite: 864 total, 114 skips, 5,246 assertions. Full PostgreSQL: 864 total, one skip, 6,172 assertions. Both full runs include the unrelated working-tree crypto tests; only reconciliation files are committed/deployed. An initial regression expectation was updated from accepted to immediately filled; the next targeted run passed. One sandbox-denied PostgreSQL rerun was repeated successfully with database access. Actual rollout is recorded below when completed.

Predeployment encrypted backup `mcp-trading-20261028T145351Z.dump.enc` passed isolated restore: 106 tables, 138,497 counted rows, encrypted fields readable, scratch database dropped. Offsite copy succeeded locally; no independent-device retrieval of this pair is claimed. The independent execution checkpoint is preserved outside database backups.

Operational recovery uses the authenticated Control Center to link CRWD to intent #1, then verifies a broker-confirmed filled outcome and exactly one execution. This sends no broker order. Ordinary scheduled operation stays under existing owner activation/policy and every submission check; missed WSM signals are not replayed.

M6 remains partial. Next: observe ordinary scheduled operation and verify future broker reference behavior without a synthetic live order. Cancellation/recovery and remaining milestone acceptance are unchanged.
