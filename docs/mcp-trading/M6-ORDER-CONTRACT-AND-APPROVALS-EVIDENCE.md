# M6 order-tool contract review, ref_id proof and broker approvals

October 25, 2026 Central, evening. After deploying `c1e1bd2` with migrations `000030`/`000031`, the owner asked to proceed with trading readiness. This increment reviews the broker's order and approval tools and makes the journal handle what they do. Source only: no order, review, cancel or approval-decision tool was called, and no write gateway exists. Live writes stay disabled.

## Deployment verification (read-only, before this increment)

Runtime `c1e1bd2` checks:
- `mcp:doctor` passed against `postgres` / `mcp_trading`;
- `migrate.php --check` reported nothing pending;
- `/ready` returned 200;
- `mcp_trading_runtime` has DML grants on all nine execution tables, which are empty;
- `BROKERAGE_WRITES_ENABLED` is false and external-order detection is on;
- the first post-deploy monitoring run (02:00Z) completed, including the order-history read that now runs reconciliation.

## Contract evidence

**Stored catalog.** The runtime connection's encrypted capability manifest (91 tools, checked 02:00:15Z, status `connected`) supplied the structural schemas: argument names, types, required fields and response shapes, with no prose.

**Description-level `tools/list`.** One read through the app-owned connection, run like October 19's: an **empty** `tools/call` allowlist, so no tool could be invoked, and only eight order, approval and account tools kept.
- The `place_equity_order` and `review_equity_order` descriptions are **byte-identical** to the October 19 review (SHA-256 match).
- The saved descriptions are private, kept in the session scratch area and not committed.
- The descriptions are broker text and were treated as data.

Reviewed facts now recorded in `RobinhoodOrderContract`:

- **Placement arguments:** `place_equity_order` takes `account_number`, `symbol`, `side` (`buy`/`sell`), `type` (`market`, `limit`, `stop_market`, `stop_limit`), exactly one of `quantity` or `dollar_amount`, `limit_price`, `stop_price`, `time_in_force` (`gfd`/`gtc`), `market_hours` (`regular_hours`, `extended_hours`, `all_day_hours`), `tax_lots` and `ref_id`.
  - Fractional quantities are allowed only on regular-hours market orders.
  - Schema fingerprints of the four write tools are pinned so a future gateway can refuse a changed schema.
- **Idempotency:** "pass a fresh UUID as `ref_id` … re-send the SAME `ref_id` on retries … the upstream deduplicates by `ref_id`." Orders report `ref_id` back.
- **Agent identity:** approved orders read `placed_agent='user'`, and `agent_initiated` "does not identify which agent". `ref_id` is therefore the only evidence of which agent placed an order.
- **Approval:** with customer approval required, the call "does not place the order: it goes to the customer to approve or decline in the Robinhood app".
  - The response holds `data.approval` (`approval_id`, `state`, `expires_at`, `is_edited`, `proposed_order`, `actual_order`) instead of `data.order`.
  - The customer can edit before approving, and pending approvals expire on their own.
  - `get_trade_approval_setting` reports `human_must_approve_trades`; only the customer can change it.
  - Approval state *spellings* are not observed yet; asset classes use enum spellings such as `ASSET_CLASS_EQUITY`. No code branches on approval state text.
- **Order states:** `new`, `queued`, `confirmed`, `unconfirmed`, `partially_filled`, `filled`, `cancelled`, `rejected`, `failed`, `voided`.
- **Pre-trade review:** `review_equity_order` returns `order_checks` alerts and `customer_approval_required`. `cancel_equity_order` returns only `accepted`.

## Delivered behavior

- **Documented order states.** `new` (open) and `voided` (terminal) are now known to both execution reconciliation and monitoring order-state classification. Before, they would have raised a reconciliation mismatch or the `unknown_order_state` pause reason.
- **`ref_id` proof.** Before external-order classification, `BrokerOutcomes::bind` matches any order whose `ref_id` equals an unresolved intent's idempotency key (`submitting`, `unknown` or `approval_pending`), case-insensitively.
  - **Match:** the order must have the same symbol, side and exact quantity, a creation time no earlier than a minute before the claim, and an unbound broker ID. It is then bound as `ref_id_match` and moves to `accepted`. A lost response therefore needs no owner link, and the order never latches the external-order incident.
  - **Differs:** a `ref_id` match that fails any check stays external and raises the blocking reconciliation incident. An approval the owner edited is the owner's order.
  - **Same order acknowledged:** acknowledgement of the already ref-bound order stays idempotent.
