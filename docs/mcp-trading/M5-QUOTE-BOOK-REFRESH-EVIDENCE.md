# Bounded quote refresh after book mismatch — October 23, 2026

Milestone: M5 forward-paper collection; source increment, not deployed.

## Observed problem

Read-only runtime inspection of CRWD found buy signal 1553 at 14:16:26 UTC with handoff `unavailable / liquidity_coverage_unknown`. Stored input 6215 had quote bid/ask `247.00 / 247.31` at 14:16:13 UTC and a separately observed book `247.04 / 247.31` at 14:16:19 UTC, with 20 bid shares and 40 ask shares. The exact-price matcher correctly left sizes unknown. This is evidence of mismatched observations, not evidence that the stock has no liquidity.

## Change

`PaperMarketInputs` now makes at most one additional exact-symbol quote read when the reviewed book/quote matcher reports a price/quantity mismatch. It runs within the existing shared broker session and checks the existing deadline/rate-limit callback before dispatch. It records the initial quote and refresh status/error, and a valid refreshed observation replaces the quote with its own component/retrieval times and updated source time.

The unchanged matcher must still accept exact bid and ask equality, fresh independent timestamps, reviewed share-unit semantics, correct identity and complete evidence. Continued movement, stale/future data, malformed/incomplete responses or provider failure leaves sizes unknown. Failed refreshes preserve the original quote. No book-price substitution, tolerance, retry loop, model call, policy change or order is added. Already-matching and untrusted-book observations require no additional read. A changing market can still fail to match; no improvement in live fill/readiness rates is claimed.

## Verification

Tests cover successful recovery in one session, original-observation retention, idempotent repeated collection, persistent mismatch, stale/future component times, wrong symbol, incomplete response, rate limit, untrusted semantics, and a batch deadline preventing refresh. All automated external reads use fakes; no broker mutation is used.

Focused SQLite: 34 tests / 250 assertions, all passed. Complete SQLite: 714 tests / 4,630 assertions, 673 passed and 41 skips. Complete dedicated PostgreSQL: 714 tests / 5,008 assertions, 713 passed and one existing environment skip. Composer validation, scoped Pint and diff whitespace checks passed. The initial stale-response test used a 21-second age against a fixture with a longer configured maximum; it was corrected to five minutes, then the focused and full suites passed. No unresolved test failures. No frontend build was needed because assets did not change.

## Operational impact and remaining work

No migration, backfill, dependency or frontend change. Existing unrelated crypto source work is preserved. No runtime deployment, runtime configuration change, paper ledger write or live order is part of this increment. Deployment must use a reviewed release without accidentally including pending crypto migration/policy changes. After deployment, inspect stored `quote_refresh` and `book_quote_match` outcomes during enabled paper cycles and measure extra read cost and match/freshness rates. A failed match continues to block the order.

M5 remains partial; next acceptance work is measured forward-paper readiness/cadence. M6 live execution remains unstarted and unauthorized.
