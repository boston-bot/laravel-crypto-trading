# M5 batch evaluation and intraday symbol warm-up

October 22, 2026. Source increment requested after the first forward-paper cycles exposed stale quotes at evaluation and missing reference evidence for midday additions. No migration, deployment, runtime flag/policy change, real collection, strategy-cycle invocation or brokerage mutation was performed by this implementation. A separate one-time read-only cadence capture was installed for the requested post-close baseline.

## Batch evaluation

With batch collection and all three forward-paper gates enabled, `mcp:signal-paper-cycle --once` owns collection. The standalone scheduled market collector is omitted in this mode, preventing a full collection pass before evaluation. The read-only `mcp:market-cycle` command remains available and does not create signals or orders.

`PaperMarketInputs::collectMany` delivers each completed batch's persisted observations to a callback before requesting the next batch. It retains one MCP session, existing batch sizes, provider backoff and the monotonic collection budget. An interrupted partial batch delivers only rows actually recorded; unattempted symbols receive no invented quote. A retry can deliver immutable existing rows, which still face the unchanged freshness checks.

`SignalPaperCycle` captures opening evidence, processes old paper orders and evaluates the delivered symbols through the existing `StrategySignals` and handoff services. Obsolete-order validation also runs before collection, including outside regular sessions. Existing guarded market-order fills and later-observation limit fills retain their semantics. No brokerage submission gateway is added.

A cycle remains the immutable five-minute grouping. Its root cutoff describes its first batch and its payload labels incremental evaluation; each signal carries its own actual evaluation cutoff, current policy/ledger/watchlist/profile/input evidence and fingerprint. The connection/portfolio locks and unique cycle/symbol identity prevent overlapping workers or retries from changing the first recorded outcome. Later batches append missing symbol outcomes under the same cycle. Removed unheld symbols are filtered before evaluation; held symbols remain eligible for exit evaluation independently of nominations. Ordinary whole-universe record calls retain their existing idempotency.

This removes delay from later batches, not source/provider lag or time spent enriching the current batch. Quotes that still exceed the applied age limit remain unavailable. No age limit, risk size, entry rule or watchlist permission was relaxed.

## Intraday warm-up

`IntradaySymbolWarmup` and `mcp:symbol-warmup --once [--account=1]` repair missing or stale listing/history/benchmark/classification evidence and rebuild missing or superseded daily profiles during a known regular session. The universe comes from the existing scoped watchlists and holdings, including data-only lists. Profiles use completed daily bars and stored earnings evidence; data-only membership never nominates an entry.

The new `MCP_INTRADAY_SYMBOL_WARMUP_ENABLED` gate defaults off. When enabled, a background scheduler task runs every five minutes independently of the quote loop. Existing provider/profile/collection gates must also permit work. Each invocation attempts at most three symbols with a 120-second monotonic budget checked between stages; an in-flight provider operation can overrun it. A ten-minute shared lock prevents overlapping warm-ups and a binding/authorization-scoped ten-minute cache throttle prevents repeated failures from flooding providers. Large backlogs take multiple cycles.

Fresh successful evidence is reused. In particular, a valid benchmark is not downloaded again for each new symbol, preventing unnecessary invalidation of existing profiles. Missing/stale history or a window that misses the latest stored completed session causes repair. Unsupported/inactive listings, incomplete SEC evidence, absent earnings or other failed eligibility checks remain blocked; the task does not grant a pass. Adapter results remain immutable records and unexpected exceptions are reported while isolating individual symbols. The returned `profiles` count includes unavailable profiles and is not a readiness count.

## Verification

Installed lockfiles remain unchanged: PHP 8.5.12, Laravel 12.70.0, brick/math 0.14.8 and PHPUnit 11.5.55.

- Full PostgreSQL: **643 total, 642 passed, 4,497 assertions, 1 existing PHP-FPM environment skip** in the guarded test database.
- Full SQLite: **643 total, 607 passed, 4,160 assertions, 36 PostgreSQL-only skips**.
- Composer validation, changed-file Pint, PHP syntax and Git whitespace checks passed. No frontend assets changed; no frontend build was required.
- The synthetic latency test advances the clock eight seconds per enrichment, verifies the previous batch has recorded signals before the next quote request, and measures eight-second quote age at each evaluation. Other cases cover partial-batch deadlines, immutable retries, scoped symbol rejection, overlapping PostgreSQL batch workers, missing midday references/SEC evidence, reused benchmark/history, unavailable provider responses, retry throttling, background lock, closed/disabled sessions, and data-only membership remaining non-nominating.

An initial test helper collided with Laravel's `seed` method; it was renamed. Two fixture assertions were corrected to account for existing synthetic input and broker-held symbols. Final full suites pass; no failed runtime trading attempt or provider request was used as a test.

No new tables, columns, backfill, settings version, dependency or price/risk arithmetic change. `PaperMarketReferences` is included in experiment source fingerprints, so deploying this change invalidates unfinished experiments' frozen process signatures; completed results remain readable. Start a new experiment when computation sources differ rather than bypassing the drift guard.

## Runtime and post-close baseline

Runtime remains **`0ee4894`**. Cached settings confirm forward signals, handoff, real-data paper fills and batch collection enabled; brokerage writes, paid AI research and AI decisions disabled. `/ready` returned ready. No runtime settings or trading service were changed here.

