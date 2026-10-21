# M5 replay, fractional order contract and measured cadence

October 19, 2026. Three M5 acceptance items named by the [opening and batch collection increment](M5-OPENING-AND-BATCH-COLLECTION-EVIDENCE.md): point-in-time replay with parameter review, the fractional LIMIT contract, and measured live cadence. Source, tests, one read-only order-tool metadata review, read-only Alpaca market data and a read-only measurement of the runtime's stored records. No deployment, migration on the runtime, settings change, order, order review or model call.

## Fractional order contract

A read-only `tools/list` through the runtime's app-owned connection saved the full metadata for `place_equity_order`, `review_equity_order`, `get_equity_tradability` and `get_equity_orders`. The client's RPC allowlist was empty, so no tool, including any order tool, could be called. Both order tools state:

> "Fractional shares: only on type=market with market_hours=regular_hours, eligible accounts, up to 6 decimal places, no short sells."

The `quantity` field adds: "Decimals (fractional) allowed for market + regular_hours only." Robinhood's public [fractional shares article](https://robinhood.com/us/en/support/articles/fractional-shares/) adds a $1 minimum order value and NMS-only eligibility. It doesn't mention limit orders.

**Fractional LIMIT orders are unsupported** for the agentic account. `RobinhoodOrderContract` records the reviewed facts:
- fractional quantities only on regular-hours market orders
- up to 6 decimal places
- a $1 minimum order value
- review date, and a sha256 of each order tool's description, so any wording change is detectable before M6 relies on it

`ExecutionPolicies` now refuses new execution versions that request `allow_fractional_limit`. Earlier applied versions stay immutable, and their stored `true` value has no effect. The legacy proposal handoff is superseded and disabled, the signal path forces whole-share limit orders, and real-data paper fills already refuse fractional quantities because broker quotes never assert fractional eligibility.

The same descriptions note behavior that matters for M6:
- `place_equity_order` sends orders to the customer for approval in the Robinhood app when review requires it
- `ref_id` is the broker's idempotency key, resent unchanged on transport retries

