# M5 external-order detection and owner review

Source increment, October 18 Chicago / October 19 UTC. Implements the next M5 checklist item after dedicated watchlist intake. This is local source and fake-provider verification, not a deployment or live activation.

## Behavior

`MCP_EXTERNAL_ORDER_DETECTION_ENABLED=false` independently defaults detection to off. When enabled, the existing monitor records normalized equity-order reads through the existing read-only gateway. No new broker tool, scheduled service, AI request, brokerage mutation or order-submission path was added.

There is no agentic submission boundary before M6, so **every observed equity order is external**. Filled, cancelled, rejected, pending and sell orders all require review. A broker ID that resembles a paper/legacy ID is not proof of app ownership; neither broker text nor legacy crypto tables can grant that ownership. Future M6 ownership must be tied to durable agentic intents and reconciliation in a separately reviewed change.

Detection runs while staging the order read, before complete-snapshot publication. Even an incomplete page can prove an order exists: its evidence and pause survive a later failed snapshot. Incomplete coverage cannot prove an absence of orders. Changed authorization/account binding discards the observation. Retry attempts retain separate immutable read evidence under the run's claim, including retries of the same snapshot after a partial result; additional orders on retry cannot be skipped.

Each previously unseen order creates an encrypted immutable case, scoped to the local connection and bound account. Repeated reads or ordinary status changes for an already reviewed order do not create a new case. New identities create new cases. A critical `external_orders_review_required` incident goes through the existing incident/outbox mechanism and remains latched while any case lacks a review. Empty reads, alert acknowledgments, restarts, reauthorization or disabling detection do not dismiss existing cases.

The authenticated Control Center displays each pending order's identity, first-observed symbol/side/status and retrieval time. A CSRF-protected, account-scoped form requires explicit review confirmation and a note for that individual immutable case. Reviewer identity comes from the authenticated server context. Reviews are encrypted and append-only, with stable request IDs and one review per case. Reviewing the last pending case resolves the incident; reviewing an older case cannot clear a concurrently discovered new case. There is no blanket resume or ownership-import action. Review does not cancel an order, grant trade authorization, or enable a feature.

## Entry controls and exits

- Dedicated-watchlist nominations return no symbols during an external-order pause. Fresh membership remains distinguishable from entry readiness.
- New real-data paper buys are rejected with an audited reason. Existing real-data paper buys are invalidated before further fills and release remaining reserved cash, including when fresh market data is unavailable.
- Held-position sells remain available under their existing exit/data/risk checks. Synthetic offline scenarios remain separate from real-account incident state.
- With detection enabled, the latest order read must be complete, from the current authorization generation, match the current reviewed order-contract fingerprint and be less than five minutes old. Missing, partial, future or expired coverage blocks buys with `broker_order_history_unverified`. There is no watchlist-style grace period for order coverage. Disabling detection cannot bypass an already latched review case.
- Detection/review and paper entry/fill checks serialize on the same connection row; no database lock is held across an external request. Future signal/live paths must use this gate under that lock and must require detection enabled as part of activation readiness. There is no signal/live path yet, and the superseded AI handoff stays disabled.

The reviewed source is the exposed **equity-order feed**. The app cannot prevent the ChatGPT connection from trading or detect orders the broker never returns. Order source timestamps remain exactly as supplied, including null; retrieval recency does not manufacture a broker source timestamp. No new claim of coverage for options, crypto, hidden or older unavailable order history is made. This increment does not complete M5 or authorize M6.

## Persistence and verification

Additive migration `2026_10_19_000018_create_mcp_external_order_reviews.php` creates three tables:

| Table | Purpose |
|---|---|
| `mcp_external_orders` | Immutable first discovery and encrypted order evidence, bound-account scope, hashed identity and snapshot provenance |
| `mcp_external_order_reviews` | Immutable encrypted owner note, authenticated actor, unique case and review request |
| `mcp_broker_order_reads` | Immutable encrypted normalized read, completeness, schema fingerprint, authorization, run-attempt identity and timestamps |

Foreign keys and unique indexes enforce identities, per-case reviews and per-attempt read deduplication. No backfill or runtime migration was performed. Persistence/schema/concurrency tests use only the guarded PostgreSQL test database; all external providers are faked and unexpected HTTP requests are rejected.

Tests cover default-off behavior; filled/cancelled and legacy-like IDs; repeated reads; partial pages and failed snapshots; partial-result retries; empty-read and acknowledgment persistence; per-case review/idempotency; reopening on a new identity; authorization/account changes; cross-operator isolation; encrypted immutable evidence; transaction rollback/retry; watchlist nomination suppression; stale/incomplete coverage; rejected buys; cancellation/reservation release without fresh quotes; and successful held-share sells during a pause. PostgreSQL workers test duplicate detection, duplicate reviews and a concurrent new order versus an older review. Workers' outputs are validated as well as their process status.

Full PostgreSQL: **437 passed / 3,184 assertions, 1 existing PHP-FPM environment skip**. Full SQLite: **410 passed / 2,935 assertions, 28 PostgreSQL-only skips** (438 total each). Final focused PostgreSQL: **14 tests / 121 assertions**, including all concurrent detection/review checks after the final contract-pin guard. Composer validation, changed-file Pint, frontend build and diff checks accompany this increment. The initial concurrency run encountered a stale test-schema fixture while the additive migration was being revised; the final schema and isolated rerun passed. No runtime schema was affected.

## Runtime limits and next work

No deployment, runtime flag change, account/approval change, live broker read, notification delivery test, AI call, live order or commit. No Hjarni access. Existing unrelated/uncommitted work was preserved. Browser visual review and populated backup/restore of the new evidence tables remain untested; the existing schema-wide backup includes them after deployment.

Next within M5: deterministic rule eligibility to replace the fixed allowlist, then the documented daily history, earnings/calendar, profiles and signal-based execution work. Runtime execution v2 and all its applied settings remain unchanged. M6 is unstarted and unauthorized.
