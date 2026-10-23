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

## Owner-authorized deployment — October 23

Implementation commit `d5cb58b7a58029575405f528372b9eb2c37cc344` was isolated from the pending crypto work. A clean archive of that exact commit passed Composer validation and both full suites: SQLite **688 tests / 4,441 assertions, 648 passed / 40 skips**; PostgreSQL **688 tests / 5,014 assertions, all passed**. These clean-release totals differ from the earlier working-tree totals because unrelated crypto source/tests were excluded.

The normal deployment script deployed this explicit revision to the local runtime. The first attempt stopped at `npm: command not found`, before config refresh/service restart; rerunning with the installed Herd Node 24 path completed normally. No migration was requested, and the migration checker reported `pending: []`.

Verification at 15:08:49 UTC (10:08:49 Central): runtime checkout clean at `d5cb58b`; deployed collector SHA-256 matches source (`0f7339f3b551769b9899eff03a6210f954e2e63d068d9573ae92570fc6736522`); doctor verifies database `postgres`, schema `mcp_trading`, runtime role and owner. `/ready` returned 200, unauthenticated `/mcp/paper` returned 302, and `/.env` returned 403. The private runtime `.env` hash is unchanged. Agentic, Coinbase and Robinhood Crypto writes are all false; legacy schedules, AI decisions/research and crypto collection remain disabled. Existing forward-paper signals/handoff/fills, batch collection and warm-up remain enabled. No manual collection, signal evaluation or order was triggered for deployment testing.

Scheduled post-deployment evidence, read at 15:18:10 UTC: CRWD input **6538**, retrieved at **15:10:51 UTC**, records `quote_refresh.status=read_complete` and `book_quote_match.status=matched`, with bid size `"4"` and ask size `"60"`. The next input **6569**, retrieved at **15:15:21 UTC**, also completed its refresh but still had mismatched prices and correctly retained unknown sizes. Both cycles used existing scheduling; no manual evaluation was triggered. Their signals recorded `holding_exit_not_triggered` and handoffs `no_order`. This establishes one observed recovery and a subsequent safe refusal, not a sustained match-rate improvement; provider-cost/cadence measurement remains pending.
