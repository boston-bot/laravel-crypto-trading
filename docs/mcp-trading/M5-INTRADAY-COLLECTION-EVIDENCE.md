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
