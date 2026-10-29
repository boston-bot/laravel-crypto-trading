# M6 correlated submission receipts — October 29, 2026

## Problem and authority

The owner requested fixing the recurring reference mismatch so future trades do not require the same manual repair. CRWD and PSX broker histories report references different from their immutable intent keys. PSX intent #3 recorded `submission_unknown_place_order_differs` after its ordinary October 29 placement attempt. The original gateway discarded even a matching order returned in the authenticated placement response if its reference differed; later history could not prove ownership by the submitted reference. The old raw responses were not retained, so the historical reference discrepancy does not prove every field in those responses matched, or establish why Robinhood used different references.

The owner already authorized bounded automatic operation and deployment in this session. This increment changes response recognition and evidence, not trade policy or permissions. No live order is used as a test.

## Ownership proof

An authenticated, JSON-RPC-correlated response to this app's single placement request is a separate ownership proof from later reference matching. The gateway now accepts its returned broker order ID only when symbol, side, exact decimal quantity, order type, time in force and market hours match. Stop prices are rejected; limit orders must echo the exact limit price. Creation time must be explicitly timestamped, no earlier than the existing one-minute clock allowance before the call and no later than response receipt. IDs must be nonempty bounded strings without whitespace, and an optional returned reference must be a valid UUID. Changed schemas still refuse before calling any tool.

The submitted idempotency key is unchanged and sent exactly once. A different valid returned reference is stored as evidence, not substituted into the intent. The new encrypted, immutable binding receipt contains both references, order ID, account scope/authorization generation, checked terms, creation time and local request/receipt times. The binding and accepted transition commit atomically; differing references get an explicit journal reason. Serialization hides the encrypted receipt and broker order ID.

Future read-only history reconciles by that bound broker order ID and existing execution-level deduplication. If a monitor observed the order before receipt persistence, its original external-order observation remains in history but no longer requires review after account-scoped ownership proof. Unrelated orders remain pending and their incident stays open. No owner review is fabricated.

A lost/unrecognized/uncorrelated response or contradictory terms remains unknown and is never resent. Without a receipt, history must still match the original reference or an authenticated owner must link the order. A crash between receiving the response and committing its receipt can therefore still require owner recovery. This is deliberate ambiguity handling, not a promise of uninterrupted trading. Existing approvals and edited approval behavior are unchanged.

## Migration and deployment

Additive migration `2026_10_29_000038` adds nullable encrypted `submission_receipt` text to `mcp_execution_broker_orders`. There is no financial or ownership backfill; previous bindings remain readable with null receipts. Existing immutable database triggers cover the new column. Runtime database is `postgres`, schema `mcp_trading`; use the existing owner migration helper and normal pinned-PHP deployment after a verified encrypted backup. Preserve the independent execution checkpoint and every account activation/risk/approval setting.

Old PSX intent #3 cannot be fixed by inventing a missing receipt. Its existing filled broker order requires the authenticated owner-link action; never replay the intent or submit a replacement order.

## Verification

Tests use only synthetic HTTP/MCP/broker data with unexpected HTTP refused. Coverage includes a valid different reference, unchanged request key, encrypted and hidden receipt, restart-style read reconciliation, exactly one execution, one place request, response/history races, unrelated external orders, malformed references, contradictory terms, wrong limit price, missing/stale/future creation times, and a JSON-RPC response for another request. Lost-response, approval, recovery-fence, restored-database and account/concurrency regressions remain in force. The initial end-to-end fixture omitted two enabled read-tool schemas; completing the synthetic catalog fixed its pre-send authorization refusal without changing production checks.

Composer validation, frontend build and scoped Pint/whitespace checks pass. Final PostgreSQL suite: 871 tests, 6,255 assertions, one environment skip. Final SQLite suite: 871 tests, 5,281 assertions, 117 PostgreSQL-only/environment skips. Targeted receipt/history-race/external-order concurrency tests passed (14 tests, 148 assertions). Live differing-reference response acceptance remains unverified until an ordinary authorized submission occurs; no manual live cycle or order will be triggered to test it.

Predeployment backup: `mcp-trading-20261029T182006Z.dump.enc`; encrypted copy verified locally and copied to the configured offsite folder. Isolated restore verified 106 tables / 182,496 counted rows, readable encrypted fields and scratch-database removal. Independent-device retrieval of this pair is not claimed. Runtime database/schema/owner verified read-only as `postgres` / `mcp_trading` / `admin`, with migrations through `000037` before deployment.

M6 remains partial. Next is observation of ordinary scheduled receipts and outcomes, plus the existing cancellation/disaster-recovery acceptance work.