A private, one-time LaunchAgent `local.investing-ops.cadence-baseline-20261022` is installed and waiting for **October 22 at 15:05 Central**. It invokes the runtime `mcp:cadence-report --account=1 --date=2026-10-22` with PostgreSQL default transactions forced read-only, validates `measured` / `complete`, and saves its JSON plus the runtime source revision. It then removes its plist and unloads itself. Plist validation, script compilation and a read-only command smoke check against the previous completed session passed. At implementation completion the scheduled job has zero runs: today's completed baseline is **pending**, not measured yet. The Mac/user service must be available for execution.

Private output directory: `/Users/joe/Services/investing-ops/runtime/cadence-baseline-2026-10-22/`. Expected report: `cadence-2026-10-22.json`; provenance: `capture-metadata.json`; failures: `capture.err.log` / `capture-error.txt`. No personal data leaves the Mac. If deployment occurs before capture, its revision is recorded and the report must not be represented as a wholly pre-change baseline. The installed job captures stored observations only and cannot place a paper or broker order.

## Next

Deploy the source through the normal procedure, then enable the new warm-up flag and recache configuration/restart services through the operator procedure. Keep today's pre-change baseline and compare a later completed session's quote ages at evaluation and within-age percentage, alongside membership and reference-read progress. Real provider warm-up, post-change latency and forward-paper acceptance remain unverified. M5 remains partial; M6 remains unstarted.


## Owner deployment verification, October 22 at 12:53–12:57 Central

The owner deployed `213411c`; both checkouts match and were clean at verification. The warm-up flag was already true in cached runtime configuration; no environment edit or service restart was necessary. Database doctor verified `postgres` / `mcp_trading` on restricted role `mcp_trading_runtime`, and `/ready` passed. `schedule:list` includes the combined five-minute forward-paper cycle and the background warm-up, without a separate market-collection pass. Forward-paper gates remain on; brokerage writes and AI research/decisions remain off. No manual signal cycle or warm-up was invoked for verification.

Observed the natural **12:55** incremental batch cycle complete with 31 evaluations: **27/31 quote source ages within 20 seconds**, median **12.894538 seconds**. The immediately preceding **12:50** whole-universe cycle had **1/31 within 20 seconds**, median **51.912485 seconds**. These are actual source ages at evaluation, not a claim that every quote passed every admission/eligibility check or that a full session is accepted. All 31 outcomes remain unavailable: 29 eligibility unavailable and two external broker positions outside strategy management.

The configured nomination lists now contain 17 symbols, with twelve data-only symbols. Warm-up has started and stored complete AMT daily history and listing evidence; no new classification/profile had been recorded at 12:57:24. Those stages remain pending, not a completed provider end-to-end acceptance claim. Private first-cycle evidence is runtime `runtime/intraday-activation-2026-10-22/first-cycle.json`.

The one-time **15:05 Central** cadence job remains installed with zero runs. Because deployment occurred during the session, today's report will contain pre- and post-deployment observations and cannot be described as wholly pre-change. The capture records its runtime source revision. No implementation change, migration, policy application, paper/broker order or extra live provider test was performed during this read-only deployment verification. No full test rerun was necessary. Next: confirm complete warm-up evidence and compare later cycles/full-session results.


## Completed October 22 session and post-close capture

At 15:05 Central the one-time capture completed successfully against `213411cd2ee6a482086d169f84daf87f2dd99f57`, saved the measured/complete report and provenance, and removed its LaunchAgent plist. The private capture logs are empty. Completed-session data: **1,518 observations, 38 distinct symbols, 75/78 collection windows (96.2%)**, 1,490 quotes admitted at recording and 28 not admitted. Thirty-seven forward-paper cycles recorded 1,002 unavailable evaluations and no trade signals; the forward path began during the session, so 37 must not be compared with 78 as if it had been enabled all day. The 68.4% full-day known-quote-age figure combines pre/post-deployment behavior.

A separate read-only review split cycles by their persisted evaluation mode:

| Mode | Cycles | Evaluations | Known source times | Within 20 seconds | Median source age at evaluation |
|---|---:|---:|---:|---:|---:|
| Before: whole universe | 12 | 323 | 309 | 20/309 (6.5%) | 45.350148 s |
| After: incremental batches | 25 | 679 | 679 | 656/679 (96.6%) | 13.161786 s |

This supports the latency improvement, while retaining the four percent of post-change evaluations outside the age limit. These are source-age measurements; other admission/eligibility gates still apply.

**Warm-up did not complete.** Across the post-deployment period there is only one new pair of complete reference reads (AMT history/listing), **zero new SEC classification records and zero new profiles**. DDOG and other newly nominated symbols still lacked their reference/classification evidence at the final cycle. Scheduled background launches appear in the scheduler log, but their output is redirected to `/dev/null` and no warm-up process remains at review time. The exact termination cause is unconfirmed; the runtime CLI memory limit is 128M, which is an observation, not a proven diagnosis. Warm-up must not be marked accepted based on the passed synthetic tests or on successful listing/history reads alone.

Paper state at review: **$500 cash, zero reserved cash, zero orders and zero positions**. Latest stored outcomes retain membership, classification, history/listing and occasional action/spread blockers; XLE/XLV remain outside strategy management. No live read, manual strategy/warm-up invocation, runtime setting, policy, code or ledger mutation occurred during review. The diagnostic process used an explicit PostgreSQL read-only transaction. Private evidence: `runtime/intraday-activation-2026-10-22/session-review.json`; original capture: `runtime/cadence-baseline-2026-10-22/cadence-2026-10-22.json`.

Next: diagnose and repair the background warm-up failure with visible bounded failure/progress evidence, then verify real reference/classification/profile completion. Keep the completed mixed-session report and the separate pre/post groups. M5 acceptance remains partial; no profitability or live readiness claim is supported.
