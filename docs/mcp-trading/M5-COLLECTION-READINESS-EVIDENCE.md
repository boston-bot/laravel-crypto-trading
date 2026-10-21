# M5 collection readiness and development resumption

October 21, 2026. This source increment improves the existing read-only cadence command before M5 forward-paper acceptance. It makes no strategy-parameter, policy, scheduling or brokerage change. Runtime remains `3f60571` on Herd PHP 8.5.12; the new reporting code has not been deployed.

## What resumed work found

Runtime inspection found two completed queued experiments, although the documentation still said no experiment had started. Both used the checked-in CRM/MSFT October 19–20 specification: $500 baseline, $10,000 affordability scenario, and $500 wider-stop scenario. The baseline and wider-stop variants generated no orders; the larger-cash variant generated one fill, ended with one open position and $9,994.778255 marked equity. All variants had zero closed trades. Stored accounting verification passed, with 310/316 known quote samples per variant and no recorded failed attempts.

[Stored-report summaries](M5-QUEUED-TRIALS-2026-10-21.json) retain both experiment IDs, definition/dataset hashes and outcomes. The datasets have different hashes: these are separate acquisitions, not evidence of byte-identical frozen-dataset reuse. This turn read their existing reports; it did not create or resume an experiment or fetch provider data. The result demonstrates completed queued processing, not profitability, twenty-session endurance, real-job restart recovery, walk-forward acceptance or forward-paper trading.

Saved project notes were unavailable because the Hjarni connection required reauthentication. Repository instructions and verified runtime state supplied the continuation context.

## Reporting changes

`CadenceReport` now identifies its contract as `stored_cadence_v2` and reports an exact `as_of` time plus calendar-derived `pending`, `in_progress` or `complete` phase. Full-day `expected_slots`, collection `slot_coverage_pct` and strategy `expected_cycles` remain available. New elapsed fields count only five-minute windows that have finished; the current open window is excluded from both numerator and denominator. Before any window finishes, elapsed coverage is unknown (`null`). `remaining_slots` includes the current window until it closes.

The reporting window includes the open and excludes the exact close. Future locally recorded/retrieved observations, future cycle records and signals not locally available by `as_of` are excluded. Early closes and daylight-saving changes use the stored exchange calendar. `cycles` and observations may include the current open window; `elapsed_cycles` and elapsed collection counts provide the matching completed-window comparison.

Observation cadence and quote quality are separate measurements. `symbols_per_slot` counts distinct symbols; the new `observations_per_slot` retains repeat-read counts. Missing/future/inconsistent timestamps have explicit diagnostic counts rather than negative quote ages being counted as fresh. Strategy age percentages still use known valid timestamps as their denominator; unknown/invalid timestamp counts accompany them.

`signal_quote_quality` measures stored quotes at their individual recording times against the configured reporting age limit (default 20 seconds), under the connection's current authorization generation. `StoredStrategyQuote` shares the signal engine's existing admission checks: account/scope, broker provenance, completeness, authorization generation, exact symbol, component/retrieval/local availability times, positive quoted decimal prices and an uncrossed spread. Its extraction adds explicit account/scope guards and safely rejects absent prices. Existing entry/exit logic and versions were retained. Signals and reporting use one admission implementation, rather than separate definitions of a usable quote.

The report includes usable/unusable observations, symbols and windows with usable quotes, elapsed-window usable coverage and per-symbol usable-quote refresh intervals. These are counts of observed evidence, not proof that every point-in-time watchlist member had coverage. A usable quote does not establish eligibility, a symbol profile, earnings/action clearance, tradability, book liquidity, buying power or execution permission. The report never enables signals or orders.

Rows are consumed through a cursor; aggregation retains timestamps and symbol sets rather than full decrypted observation payloads. The command remains read-only and makes no broker, data-provider or model request.

## Real stored-data measurement

The source report was exercised against runtime PostgreSQL in an explicit **read-only transaction**, loading only the new reporting/admission classes into a separate diagnostic process. Runtime files, cached configuration and workers were not replaced. [The resulting report](M5-CADENCE-V2-2026-10-21.json) was captured at **11:19:20 Central**, while the session was still in progress:

- 1,024 observations covering 32 observed symbols.
- 33 completed five-minute windows; 31 contained readings: **93.9% elapsed coverage**. Total observed windows included the current open window; the full-session denominator remains 78.
- 1,005 observations met signal-quote admission at recording; 19 did not. This is distinct from their later/current freshness.
- No strategy cycles or signals were present; those runtime capabilities remain disabled.

This is partial intraday evidence. It does not complete the planned full regular-session cadence measurement. Earlier in the same check, at 11:16 Central, 998/1,016 observations met quote admission; counts increased as the existing collector continued. No provider freshness limit was relaxed.

## Verification and next work

Installed dependencies were inspected without changing lockfiles: PHP 8.5.12, Laravel 12.70.0, brick/math 0.14.8 and PHPUnit 11.5.55. Composer validation passed. Focused SQLite reporting/signal tests passed **21 tests / 85 assertions**. The full guarded PostgreSQL suite, including Herd FPM recovery and concurrency tests, passed **616 tests / 4,512 assertions**. After adding the explicit elapsed-cycle count, final PostgreSQL report tests passed **7 tests / 31 assertions**. Targeted Pint and diff checks passed. There were no test failures in this increment.

New reporting cases cover live/future evidence, pending/just-opened sessions, an early close after the daylight-saving transition, exact-close exclusion, distinct symbols versus repeated observations, stale/incomplete/malformed/future/wrong-provenance/wrong-authorization/crossed or float-price quotes, invalid age evidence, and account/scope isolation. Tests reject unexpected HTTP through the existing test harness.

No migration, backfill, frontend asset change/build, application deployment, runtime setting change, forward-ledger mutation, policy application or brokerage mutation was required. The earlier PHP/Herd maintenance edits were preserved. Private full report copies and test evidence are in runtime `storage/app/private/development-resume-2026-10-21/`.

Next within M5: deploy the reviewed reporting increment, capture the completed regular session, then address measured collection gaps and controlled forward-paper prerequisites. The parameter editor and broader point-in-time/benchmark evaluation remain open. No new experiment or trading capability was enabled. M5 remains partial; M6 is unstarted.
