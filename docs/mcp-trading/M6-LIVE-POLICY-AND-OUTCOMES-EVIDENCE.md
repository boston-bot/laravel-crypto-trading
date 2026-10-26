# M6 live policy, submission checks and broker-confirmed outcomes

October 25, 2026 Central. The owner asked to continue M6 with the live policy and submission checks, then extend the journal to record broker-confirmed outcomes, keeping live writes disabled and testing with fakes and PostgreSQL. Source only. No live policy was applied to any real account, no runtime migration or deployment, no flag change, and no broker request. M6 remains partial; M5 acceptance is unchanged. This builds on [the journal foundation](M6-EXECUTION-JOURNAL-EVIDENCE.md).

## Delivered behavior

### Live policy (`LivePolicies`)

An owner-applied, versioned live policy per broker account (shared execution-account scope). All twelve settings must be supplied; there are no defaults:

| Setting | Code bound |
|---|---|
| `sleeve_capital`, `max_order_notional` | Quoted dollars, positive; order notional ≤ sleeve |
| `risk_per_trade_pct` | (0, 1] |
| `max_position_pct` | (0, 20], the owner's ~20% maximum position |
| `daily_loss_pause_pct` | (0, 10] |
| `max_open_positions` | 1–8 |
| `max_orders_per_session` | 1–20 |
| `order_type`, `allow_fractional` | market or limit; fractional only with market (Robinhood contract) |
| `slippage_bps`, `limit_offset_bps` | 0–100; market orders need ≥ 5 bps slippage |
| `quote_max_age_seconds` | 1–60 |

The reviewed cost schedule is attached. Only an operator on the connected agentic account can apply a version; request IDs are idempotent and a stale base version is refused. Rows are immutable and fingerprinted (`live_execution_policy_v1`, scope, version, payload). An intent's `policy_fingerprint` must equal the current version's fingerprint at claim, so any policy change invalidates every staged intent. Applying a policy enables nothing: `BROKERAGE_WRITES_ENABLED` is still required and still false.

### Owner controls (`ExecutionControls`)

Append-only buy pause and emergency disable per broker account. Engaging either works even after the authorization has changed; releasing requires the current connected authorization. Emergency disable denies every claim, buys and sells. A buy pause denies entries only.

### Submission checks

`SubmissionChecks::reason()` replaces the always-deny stub. It runs inside `claim()` under the connection and execution-account locks. `plan()` proposes the largest admissible draft from the same facts and math, so the planner and the submission recheck cannot diverge. Paper cash, paper positions and model text are never inputs. In order, a claim is denied for:

1. **Account and policy state:** an open execution reconciliation incident, emergency disable, no live policy, a changed policy fingerprint, or an expired or unreviewed cost schedule.
2. **Signal and session:** invalid signal provenance; outside a verified regular session or within five minutes of the close.
3. **Broker and quote freshness:** no fresh (≤ 5 min), complete broker snapshot for the current authorization with equity, buying power and exposure. A stale, unmatched or untradable stored quote is refused, reusing the paper quote, book and tradability validation through the new ungated `BrokerSimulationQuote::observe`.
4. **Sells:** only the minimum of broker-held quantity and the app's net broker-confirmed fills for that symbol may be sold. Exits ignore buy pauses, watchlists and entry incidents. Owner and other-agent holdings are never sold.
5. **Buys, gates:** buy pause; external-order detection disabled; an external-order incident or unverified order history; the per-session claim cap.
6. **Buys, strategy evidence:** a symbol not eligible under the applied eligibility rules (the same engine, including fresh watchlist nomination); a changed or blocked profile; an earnings blackout; an entry trigger that no longer holds; an ask above the profile's maximum entry; a symbol the broker already holds.
7. **Buys, account risk:** the daily loss pause or maximum open positions. These are measured against a broker-confirmed opening baseline: the first complete snapshot within 30 minutes of the open, and a missing baseline is unknown, not zero.
8. **Buys, sizing:** the shared `RiskSizer`. Inputs are the lower of sleeve capital and broker equity, broker buying power, the sleeve capacity remaining after all broker-held exposure in the dedicated account, and the max order notional.

The intent must then match the plan exactly on order type and price bound. Its quantity may not exceed the plan, and it may not carry fractional quantities the current tradability or policy disallows. Limit bounds round to cents and market collars to six decimals, always in the tightening direction.

### Journal extensions and broker-confirmed outcomes

The lifecycle now continues past the claim:

```text
staged -> submitting -> withdrawn                     (pre-send recheck; nothing sent)
staged -> submitting -> accepted                      (broker acknowledged, order ID bound)
staged -> submitting -> unknown -> accepted | absent  (owner resolution only)
accepted -> partially_filled -> filled | broker_cancelled
accepted -> filled | broker_cancelled | rejected
```

