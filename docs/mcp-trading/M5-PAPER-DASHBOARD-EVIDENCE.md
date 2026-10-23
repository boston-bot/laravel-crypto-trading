# M5 visual forward-paper dashboard

October 22, 2026. Source-only interface increment. No migration, deployment, runtime setting/policy change, provider/model request or forward-paper/brokerage mutation was performed. Existing warm-up failure and trading-readiness acceptance remain separate unfinished M5 work.

## Operator experience

A new **Paper Trading** navigation item opens `/mcp/paper`. Control Center and Performance also link to it. The page shows:

- Current simulated equity, available/reserved cash, realized trade P&L, marked unrealized P&L, recorded fees and dividend income/receivables.
- A native SVG chart of up to thirty recorded session-opening valuations, with an accessible exact-value table and explicit gaps.
- Session evaluation/signal/order counts and a bar chart of the most frequent waiting/unavailable reasons.
- Completed-window collection coverage, stored-quote admission and quote ages at evaluation from the existing cadence report.
- Open simulated positions, current fresh bid marks, basis/average cost, unrealized P&L and initial/recorded trailing-stop evidence.
- Paginated orders/executions with side, state, requested/filled quantity, weighted fill price, fees, acceptance/first/last fill times, bound/rationale and individual fills.
- Paginated recorded evaluations with expandable eligibility checks, plus current-universe listing/history/SEC/profile readiness.

The date filter selects activity and the chart endpoint. Symbol filters apply to the tables; state filters orders only. Current ledger totals remain current and account-wide regardless of the selected historical date. Order rows are selected by acceptance date in the New York calendar day. Current readiness is explicitly distinguished from recorded eligibility at evaluation. Times display in Central. Wide tables scroll within panels at phone widths; their pagination anchors account for the sticky header. Refresh is a normal read-only page reload, not automatic polling or a strategy trigger.

Historical experiments remain at `/mcp/experiments` with separate datasets and balances. This page does not combine them with forward paper or with broker-confirmed holdings. Synthetic/other simulation orders in the same virtual ledger are labeled separately from signal-linked forward-paper orders.

## Accounting and provenance

All monetary calculations and SVG geometry use exact decimals. Filled prices are weighted from actual recorded fills; an unfilled order has no execution price. Order price bounds and initial/trailing stops are not projected sale prices. Buy and sell orders have separate rows; the UI does not fabricate paired closed-trade episodes across splits, dividends or other ledger adjustments.

Current equity is cash plus validated remaining-position bid marks plus recorded unpaid dividend receivables. Reserved cash is already part of cash and is not added twice. Current marks require the existing scoped, current-authorization quote admission under the applied execution age limit, corporate-action clearance and an unblocked position. If any held position cannot be valued, aggregate equity and open marked P&L remain unknown; basis is never used as a substitute. Ledger fees are already included in cash/basis/realized amounts. Open marks exclude hypothetical terminal sale fees.

The chart uses only stored `SessionValuation` records for the current paper portfolio/authorization, the reviewed opening-valuation rule version, valid payload fingerprint, positive equity, and locally available cutoff/recording times. Missing valuations break the line; no initial cash point, closing value, intraday movement, return, drawdown or benchmark is invented. The chart does not write new valuations. Initial stops use the existing recorded-fill position facts; the displayed trailing stop must come from the same entry episode's recorded evaluation. Viewing the page never evaluates an exit rule or changes a stop.

The controller uses the existing financial-operator/authentication/privacy middleware, selects the signed-in user's connection, and scopes portfolio/history to its current binding. Signals and opening valuations use current authorization generation. PostgreSQL uses a repeatable-read, read-only transaction for a coherent ledger snapshot. Timestamp query bounds retain microseconds, so records at the exact current second are included without admitting future rows. Inputs, filter/page ranges and untrusted strings are validated/escaped. Sensitive responses retain private/no-store headers.

## Verification

- Focused feature/geometry suite: **10 tests / 101 assertions**. Additional PostgreSQL request test exercises the actual read-only transaction outside a fixture transaction.
- Final full PostgreSQL: **654 total, 653 passed, 4,606 assertions, 1 existing PHP-FPM environment skip**.
- Final full SQLite: **654 total, 617 passed, 4,261 assertions, 37 PostgreSQL-only skips**.
- Composer validation, changed-file Pint, Git whitespace checks and the Vite frontend build passed using existing lockfiles. No dependency upgrades.
- Synthetic built-asset Chrome review covered desktop and **390 × 844** phone viewport, including known/missing chart values and populated execution/position tables. The phone page width remained 390 px; wide tables scrolled internally. Browser fixtures were static exports from isolated fake-client tests, served on loopback only. No runtime financial data or real brokerage/provider client was used. Physical phone verification awaits deployment.

Coverage includes authentication/operator access, changed/absent binding, a second authorized operator's isolation, exact cash and weighted partial-fill prices, no GET-triggered collection/evaluation/ledger writes, stale/blocked position values, missing chart sessions, authorization-generation exclusions, untrusted rationale escaping, filter validation and independent paginated history. A genuine second-boundary timestamp omission in the new read queries was corrected with explicit microsecond cutoffs before final verification. Fixtures were corrected for the applied sixty-second test quote limit and SQLite's stored decimal precision; pure geometry and PostgreSQL checks retain exact decimal coverage.

## Impact and next work

No migrations, backfills, new flags, computation-source fingerprint changes, schedules, strategy rules, costs, order permissions or broker tools. New presentation classes sit outside the frozen experiment-processing source set. No frontend JavaScript or external chart dependency was needed.

Deploy through the existing procedure to make `/mcp/paper` available in the running app. The currently authorized home-LAN HTTP override is not changed by this source increment. An installer rerun remains a separate operation as documented in the operator guide.

The dashboard reports existing evidence and makes failures visible; it does not fix the unfinished background warm-up or establish profitability/live readiness. Next remains warm-up repair and controlled forward-paper acceptance, followed later by separately authorized M6 implementation.
