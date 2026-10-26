# M6 execution journal foundation

Superseded in part, October 25 (later): production `SubmissionChecks` no longer always denies, and the lifecycle now continues through broker-confirmed outcomes. See [live policy, checks and outcomes](M6-LIVE-POLICY-AND-OUTCOMES-EVIDENCE.md). The record below describes the first increment as delivered.

October 25, 2026 Central. The owner requested beginning live-execution implementation following discussion of a small supervised pilot. This authorizes development; no live policy, brokerage mutation, approval-setting change or activation was requested or performed. Source only. M6 is now partial; M5 acceptance remains partial.

## Delivered behavior

`ExecutionJournal` records an unapproved equity intent against an existing deterministic signal, scoped to the operator, broker account and authorization generation. It retains signal/profile/watchlist/input references, rule and parameter versions, exact decimal quantity/price bound, a proposed policy fingerprint and a submission deadline. The encrypted payload is hidden from ordinary model serialization. The fingerprint is evidence only: it is not an applied live policy or approval.

Each account/signal pair has one immutable intent and a stable UUID idempotency key. Identical retries return the same intent; changing quantity, policy evidence or another payload field is refused. Intents, the initial audit event and a single durable dispatch/outbox row commit together. A submission deadline may not exceed the signal's five-minute validity window; it is not the broker order's expiry. Fractional limits, unquoted/exponential/nonpositive decimals, excessive precision and unexpected fields are refused. Paper cash, holdings and fills are not modified.

The journal implements only these transitions:

```text
staged -> cancelled
staged -> expired
staged -> submitting -> unknown
```

`claim()` requires PostgreSQL, checks current account/operator authorization, locks a shared account row and grants at most one persisted claim token. The account lock uses the existing account-scope HMAC, so unresolved submissions block other connections and authorization generations for that same account. A claim cannot run inside an outer transaction: ownership must commit before any future network request. A duplicate claim returns no token. There is no lease expiry, reclaim or automatic retry.

Both `submitting` and `unknown` block further claims for the account, including sells in this initial conservative implementation. A caller retaining the claim token can append an unknown outcome after a crash or lost response, even after the original authorization disconnects. A wrong token is refused. Unknown outcomes cannot be locally cancelled or reset. Reconciliation and confirmed terminal outcomes must be implemented before this lifecycle is usable for trading.

Production `SubmissionChecks` always denies with `live_submission_checks_unimplemented`, even if `BROKERAGE_WRITES_ENABLED` is true. Tests substitute synthetic admission solely to exercise storage ownership. No broker write gateway, dispatch job, command, route, activation flow or scheduler integration has been added. The durable dispatch row is an outbox foundation, not an operating external dispatcher. No new environment flags or dependency changes.

## Persistence and migration

Additive migration `2026_10_26_000030_create_mcp_execution_journal.php` creates four tables: `mcp_execution_accounts`, `mcp_execution_intents`, `mcp_execution_dispatches`, and `mcp_execution_events`. No backfill, existing-table rewrite, cash initialization or frontend asset change.

PostgreSQL adds uniqueness, foreign keys, deadline/state/version/claim constraints, immutable-intent/event update/delete triggers, a dispatch-delete prohibition and a transition trigger preventing reversal/replay. Two schema-qualified trigger functions are installed in `"mcp_trading"`. SQLite supports fast journal-input tests; claims require PostgreSQL and the authoritative database constraints are verified there. Test refresh uses `CREATE OR REPLACE FUNCTION` because dropping tables does not drop the trigger functions.

The migration was exercised only in guarded test database `mcp_trading_test`, schema `mcp_trading`. It is not applied to the operator database. Future deployment must follow the operator backup/restore, owner-role migration and runtime-grant procedure, then verify existing ledgers and flags are unchanged. The existing migration search-path guard still applies. No deployment was performed.

## Verification

Locked/installed dependencies inspected: Laravel 12.70.0, MCP SDK 0.8.1, Brick Math 0.14.8, PHPUnit 11.5.55; PHP 8.5.12. Composer validation and scoped Pint passed; no dependency changes. No frontend build was needed because no frontend assets changed.

- Focused SQLite: **6 tests / 41 assertions**, passed.
- Focused PostgreSQL: **12 tests / 87 assertions**, passed.
- Full SQLite: **690 passed / 47 skips, 4,749 assertions** (737 total).
- Full PostgreSQL: **736 passed / one existing environment skip, 5,173 assertions** (737 total).

Coverage includes identical/conflicting intent retries, encryption, paper-ledger separation, decimal and provenance refusals, atomic rollback, idempotent cancellation, independent commit requirements, expiry, production refusal with either write-flag value, three-worker staging/claim races, two competing orders, wrong-token fencing, restart/authorization-change recovery, shared-account blocking through another connection and raw-SQL immutability/replay refusals. PostgreSQL fixture cleanup temporarily disables user triggers only inside a guarded, scoped test-owner transaction; runtime code has no such operation.

Initial checks exposed a test helper name conflicting with Laravel's `seed()`, an incorrect assumption that authorization generations start at one (the repository starts at zero), and trigger-function survival across test schema refresh. Those were corrected before the passing runs. The sandbox initially blocked the PostgreSQL socket; verification used the approved test command outside the sandbox. No broker/provider call or real order occurred.

## Remaining work before any live pilot

1. Explicit, versioned live policy and account/sleeve allocation, based on broker-confirmed balances/positions. Paper signals can supply deterministic rule evidence, but paper-position exits and paper buying power cannot authorize live decisions. Adapt position facts to live holdings without building a second strategy engine.
2. All local submission checks: policy/risk/cash reservations, data freshness, eligibility/session, approvals, external-order incidents, buy pause and emergency disable. Confirm account trading eligibility independently of its existing `agentic_monitoring` role.
3. Separate Robinhood execution session/allowlist, reviewed broker preview/submission/approval/cancel contracts, bounded requests and approval-to-order reference mapping. Verify actual broker idempotency support; the journal UUID alone does not prove broker deduplication.
4. Broker reconciliation, confirmed open/partial/filled/rejected/cancelled states, fill accounting and complete-history requirements. Implement restored-backup/restart fencing before adding an external dispatcher.
5. Remaining M6 fault tests at the actual execution boundary: accepted-but-lost response, partial fill/cancellation, refresh-token race, policy/approval expiry, manual buying-power consumption, incomplete history, emergency disable during queued work and stale intents restored from backup. Current storage tests do not claim these broker-level cases passed.
6. Fresh-session verification of the deployed cadence/freshness fixes and applicable M5 data gates. A smaller symbol set may proceed only through its existing valid eligibility checks; action/classification blockers remain exclusions.
7. Reviewed deployment and separately explicit owner live settings/activation, followed by a supervised small pilot and broker-confirmed results. No long observation-period waiver or successful performance acceptance is claimed by this increment.

Next increment within M6: live-policy/admission and confirmed order reconciliation design and implementation with fake broker fault tests. Live writes remain disabled.