**Owner decision:** fractional sizing is possible only through regular-hours **market** orders. That would need a deterministic price guard (a fresh ask at or below the profile's maximum entry, within the spread limit) instead of a limit price, and a more conservative slippage assumption for market fills. Until decided, sizing stays whole-share limit orders.

## Retrospective daily replay

`StrategyReplay` (`daily_replay_v2`, corrected October 20) walks each trading session using only bars completed before it. It reuses the live classes: `ProfileCalculator`, `SignalRules`, `RiskSizer`, `TradingCosts` and `SignalParameters`. Earlier immutable v1 records remain historical evidence; their returns and parameter comparisons are superseded by the corrections below.
- **Profile window:** each profile uses the trailing 275 sessions, the live 400-day window.
- **Requested window:** `--years=N` begins on the New York date N calendar years before the as-of date and ends the day before the as-of date. Results begin on the first benchmark session in that range with enough profile warm-up. The extra 400 calendar days only warm profiles: no earlier trades, positions, returns or benchmark performance. Both requested boundaries are included in the input fingerprint. The read bound accommodates five years plus all 400 warm-up days, including leap years.
- **Entries:** at the open after a gap, otherwise one cent through the trigger. Opening entries run before intraday crossings; ties within each phase use symbol order. Limited by the live maximum-chase rule, the market filter, cooldown, the 8-position cap and whole-share 1% risk sizing with a 20% maximum position.
- **Exits:** the live exit rules are applied at the open (stops, close below the 50-day average, time exit). Opening sales can fund that day's entries. Intraday stops are applied only after all entries; their cash and position capacity become available the next session. Daily bars cannot establish which intraday event came first, so this can conservatively skip an entry that a later intraday observation would allow. The live trailing, holding and progress rules update after each close.
- **Sizing equity:** recomputed before each entry from current simulated cash, opening marks of held positions and fill marks of newly entered positions. Opening gaps, realized opening losses and preceding entry fees affect risk and concentration caps immediately. Intraday prices of other positions are unknown; opening marks remain an approximation.
- **Entry-day stop:** if the entry day's low reaches the stop, the trade is assumed stopped out that day, because daily bars cannot show which came first. Its proceeds and capacity also remain unavailable during the entry phase.
- **Costs:** the reviewed cost schedule and 1 bp slippage.

Stated limitations, carried in every result:
- daily bars approximate the 5-minute triggers and stops
- earnings blackouts are not reconstructed
- the 15-minute opening delay is not modeled
- today's watchlist is applied to the past (selection bias)
- no intraday quotes or book liquidity
- intraday exit cash/capacity unavailable until the next session
- intraday sizing uses opening marks and new-entry fills

`StrategyReplays` reads split-adjusted SIP bars (a longer, replay-only lookback) and stores each run as an immutable, encrypted record in `mcp_strategy_replays`. Each record carries the label `retrospective_daily_replay_not_forward_results`, the input fingerprints (bar hashes, versions, settings) and the result. `php artisan mcp:strategy-replay --account=<id> [--years=3] [--equity=500] [--symbol=…]` is the entry point. No order or ledger change is possible.

### Historical v1 replay — superseded (development checkout; read-only Alpaca data, no database write)

**Do not use the following v1 returns or sensitivity comparisons to choose parameters.** Review found look-ahead through intraday exit proceeds/capacity, sizing from equity before opening losses, and warm-up sessions included in the requested replay window. The figures are retained as historical evidence only. A fresh v2 real-data replay was performed after the October 20 fixes; its separate results appear below.

The 25 nominating symbols; window 2023-07-03 to 2026-10-16 (827 sessions after warm-up). One Alpaca request returned all histories in 2.5 s; each replay took about 41 s to compute.

| | $500 virtual start | $10,000 start | SPY buy-and-hold | Equal-weight watchlist |
|---|---|---|---|---|
| Total return after costs | +4.64% | +26.35% | +72.32% | +70.16% |
| Maximum drawdown | 5.60% | 11.80% | 19.00% (close to close) | — |
| Closed trades (wins/losses) | 50 (22/28) | 218 (83/135) | — | — |
| Hit rate; average win / loss | 44.0%; +5.50% / −2.84% | 38.1%; +6.57% / −3.19% | — | — |
| Profit factor | 1.41 | 1.34 | — | — |
| Setups skipped: can't afford one whole share | 909 | 86 | — | — |

At $10,000, exits were: intraday stops 110, close below the 50-day average 80, trailing stops 16, initial stops 9, time exits 2, entry-day stop 1. Of 218 trades, 208 were pullbacks and 10 breakouts.

Exploratory sensitivity at $10,000, not applied:

| Variant | Return | Max drawdown | Profit factor |
|---|---|---|---|
| Initial stop 3× ATR | +29.20% | 10.76% | 1.49 |
| Trailing stop 4× ATR | +22.77% | 11.87% | 1.29 |
| Both | +21.74% | 12.03% | 1.36 |

Historical v1 reading (withdrawn pending a corrected run):
- **Lag versus buy-and-hold:** the starting parameters trail buy-and-hold by a wide margin in this period, while holding about 60% of SPY's drawdown. Return over drawdown was about 2.2, against SPY's 3.8.
- **Not the stop width:** wider stops don't close the gap, so the shortfall is structural for this swing design in a strong bull market rather than one mis-set stop.
- **Small account:** at $500, whole-share sizing blocks most setups.

These are retrospective, selection-biased approximations, not forward results and not evidence of an edge. Parameters were not changed.

### October 20 replay corrections

The three review findings are fixed in source as `daily_replay_v2`. Five regression tests failed against v1 before the implementation changed. Coverage now includes opening/intraday candidates after a later stop, cash shortages with unused position slots, entry-day stop proceeds, opening-entry precedence over an alphabetically earlier intraday candidate, and replacement sizing after a gap loss. In the gap fixture, $9,028.6012 after the opening sale permits 17 replacement shares at $101.2102; v1 bought 19 and exceeded the 20% concentration cap.

Window tests exclude pre-window trades and post-window bars from performance; service tests verify a one-year window and its benchmark, a weekend boundary, the New York date when UTC is already on the next day, five years plus the full warm-up span, and an empty replay window stored incomplete with duplicate-safe retries. All market data in these tests is synthetic and unexpected HTTP is rejected. Existing immutable runs are not rewritten. No migration, backfill, parameter change, runtime change or real provider call is required by this correction.

## Measured cadence

`CadenceReport` and `php artisan mcp:cadence-report --account=<id> [--date=YYYY-MM-DD]` measure one regular session from stored records only:
- **Collection:** five-minute slots with reads, symbols per slot, slot span, quote age at retrieval and per-symbol refresh interval.
- **Strategy:** cycles versus expected, cycle lag, signal outcomes and reasons, quote age at evaluation and the share within the quote-age limit.

Durations are in milliseconds, percentiles are nearest-rank, and an unknown session is reported as unavailable.

**Baseline, the runtime's real 2026-10-19 session.** The runtime was on `80225da`, with the older rotating collector of five symbols per five minutes; the batch collector was not deployed and strategy cycles were off.

| Measure | Result |
|---|---|
| Five-minute slots with reads | 77 / 78 (98.7%) |
| Observations / symbols | 394 / 69 (the old allowlist plus the watchlists after the switch) |
| Symbols per slot | median 5, max 8 |
| Slot span | median 17.6 s, p95 60.9 s, max 81.0 s |
| Quote age at retrieval (broker bid/ask times) | median 4.5 s, p95 15.5 s, max 50.8 s |
| Per-symbol refresh interval | median 44.9 min, p95 49.5 min, max 65.1 min |
| Strategy cycles | 0 of 78 (disabled) |

Findings:
- **Refresh is too slow:** each symbol was refreshed every 45 minutes or so, against the 5-minute design. That confirms the batch collector is required, and it needs deploying and enabling before forward evaluation.
- **Quotes arrive old:** quote age is substantial even at retrieval, because quiet symbols' bid/ask times lag. With a 20-second limit, the p95 of 15.5 s leaves little margin by the time a signal evaluates. Measure `within_quote_max_age_pct` after strategy cycles are enabled, before relying on it.

## Verification

| Check | Result |
|---|---|
| Full SQLite | **515 passed / 3,588 assertions, 34 PostgreSQL-only skips** |
| Full PostgreSQL | **548 passed / 3,901 assertions, 1 existing PHP-FPM environment skip** |
| `composer validate`, Pint, `git diff --check` | Passed |

These runs include another session's uncommitted batch-collection and session-valuation work in the same working tree.

October 20 correction verification is recorded below; the table above describes the historical v1 checkpoint.

New tests:
- **`StrategyReplayTest`** (4): exact breakout entry and trailing-stop exit, whole-share size zero at $500, gaps beyond the chase limit and a falling market never entering, entry-day stop ordering, determinism.
- **`StrategyReplaysTest`** (3): a labeled, immutable, encrypted record with fingerprint and idempotent request; a missing benchmark recorded as incomplete; gates, operator scope and the command.
- **`CadenceReportTest`** (1): exact slot, span, age, interval, lag and quote-age-limit metrics; the post-close exclusion; an unknown session.
- **Contract:** `ExecutionPoliciesTest` covers the contract checks and refusal; `RealDataPaperFillsTest` now applies fractional shares without fractional limits.

## Migration and configuration impact

Additive migration `2026_10_19_000026_create_mcp_strategy_replays.php` creates one table: unique request identity, a history index, and a PostgreSQL check that complete replays have dates. It follows the other session's uncommitted `000025`. There are no new settings. `DailyBars` accepts an optional longer lookback, for replay only. Next:
- deploy and enable the batch collector, then measure a full session
- review the corrected v2 replay below before deciding on strategy parameters; decide on fractional market orders separately
- then controlled forward paper evaluation

## October 20 correction verification

- Focused replay tests on PostgreSQL: **16 tests / 72 assertions**, all passed.
- Full SQLite: **524 passed / 3,622 assertions, 34 PostgreSQL-only skips** (558 total).
- Full PostgreSQL: **557 passed / 3,935 assertions, 1 existing PHP-FPM environment skip** (558 total), run alone against the guarded `mcp_trading_test` database.
- `composer validate`, changed-file Pint and `git diff --check`: passed. Installed Laravel 12.70.0 and brick/math 0.14.8 were preserved; lockfiles are unchanged. No frontend assets changed, so no build was required.
- Nine new tests cover the corrections; five failed against v1 before implementation. No final test failures remain.

At the correction checkpoint, the real-data rerun and parameter review remained outstanding. No real market-data/broker/model request, migration, backfill, runtime deployment or capability enablement occurred during the code correction. The subsequently authorized data rerun follows. Existing v1 evidence remains immutable and explicitly superseded. M5 stays partial; M6 remains unstarted.

## Corrected real-data replay, October 20

The owner requested this rerun after the v2 fixes. Both starting balances use the same cached Alpaca split-adjusted SIP daily-bar snapshot, current unmodified signal parameters and the current reviewed fee model (`rh_listed_equity_2026q4_v1`) with 1 bp slippage. This is a retrospective daily approximation and includes no order or paper-ledger write.

The selection is the runtime's stored nominating-watchlist revision **314**, retrieved **2026-10-20 19:56:04 UTC**; 23 unique symbols, excluding the data-only list. Runtime access used a database read-only session; only the revision metadata and symbols were exported. No runtime credential was imported into the development checkout. The development app's own configured Alpaca credentials performed the one-shot reference reads. Its data flags were enabled in that process only; persistent settings and scheduled loops stayed unchanged.

- Requested and actual session window: **2023-10-20 through 2026-10-19**, **751 sessions**, starting flat. Warm-up bars produced no trades or returns.
- Fetch: **2026-10-20 20:02:32.925979 UTC** as-of; **3 read-only HTTP requests**, **2.289 seconds**, all 23 symbols and SPY complete with **1,027 bars each**, including warm-up. No symbols were excluded. Each replay took about **34.5 seconds**.
- Settings: whole shares, 1% planned risk per entry, 20% maximum position, eight open positions. These are the existing signal execution defaults, used solely for research, not a newly applied execution policy.
- The universe now has 23 symbols, versus 25 in v1, and the requested period/end date also differ. Changes in returns cannot be attributed to the code fixes alone. No sensitivity reruns or parameter optimization were performed.

| Measure | $500 start | $10,000 start |
|---|---:|---:|
| Final marked equity | $525.74 | $12,421.68 |
| Return after modeled costs | +5.1480% | +24.2168% |
| Maximum drawdown, daily marks | 6.1421% | 10.4486% |
| Closed trades (wins / losses) | 41 (18 / 23) | 182 (70 / 112) |
| Open positions at end | 0 | 2 |
| Hit rate | 43.90% | 38.46% |
| Average win / loss | +6.2764% / −2.6607% | +7.1646% / −3.1156% |
| Profit factor | 1.5130 | 1.3894 |
| Setups skipped at zero whole-share size | 728 | 65 |
| Setups skipped at position capacity | 0 | 14 |

SPY price buy-and-hold over the same dates: **+79.9333%**, maximum daily-mark drawdown **18.9989%**. Equal-weight price buy-and-hold across the 23 symbols: **+78.3926%**. Those benchmarks have no modeled fees, slippage or dividends. The strategy uses the current reviewed fee schedule throughout history; historical fee rates and cash dividends are not reconstructed. The $10,000 ending equity includes unrealized P&L in two open positions, marked at the final daily close.

In this selected universe and period, the default replay returned less than both price benchmarks with a smaller daily-mark drawdown. Whole-share affordability still blocks many $500 entries. This does not establish an edge or validate a parameter change: current-watchlist selection bias, unreconstructed earnings dates and daily-bar timing/liquidity assumptions remain. No parameter or fractional-order decision was applied. Controlled forward paper evidence remains outstanding.

### Reproducibility and verification

[Machine-readable report](M5-REPLAY-V2-2026-10-20.json) contains exact metrics, universe, requested window, parameters/settings, source-file hashes, artifact hashes and verification outcomes. Local private artifacts are under `storage/app/private/mcp-replays/2026-10-20-v2/` (ignored by Git):

- `inputs.json`: full normalized provider reads with provenance/completeness/retrieval times, parameters, settings and source hashes. SHA-256: `021715a0fd478d2ab1d4cea7e9800db2ae45c1c4245f6002b1f766a23cc4cb80`.
- `replay-500.json` and `replay-10000.json`: full trades, equity curves, limitations and the input snapshot hash.
- `run.php`: local evidence runner. `php storage/app/private/mcp-replays/2026-10-20-v2/run.php replay` recomputes from the cached snapshot with no network or database call and rejects changed source hashes. The `fetch` action was used once successfully; do not use it to reproduce these exact results because it acquires a new snapshot.

Independent exact-decimal checks reconstructed final equity from starting cash, every closed trade's prices/quantities/fees and the final marks/buy fees of open positions. They matched **$525.74** and **$12,421.68** exactly after cent rounding. Every closed trade's P&L, trade-count conservation (entries = closed + open), window bounds and both snapshot hashes passed. The one-shot fetch initially failed under the network sandbox and then succeeded with approved network access; no incomplete input was used. An initial local runner path error was corrected before the fetch.

The code-fix test results above still apply; this rerun changed evidence only. No migration/backfill, runtime deployment/configuration change, watchlist edit, brokerage mutation, live/paper activation, AI request or database write occurred. Next within M5: owner parameter review, measured batch-collection cadence and controlled forward paper acceptance; M6 remains unstarted.


### Later runtime activation, October 20

The owner subsequently deployed `51392ea` with migrations `000025`/`000026`, then authorized read-only batch collection and opening valuations. Those two runtime flags are now true; signals, signal handoff, paper fills, AI and brokerage writes remain false. Initial measurement occurred after close: 32 attempted symbols in 48.874 seconds, 32 incomplete base observations due to stale mixed quote batches, no time-budget/backoff stop and unchanged $500 cash/no paper positions/orders. This does not replace the regular-session baseline above or establish fresh 5-minute coverage. [Activation, private evidence and next-session measurement](MAC-SETUP-AND-OPERATIONS.md#october-20-read-only-batch-activation).
