# M5 dedicated strategy watchlist intake

Source increment, October 18 Chicago / October 19 UTC. This is the next implementation after broker read efficiency. It implements the watchlist-intake checklist item; the deterministic eligibility/profile/signal engine and external-order incident gate remain separate unfinished M5 items. No live execution is authorized.

## Delivered behavior

- The authenticated Control Center saves an immutable, encrypted, account-scoped version of the dedicated list name. It requires confirmation that the list is separate from personal lists and refuses the existing personal `On Watch` name. Optimistic version checks and request IDs prevent silent overwrite or duplicate settings. Neither settings nor membership can enable intake, change applied execution rules, or create an order.
- `MCP_STRATEGY_WATCHLIST_ENABLED=false` is an independent default. Existing broker and read-only watchlist gates still apply. When enabled, the existing scheduler runs `mcp:strategy-watchlist --once` every five minutes. An optional `--account` selects a local connection ID. A stable per-settings/auth-generation/window request ID makes repeated dispatches idempotent; failed windows retry in the next window.
- Intake uses `RobinhoodWatchlists` and its existing read-only gateway/session, complete pagination and list-identity/count recheck. There are no new broker tools, cloud ingress, model calls or watchlist writes. Broker source time stays null; the broker's multi-tool read is explicitly not atomic.
- Every successful read retains an immutable encrypted membership revision, including unchanged lists, with normalized members/object types, canonical SHA-256 hash, retrieval time, original membership change time and authorization generation. This follows AGENTS.md's stronger every-success rule; the earlier design sentence about deduplicating unchanged revisions was corrected. Retries of the same logical read return the existing record. A same-named replacement list ID is rejected until a new settings version is saved.
- More than 75 total items rejects the entire read. Non-equity items and instruments without usable symbols are retained with skip reasons; membership does not assert stock/ETF classification or execution eligibility. Empty successful lists are valid and immediately remove all nominations.
- Failed/rejected reads retain the last successful revision for strictly less than 30 minutes. Failures never extend its retrieval time. The nomination gate returns no symbols at the 30-minute boundary, while disabled, after changed settings/auth/account/operator authorization, or without a valid observation. Point-in-time queries exclude later settings and reads.
- Removed symbols lose nominations immediately. Intake never changes held shares, cash, exit rules or reservations. A synthetic buy/removal/expiry/sell test verifies that an existing paper position can still exit independently.
- Failed/over-cap/expired reads create scoped incidents through the existing outbox. Recovery resolves them. An unchanged list spanning two complete verified trading sessions raises an informational quiet-list incident; it does not block entries. Calendar gaps never invent trading days. A membership change resolves the quiet incident. Actual phone delivery was not tested.
- Network calls happen outside database transactions. Cache locks limit overlapping requests; a short connection-row lock serializes authorization/settings rechecks and the atomic revision/read/incident commit. Credentials, bindings or settings changed during a read discard the observation. A failed commit rolls back membership and can safely retry. Persistent uniqueness also protects concurrent workers even when their cache locks are not shared.

## Storage and verification

One additive migration, `2026_10_19_000017_create_mcp_strategy_watchlists.php`, adds:

| Table | Purpose |
|---|---|
| `mcp_strategy_watchlist_settings` | Encrypted dedicated-list names, actor, scope, version and request identity |
| `mcp_strategy_watchlist_revisions` | Encrypted immutable successful membership evidence, hash, authorization and times |
| `mcp_strategy_watchlist_reads` | Immutable success/failure outcomes with a redacted reason or revision reference |

No backfill. PostgreSQL enforces version/request uniqueness, foreign keys, positive settings versions and exactly one read outcome (revision or failure reason). Eloquent's existing immutable-record protections reject updates/deletes to history. All migration testing used the guarded `mcp_trading_test` database and verified the `mcp_trading` schema; no operator database migration was run.

