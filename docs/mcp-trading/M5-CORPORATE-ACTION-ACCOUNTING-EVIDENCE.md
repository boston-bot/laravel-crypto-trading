# M5 paper corporate-action accounting evidence

Source increment, October 18, 2026. This work is local, uncommitted and undeployed. M5 remains partial; M6 remains unstarted. Runtime AI, paper execution and live writes remain disabled.

## Implemented behavior

`PaperCorporateActions` consumes the newest immutable, account-scoped broker market input containing a fresh, complete Alpaca all-quality process-date query. It never accepts caller-supplied event JSON, collects data, calls a model or sends a broker mutation. The separate `MCP_PAPER_ACTIONS_ENABLED` gate defaults to false. `mcp:paper-actions --account=<local-connection-id> --input=<stored-input-id>` is an explicit operator-owned local reconciliation command; no schedule was added.

- Whole-share forward and reverse splits reconstruct holdings immediately before the New York ex-date boundary, adjust quantity and retain total cost basis, cash and realized trade PnL. Affected open orders release reservations; expired orders remain expired. Fractional results and cash-in-lieu require review.
- Ordinary domestic cash dividends capture immutable pre-ex-date holdings, including shares sold before payment. Ex-date and later purchases receive no entitlement. Gross income becomes paper cash once the payable date is reached. Taxes are not simulated. Dividend receivables count toward paper equity, never spendable cash, and advance the ledger version when first booked. Received income and receivables are displayed separately from trade PnL.
- Observations, entitlements, applications and review blocks have encrypted immutable payloads and stable provider-event/fingerprint/phase keys. Portfolio locking, transactional rollback and concurrent duplicate-worker tests establish exactly-once local application. Numeric formatting changes alone do not create a corrected event.
- Corrected/disappearing events, late splits after intervening trades or dependent adjustments, ambiguous same-day actions, unsupported types and unverifiable histories freeze the affected symbol and cancel its open paper orders. Existing fills and paid cash are retained for review. There is no automatic reversal, unblocking or historical rebuild workflow.
- Invalidated/expired paper orders release reservations before fresh corporate-action evidence is required. Repeated processing of an already captured dividend entitlement does not repeatedly cancel later orders.

## Bounded execution policy

`CorporateActionClearance` derives `alpaca_observed_since_paper_inception_v1` only when a fresh, fully paginated query covers paper inception through the observation cutoff and every due reported event has matching accounting evidence available by that cutoff. A future application cannot make an older observation ready. Corrected or missing previously observed IDs and review blocks prevent clearance. New collection extends its start date back to paper inception when needed.

This is a local observed-data policy, not provider proof that no delayed event exists. Provider publication time and raw provider `corporate_actions_clear` remain unknown; the derived decision explicitly preserves `publication_delay_possible=true`. All-quality early records may change and trigger review. The adapter's 366-day per-query maximum and page/event bounds still apply: portfolios beyond that history require a future chunked backfill and remain blocked meanwhile. Source freshness, classification, leverage/inverse and the remaining execution checks are independent requirements. No actual symbol became ready through this change.

## Verification and database impact

One additive migration, `2026_10_18_000015_create_mcp_paper_corporate_actions.php`, creates `mcp_paper_action_records` in the configured schema with foreign keys, a unique application identity and PostgreSQL phase/status constraints. It was exercised only in the guarded dedicated test database. No runtime migration, deployment, data backfill or configuration change occurred. Deployment must apply this migration before using the new code. A populated runtime backup/restore containing these new records remains untested.

Tests cover entitlement across sale/payment, split basis/cash preservation, order reservation release, ex-date exclusion, late/corrected/missing events, fractional splits, special dividends, ledger/equity behavior, unavailable data, immutable/encrypted audit, point-in-time clearance, failure rollback/retry and actual PostgreSQL duplicate-worker races. External HTTP/MCP clients are faked; no live brokerage mutation is a test.

Final full SQLite: **350 passed / 2,476 assertions, 25 PostgreSQL-only skips**. PostgreSQL: **374 passed / 2,686 assertions, 1 existing PHP-FPM environment skip**. Final focused PostgreSQL action/fill/concurrency: **33 tests / 206 assertions**. No browser visual review was performed this increment. Composer validation, changed-file Pint, frontend build and diff whitespace checks passed. No credentials or private owner holdings are test fixtures.

## Remaining M5 acceptance

Authoritative leveraged/inverse classification is still unavailable through the configured contracts. Margin settings, product names and absent attributes are not proof. Complete corporate-action accounting remains unfinished: mergers, spinoffs, stock dividends, special/foreign dividends, due bills, fractional cash-in-lieu, corrected-event review/rebuild and longer-history backfills require additional work. Conservative review blocks cover these cases; this increment does not satisfy the comprehensive corporate-action acceptance item. Verified session baselines, full performance evaluation and controlled paper activation also remain separate work.

Ordinary dividend entitlement follows the [SEC investor explanation of ex-dividend dates](https://www.investor.gov/introduction-investing/investing-basics/glossary/ex-dividend-dates-when-are-you-entitled-stock-and). Event fields and delayed/all-quality query semantics follow the [Alpaca API](https://docs.alpaca.markets/us/reference/corporateactions-1) and [official event models](https://github.com/alpacahq/alpaca-py/blob/master/alpaca/data/models/corporate_actions.py). These sources do not establish current classification or universal absence of events.
