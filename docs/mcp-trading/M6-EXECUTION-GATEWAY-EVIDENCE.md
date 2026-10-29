# M6 execution gateway and dispatcher

October 29 follow-up: the original matching-reference requirement on a direct placement response is superseded by [validated, correlated submission receipts](M6-SUBMISSION-RECEIPTS.md). Broker history can report a different reference; exact response terms and request correlation prove the returned broker order ID, while lost responses still need exact reference proof or an owner link. Historical verification below describes the original implementation.

October 25, 2026 Central, night. This continues trading readiness after deploying `1232e12` and enabling the approval-setting read. Source only, tested only against a synthetic JSON-RPC server and a scripted fake gateway. Live writes stay disabled (`BROKERAGE_WRITES_ENABLED=false`). No order, review or approval tool was called against Robinhood, and nothing was scheduled. No live pilot is authorized by this document.

## Runtime state at the time of writing

- `1232e12` is deployed with migration `000032` applied.
- `ROBINHOOD_MCP_APPROVAL_SETTING_ENABLED=true` is live after a config rebuild.
- Two owner-requested foreground `mcp:sync` runs (03:11Z and 03:21Z) recorded a complete, trusted setting for the bound account (masked `…8750`): `human_must_approve_trades: false`.
- The owner reported turning approvals on, but the 03:21Z read still showed `false`. The likely cause is a different account or an unsaved change. Until the broker reports what the live policy states, every live order is denied.

## Delivered behavior

**Write session.** `SessionFactory::openExecution` opens a separate MCP session whose `tools/call` allowlist is exactly `review_equity_order` and `place_equity_order`. It refuses unless brokerage writes are enabled. Read sessions never include order tools, and a test proves a read session cannot call `place_equity_order` even with writes enabled.

**`RobinhoodExecutionGateway`** (bound to the new `ExecutionGateway` interface; only `ExecutionDispatcher` uses it):
- **Catalog check:** it lists the live catalog in the write session and refuses (`write_schema_changed`, nothing sent) unless both tools' schema fingerprints equal `RobinhoodOrderContract::SCHEMA_FINGERPRINT`. A test proves the pinned fingerprints match the captured real schemas, stored structurally in `tests/Fixtures/Mcp/robinhood-write-tool-schemas.json` (no descriptions or identifiers).
- **Arguments:** they come only from the immutable intent: account number from the binding, symbol, lower-case side, type, exact quantity, `gfd`, `regular_hours`, `limit_price` from the bound for limits, and `ref_id` set to the intent's stable idempotency key on place only. Fractional limits, other sessions, `gtc` and unknown sides are refused before sending.
- **Review:** the response must echo the symbol and side and carry `order_checks`.
- **Place:** `data.order` with an ID, the same symbol, side and quantity, and a matching or absent `ref_id` becomes `order`. A complete `data.approval` becomes `approval`. Anything else, or any transport or tool error, is `ExecutionFailure` with `maybeSent=true`. There are no retries.

**`ExecutionDispatcher::dispatch`**:
1. **Claim** with every submission check.
2. **Review:** non-empty `order_checks` withdraws (`withdrawn_broker_order_checks`). A `customer_approval_required` that differs from the policy withdraws (`withdrawn_broker_approval_mode_differs`).
3. **`beforeSend`:** writes disabled, emergency disable, a passed deadline or a changed authorization withdraws.
4. **One place request.**
5. **Record the result:** `accepted()` for an order, `approvalPending()` for an approval.

Failure handling:
- **Before the place request** (session, catalog or schema failure, review failure): the claim is withdrawn with the reason, since nothing was sent (new `ExecutionJournal::withdraw`).
- **During or after it:** `unknown`, later proven by `ref_id` reconciliation or resolved by the owner.
- **Repeat dispatch:** a dispatched intent can never be claimed again.

**`php artisan mcp:live-order --account=<id> --signal=<id> [--stage-only]`.** The owner runs it, one recorded signal at a time:
- It plans from stored broker-confirmed facts and stages the immutable intent, with the deadline inside the signal's five-minute window.
- Unless `--stage-only`, it dispatches.
- It prints a JSON outcome or the denial reason.
- Nothing schedules it.

## Verification

`composer validate` passed; Pint applied; no migration or frontend change in this increment.

- `RobinhoodExecutionGatewayTest`, **9 tests** (HTTP-level, stray requests refused):
  - pinned fingerprints;
  - nothing sent with writes disabled;
  - exact review/place arguments with `ref_id` on place only;
  - limit price and unsupported-terms refusal;
  - approval parsing;
  - changed-schema refusal before any call;
  - 503, unrecognized, differing-order and foreign-`ref_id` responses are unknown after one request;
  - no calls outside the verified session;
  - read-session allowlist.
- `LiveExecutionDispatchTest`, **7 PostgreSQL tests**:
  - order and approval journaling, with no resend;
  - an approval blocks the account;
  - review alerts and approval-mode mismatch withdraw without placing;
  - emergency disable during review withdraws before placing;
  - a pre-send failure withdraws;
  - a post-send failure becomes unknown, then is proven by `ref_id` from the next order-history read without resending;
  - writes disabled or a failing check opens no write session;
  - the operator command's stage-only path, dispatch path and denial reporting.

| Run | Result |
|---|---|
| Full SQLite | **714 passed / 69 skips, 4,931 assertions** (783 total; PostgreSQL-only tests skip) |
| Full PostgreSQL | **782 passed / one existing environment skip, 5,527 assertions** (783 total) |

The test database had no fixture connections left afterwards.

## Before a supervised pilot (owner actions, not authorized here)

1. **Approval setting.** Make the broker report the approval mode you want (recommended: on). Confirm with a sync.
2. **Deploy.** Commit and deploy this revision. It has no migration.
3. **Live policy.** In the Control Center, apply a small live policy: sleeve, maximum order value, risk, `require_broker_approval`.
4. **Supervised session.** With the owner present during regular hours:
   - set `BROKERAGE_WRITES_ENABLED=true`, rebuild config, and confirm old automated executors are paused;
   - run `mcp:live-order` for one fresh signal;
   - approve or decline in the Robinhood app;
   - watch reconciliation in the Control Center;
   - set the flag back to false afterwards.
5. **Record the first observations.** Note the approval state spelling and whether the approved order carries the app's `ref_id`.

Still open:
- live exits from app-attributed holdings;
- account eligibility at submission;
- broker cancel and approval decline tools (deliberately not on the allowlist);
- remaining fault tests (token-refresh race, a backup restored with in-flight intents).