Focused tests cover caps including skipped items, stable hashes under reordering, unchanged successful refreshes, failure grace/expiry/recovery, empty lists/removals, settings/list identity changes, revoked authorization, no look-ahead, atomic rollback/retry, held-position exits, quiet alerts, default-off dispatch, duplicate windows, request idempotency and operator/account-scoped Control Center forms. Three concurrent PostgreSQL workers must return one persisted read/revision; invalid database outcomes are rejected. The dashboard action inventory explicitly includes the new CSRF-protected settings form. External requests are faked/rejected.

Full PostgreSQL: **423 passed / 3,065 assertions, 1 existing PHP-FPM environment skip**. Full SQLite: **397 passed / 2,839 assertions, 27 PostgreSQL-only skips** (424 total each). Final focused PostgreSQL: **13 tests / 88 assertions**, including the three-worker race and database outcome constraint. Composer validation, changed-file Pint, frontend build and diff checks are part of this increment. The initial full PostgreSQL run exposed fixture cleanup and dashboard action-inventory failures; these were corrected. Focused PostgreSQL also caught an overlong incident-source label; it now uses the existing 16-character column contract.

## Runtime and remaining work

No deployment, runtime migration, configuration enablement, dedicated-list selection, live broker read, AI request, brokerage mutation or commit. The operator must supply the dedicated list name through the new Control Center form after deployment and separately enable read-only intake. No credentials were read from ChatGPT/Codex/browser sessions. Existing uncommitted broker/classification work was preserved.

The gate exposes entry nominations for the future deterministic engine; it is not wired into the superseded AI handoff. Existing execution v2's fixed allowlist, fees and position cap remain unchanged. No strategy entry engine exists yet, so this is not evidence of automatic decisions or complete M5 acceptance. Full classification/tradability/action/price/liquidity/spread/history eligibility, market data/profiles, signal-based handoff, external-order review, realistic costs and fractional execution support remain open. Quiet-alert verification depends on existing verified calendar records. Browser visual review, populated watchlist backup/restore and actual dedicated-list connectivity remain untested.

Next within M5: external-order detection/review and rule-based eligibility, followed by the documented market-data/profile/signal work. M6 remains unstarted and unauthorized.

## Multiple lists with roles (October 19)

The owner's ChatGPT tasks maintain three Robinhood lists: "Core candidates", "Growth opportunities" and "Awaiting price or evidence". The owner defined Growth opportunities as researched candidates to evaluate for a trade now, subject to fresh valuation, earnings, position-size, exposure and technical checks; membership alone is not a buy signal.

A settings version now holds up to five named lists, each with role `nominate` or `data_only`. A single nominating list keeps the original payload shape, so earlier versions still work. Validation:
- names are unique, case-insensitive
- "On Watch" is refused in any position
- at least one list must nominate
- roles come from a closed set

Reading and membership:
- **One read.** All lists are read in one broker session (`RobinhoodWatchlists::namedMany`) and stored in one immutable revision. Each member is tagged with its list and role, and the revision keeps every list's ID.
- **All or nothing.** A failed read of any list records a failed read and no revision, so a failed Core read can never look like an emptied Core list.
- **Cap.** The 75-item cap applies separately to nominating and to data-only items (skipped items count).
- **Identity.** Each list's identity is pinned per settings version.
- **Nominations.** These come only from nominating lists; a symbol on both a nominating and a data-only list nominates.
- **Warm-up.** Data-only symbols are reported separately and added to the selected data universe (collection, SEC classification, references, profiles). They never pass the eligibility membership check.

The Control Center form offers five rows (list name and role) and ignores blank ones.

Verification:
- **New tests:** `StrategyWatchlistsTest` gained 4 (roles and warm-up, moves between lists, any-list failure, per-role caps at 75/76, per-list identity pinning, validation, form save and display); `RuleEligibilityTest` gained 1 (data-only symbols enter the data universe but fail membership).
- **Full SQLite:** **464 passed / 3,330 assertions, 31 PostgreSQL-only skips**.
- **Full PostgreSQL:** **494 passed / 3,617 assertions, 1 existing skip**.
- **Migration:** none; settings and revisions keep encrypted payloads.