- **`approval_pending` state.** `approvalPending()` records the broker's approval ID (hashed and encrypted), its initial state text verbatim, `is_edited` and `expires_at`. It moves `submitting` → `approval_pending`, which blocks every other claim on the account.
  - The approved order resolves it automatically through `ref_id`. Without that proof, the owner links the order or confirms absence.
  - Absence requires a complete order-history read at least two minutes after the later of the submission deadline and the approval's expiry, with no matching or same-`ref_id` order.
  - An `approval_pending` intent missing from history is not an incident.
- **Broker approval setting.** A new reviewed read-only operation, `trade_approval_setting` (`get_trade_approval_setting`, schema fingerprint pinned), is read with each monitoring snapshot when `ROBINHOOD_MCP_APPROVAL_SETTING_ENABLED` is true (default false).
  - **Validation:** the read must name the bound account and carry a boolean.
  - **Failure handling:** a failure is recorded and never fails monitoring.
  - **Flag off:** the tool is excluded from the call allowlist and schema-change detection, so a change to it cannot stop monitoring.
- **Live policy v2.** `live_execution_policy_v2` adds the required `require_broker_approval`. Every claim, entry or exit, is denied:
  - with `broker_approval_setting_unverified` unless the snapshot carries a complete, trusted, fresh setting for the bound account;
  - with `broker_approval_setting_differs` when the setting differs from the policy.

  The Control Center form makes this an explicit choice, recommending approval on for a pilot.
- **Control Center.** Approval-pending intents show the approval expiry and the link/absent forms. Approval IDs are not displayed.

## Persistence and migration

Additive migration `2026_10_26_000032_add_mcp_execution_approvals_and_ref_binding.php`:
- creates the immutable `mcp_execution_approvals` table;
- widens the PostgreSQL dispatch/event state constraints and transition function with `approval_pending`;
- adds `ref_id_match` to the binding source constraint.

It has no backfill. Its `down()` restores the 000031 rules and intentionally fails if the new states exist.

The status was first spelled `awaiting_approval`. That is longer than the existing `varchar(16)` columns, so it was renamed rather than widening columns. In the guarded test database only, the one early version of `000032` was rolled back and reapplied. The fixture cleanup missed the new table, which left 22 test connections behind; they were removed with the guarded helper after the cleanup was fixed. Not applied to the operator database.

## Verification

`composer validate` passed; Pint applied to the changed PHP; no frontend asset change.

| Run | Result |
|---|---|
| Full SQLite | **705 passed / 62 skips, 4,904 assertions** (767 total; the six new boundary tests are PostgreSQL-only) |
| Full PostgreSQL | **766 passed / one existing environment skip, 5,459 assertions** (767 total) |

The test database had no fixture connections left afterwards.

New coverage:
- **Approval-setting gate:** setting differs, including for exits; read failure (monitoring still completes); flag off; a policy expecting approvals off.
- **Setting reads:** wrong-account or non-boolean reads are malformed.
- **`ref_id` resolution:** a lost response resolved by `ref_id` (upper-case echo) while another agent's look-alike order stays external; a `ref_id` binding racing the acknowledgement.
- **Approval paths:**
  - an edited-quantity approval stays external and blocks;
  - approval pending blocks the account until the approved `new` order is proven, then fills;
  - absence is refused before the approval expiry and allowed after it.
- **New states:** `new` → `voided` reconciles without incidents.
- **Raw-SQL refusals and rendering:** raw-SQL shortcut refusal; approval-pending rendering.

## Readiness gates still open

1. **Deploy and enable.** Deploy this revision (migration `000032`), then set `ROBINHOOD_MCP_APPROVAL_SETTING_ENABLED=true`, which adds one read-only call per monitoring run. Then confirm the stored setting matches what you see in the Robinhood app.
2. **Execution gateway and dispatcher.** A separate write session whose allowlist is only `review_equity_order`/`place_equity_order` (plus `cancel_equity_order` later). It would:
   - check the pinned schema fingerprints;
   - review first, refusing any non-empty `order_checks`;
   - place with `ref_id` set to the intent key, re-sending the same `ref_id` only on transient transport failure;
   - map `data.order` to `accepted()` and `data.approval` to `approvalPending()`, and anything else to `uncertain()`;
   - run `beforeSend()` immediately before the request.

   It would be tested with a fake MCP server only; no live write is authorized by this document.
3. **First approval observation.** Observe a real approval's state spelling and confirm that an approved order carries the app's `ref_id` during the first supervised order. Until then, approved orders that lack the `ref_id` need an owner link.
4. **Live exits** from app-attributed broker holdings rather than paper exits.
5. **Account eligibility** (`agentic_allowed`, not deactivated) checked at submission.
6. **Pilot.** Explicit owner activation and a supervised small pilot with approval mode on.
