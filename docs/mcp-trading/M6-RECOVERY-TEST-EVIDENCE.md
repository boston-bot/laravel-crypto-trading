# M6 recovery verification — October 26, 2026

## Runtime verification

The owner reported deployment of `66b3a93` and completion of the two runtime flag edits. Verified the deployed revision, no pending migrations and HTTP readiness 200. The runtime environment had both flags true but its cached configuration still had them false. Rebuilt configuration with the pinned runtime PHP, signaled queue restarts and restarted the dedicated PHP-FPM LaunchAgent under the actual GUI UID. The configuration cache remains private (`0600`).

At 14:27 UTC, the cached values were `ROBINHOOD_MCP_ACCOUNT_ELIGIBILITY_ENABLED=true`, `MCP_LIVE_EXIT_SIGNALS_ENABLED=true`, and `BROKERAGE_WRITES_ENABLED=false`. A read-only monitoring sync completed at 14:25:08 UTC. Its fresh account metadata passed `AccountEligibility`; the broker approval-setting read was complete and reported `human_must_approve_trades=false`. There was no applied live policy. These are time-specific observations; submission still requires current evidence and matching policy at each boundary. No approval setting was changed and no order was sent.

## Initial recovery tests

- Two independent PHP processes open read and execution MCP sessions against an expired synthetic credential. They share the database cache lock, perform exactly one fake OAuth refresh, and initialize sessions using the persisted refreshed token. Restarting the read process does not refresh again. The fake HTTP server rejects all tool calls; opening a test execution session never reviews or places an order. Existing OAuth tests separately cover revocation, ambiguous refresh failure and reconnect during refresh.
- Real encrypted PostgreSQL backups capture synthetic `submitting` and `accepted` intents, stable idempotency keys, and another staged intent for the same account. The source subsequently advances to acceptance or a broker-confirmed synthetic fill. The normal restore drill recreates the older snapshot in an isolated scratch database. Both restored in-flight intents refuse redispatch, the staged intent remains blocked, and the fake gateway receives no calls. The drill verifies counts/decryption and drops its scratch database. The guarded source test database remains `mcp_trading_test`; no runtime backup or restore was performed.

Composer validation, scoped Pint and whitespace checks passed. Focused PostgreSQL verification: **34 tests, 199 assertions**, covering the new cases plus existing OAuth, live dispatcher and backup-drill tests. No failures or skips in that run. No dependencies, production code, migrations or frontend assets changed; no frontend build was needed. Previous full-suite evidence remains attached to `66b3a93`; a new full-suite run is not claimed.

## Recovery safeguard implementation

The owner then requested proceeding with the earlier-snapshot safeguard. The source now adds `ExecutionRecoveryFence`, a private per-account local checkpoint outside PostgreSQL. First-use initialization requires an operator-owned connected agentic account, brokerage writes disabled and no existing intents for that account. The command creates the file exclusively, with no overwrite/reset path. Its configured directory is private `0700` and the file `0600`; symlinked, missing, public, wrong-owner or malformed state is refused. The default configuration is unset, which denies claims. No checkpoint is automatically created by dispatch.

After every ordinary submission check, while holding the PostgreSQL account lock, the journal verifies the previous checkpoint against the persisted claim token, immutable intent fingerprint and idempotency key, and total account claim count. It then saves the next checkpoint with `fflush`/`fsync` **before** committing the new database claim. External broker review/place cannot run until that claim commits. A disk reservation followed by a failed database commit conservatively blocks the account. The checkpoint is checked again after preview, before place; a denial withdraws the unsent order. The manual order command also checks it before staging, so a missing initial checkpoint cannot create an intent that prevents first-use provisioning.

Real encrypted dump/restore tests now cover four snapshot cutoffs: before any intents, staged, submitting and accepted. The source subsequently advances to acceptance or a broker-confirmed fill. Restored earlier snapshots cannot dispatch through a new dispatcher using the retained checkpoint; in-flight snapshots retain their existing account block. The fake gateway sees no calls. Additional tests exercise damaged/missing/public/symlinked/unconfigured checkpoints, loss during preview, normal sequential claims, file rollback, database rollback after the disk reservation, operator-only first-use provisioning, disabled-write enforcement and refusal to recreate a checkpoint once intents exist. Existing process-concurrency tests share the same isolated test checkpoint and still prove one submission owner.

There is no new migration, backfill, dependency or frontend change. No runtime configuration, checkpoint, policy or brokerage state was changed by this source increment. Runtime still runs `66b3a93` with brokerage writes disabled. Deployment needs the new private path and explicit first-use provisioning, documented in the [operator procedure](MAC-SETUP-AND-OPERATIONS.md#execution-recovery-checkpoint-october-26-source-increment).

Final verification after the manual-command preflight change:

| Suite | Result |
|---|---|
| SQLite | **716 passed / 97 skips, 4,958 assertions** |
| PostgreSQL | **812 passed / one existing environment skip, 5,761 assertions** |

Both suites contain 813 tests. SQLite skips the PostgreSQL-only ownership/restore cases. The focused final checkpoint/dispatcher run also passed **26 tests / 158 assertions**. Composer validation, scoped Pint and whitespace checks passed. No unresolved test failures remain. No frontend build was required because assets did not change.

## Recovery limits and remaining gates

The safeguard establishes fail-closed rollback detection when the independent current checkpoint is retained. It does not reconstruct missing intents/fills or automatically clear a recovery block from a fresh broker read. A stable `ref_id` alone is not proof of safe replay. Preserve the checkpoint separately from database snapshots, never roll it back with a restored database, and never delete/edit it to unblock trading. Losing or rolling back **both** stores cannot be detected from these two stores alone. Replacement-machine/disaster recovery requires an explicit owner-reviewed reconstruction and broker reconciliation procedure; no automatic reset or rearm command is supplied.

After any restore, leave writes disabled, recover the authoritative execution journal and current checkpoint, reconcile broker orders/approvals/fills and resolve incidents before any later explicit live activation. If the records cannot be recovered, remain blocked. Normal broker reconciliation remains available while dispatch is fenced.

M6 remains partial. Next: review/deploy and provision this safeguard, obtain explicit owner live capital/risk/order/approval settings, match the current broker approval setting, and separately authorize a supervised pilot. Cancellation/approval-decline adapters and the bounded scheduled execution loop remain unimplemented. The current dispatcher/manual evaluator does not establish unattended trading readiness. No live activation or trade authorization is supplied by these tests or this document.