The journal operations behind these transitions:

- **Pre-send recheck:** `beforeSend()` is the claim owner's last local check before a future network request. Writes disabled, emergency disable, a passed deadline or a changed authorization withdraw the claim.
- **Acceptance:** `accepted()` binds the broker order ID (SHA-256 hash plus encrypted ID) from the submission response. It is idempotent, and a different ID is refused.
- **Owner link:** `link()` lets the owner resolve `unknown` to an observed order matching symbol, side and quantity created after the claim. It reviews that external order in the same authenticated action.
- **Owner-confirmed absence:** `absent()` requires a fresh, complete order-history read taken at least two minutes after the submission deadline, with no candidate order and no unreviewed external candidate.
- **Account block:** `submitting`, `unknown`, `accepted` and `partially_filled` all block further claims on the account, giving one live order per account.

`BrokerOutcomes` reconciles each stored order-history read inside the existing monitor transaction:

- **Outcome rows:** only broker-ID-bound orders are reconciled. Each changed observation appends an immutable outcome row recording state, cumulative quantity, average price, fees, source and retrieval time.
- **Fills:** each broker execution ID is stored once with exact decimals in a fill journal separate from paper fills.
- **Contradictions:** any of the following is left unchanged and raises a critical `execution_reconciliation_mismatch` incident:
  - a different symbol, side or quantity;
  - an unknown state;
  - a decreasing fill;
  - fills without execution records;
  - execution totals not matching the cumulative quantity;
  - a changed execution;
  - a terminal regression.
- **Missing orders:** a complete history that omits an open app order raises `execution_order_missing_from_history`.
- **Blocking and review:** both incidents deny every live claim until the owner reviews them, and a contradiction that persists reopens on the next read.

External-order detection now excludes only broker IDs bound by this app's acceptance or an owner link (`app_bound_broker_ids_excluded_v1`); every other order still latches the review incident. The order normalizer retains `ref_id`, `created_at`, `last_transaction_at`, `market_hours` and strictly validated `executions`.

## Persistence and migration

Additive migration `2026_10_26_000031_add_mcp_live_policy_and_broker_outcomes.php` creates `mcp_execution_policies`, `mcp_execution_controls`, `mcp_execution_broker_orders`, `mcp_execution_outcomes` and `mcp_execution_fills`.

On PostgreSQL it also:

- adds source, bounds and version constraints;
- installs immutability triggers on all five tables, reusing `"mcp_trading".execution_history_immutable()`;
- replaces the dispatch/event state constraints and `execution_dispatch_transition()` with the extended graph, which keeps claim tokens fixed after a claim and refuses shortcuts and regressions.

The migration has no backfill and does not rewrite existing journal rows. Its `down()` restores the narrower 000030 lifecycle and intentionally fails if extended states exist. It was applied only to guarded test database `mcp_trading_test`, schema `mcp_trading`. It is not applied to the operator database. Deployment must follow the backup/restore and owner-role migration procedure.

`ExecutionJournalFixture::cleanup` now covers the new tables and external-order reviews. One fixture connection left by an earlier failing test run in `mcp_trading_test` was removed with that guarded helper.

## Verification

Installed versions are unchanged: Laravel 12.70.0, PHPUnit 11.5.55, PHP 8.5.12. `composer validate` passed; Pint was applied to the changed PHP files. No frontend assets changed.

- **Focused SQLite:** `LiveSubmissionChecksTest`, **8 tests / 77 assertions**, passed.
- **Focused PostgreSQL:** live, boundary and journal tests, **29 tests / 260 assertions**, passed.
- **Full SQLite:** **698 passed / 56 skips, 4,826 assertions** (754 total).
- **Full PostgreSQL:** **753 passed / one existing environment skip, 5,346 assertions** (754 total).

Coverage includes:

- **Policy application:** explicit, operator-scoped, bounded, idempotent, versioned and immutable.
- **Owner controls:** engage after authorization loss; release only with the current authorization.
- **Policy-change invalidation** of staged intents.
- **Tampering:** tampered quantity, fractional quantity and price collar.
- **Sells:** unattributed sell refusal, and broker-held/attributed sell sizing.
- **Freshness:** stale quote and stale snapshot.
- **Daily loss:** pause from the broker-confirmed baseline, and a missing opening baseline.
- **Session:** last five minutes of the session, and the per-session claim cap.
- **Buy gates:** external-order incident and detection disabled; a manual buy consuming broker buying power.

Mandatory fault tests now exercised at the journal boundary with fakes:

- **Emergency disable during queued work:** withdrawal before send, and queued intents denied.
- **Writes disabled after a claim:** withdrawal.
- **Response lost after acceptance:** `unknown`, then the external incident, then an owner link to the matching order (not an unrelated one), then reconciled fills.
- **Crash with no order:** `absent` only from complete history after the deadline.
- **Duplicate delivery:** sequential, and three concurrent worker processes producing one outcome and one fill.
- **Partial fill then cancellation.**
- **Rejection.**
- **Contradictions:** quantity, terminal regression and unknown state, each raising a blocking incident that reopens after review if it persists.
- **History omissions:** a complete history missing an open order.
- **Raw-SQL refusals:** outcome/fill/binding/policy edits, negative fills, and lifecycle shortcuts and regressions.

These are local fakes, not broker-level evidence. No broker write tool exists or was called.

## Control Center owner forms (follow-up increment)

October 25, after the owner committed the service increment and asked to proceed. A "Live execution boundary" panel on the Control Center shows:

- whether brokerage writes are enabled (the panel states that no placement gateway exists);
- the applied live policy version, time and fingerprint prefix;
- buy pause and emergency disable state, and the external-order entry gate;
- the ten most recent live intents with their journal status and latest broker-confirmed observation;
- broker-confirmed fills, labelled separately from paper.

Five routes, each authenticated, `operate-finances`, operator-only, account-scope bound, CSRF-protected and throttled at 10/min. Every route calls the existing service, which rechecks identity and authorization under its locks:

| Route | Action |
|---|---|
| `POST /mcp/control/live/policy` | Apply a live policy version. All fields; an unchecked fractional box is an explicit `false`; unexpected fields are refused. |
| `POST /mcp/control/live/controls` | Record buy pause and emergency disable with a reason. A one-click **Engage emergency disable** button keeps the current buy pause. |
| `POST /mcp/control/live/intents/{intent}/link` | Link an unknown outcome to an observed same-symbol, same-side broker order not already bound. |
| `POST /mcp/control/live/intents/{intent}/absent` | Confirm an unknown outcome created no order (complete post-deadline history required). |
| `POST /mcp/control/live/reconciliation/review` | Review an execution reconciliation incident. |

The console form audit (`ConsoleInterfaceTest`) now lists these five routes as reviewed, and requires the controls and policy forms to be reachable. A `.btn-danger` style using existing tokens was added; `npm run build` passed. None of these actions enables writes or sends an order.

Tests: `LiveExecutionControlsTest`, **4 tests / 52 assertions**, covers:

- guest redirect, non-operator 403 and wrong account scope 409;
- missing confirmation, out-of-bound values and unexpected fields (422);
- CSRF 419 outside the testing environment;
- idempotent request IDs and explicit-false fractional settings;
- append-only controls and the engaged-state display;
- linking the matching order but refusing a wrong-side order, and owner review attribution;
- absence refused without post-deadline history;
- reconciliation review resolution.

Full SQLite **702 passed / 56 skips, 4,886 assertions** (758 total). Full PostgreSQL **757 passed / one existing environment skip, 5,406 assertions** (758 total).

The first full run exposed two test gaps, both fixed:
- the console form audit correctly rejected the new routes until they were reviewed and added;
- on PostgreSQL, the transition trigger correctly refused the test's simulated `staged`→`unknown` shortcut; the simulation now passes through `submitting`.

## Limitations and remaining work before any live pilot

1. **Execution gateway and dispatcher.** No broker write gateway, dispatcher, command or scheduler entry exists. Owner forms now exist for policy, controls, unknown-outcome resolution and reconciliation review (see above).
2. **Broker contract review.** The October 19 read-only metadata review recorded that `ref_id` is the broker's idempotency key and that `place_equity_order` can route an order to the customer for approval in the Robinhood app. The full input/response schemas exist only in the runtime connection's encrypted capability manifest; nothing in the repository has them. Placement code must not be written against guessed argument names. The next review needs owner approval to read that stored manifest (local and read-only) or a new read-only `tools/list`.
3. **Live exits.** Live sell signals still come from the paper exit engine. Generating exits from live, attributed holdings without a second strategy engine is open.
4. **Account trading eligibility.** It is checked only at binding time. A fresh stored accounts read (`agentic_allowed`, not deactivated) at submission is not implemented.
5. **Sleeve accounting.** Every broker holding in the dedicated account consumes sleeve capacity. Realized P&L and confirmed-performance reporting, kept separate from paper, are not built.
6. **Remaining fault tests:** refresh-token race, a missing order-history page at the broker level, restored backups containing `submitting` or `accepted` intents, and approval expiry once approvals exist.
7. **Deployment and activation.** A reviewed deployment and migration, explicit owner live settings and activation, verification that old automated executors are paused, and only then a supervised small pilot with broker-confirmed results.
