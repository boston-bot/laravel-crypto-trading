# M5 market calendar and daily symbol profiles

October 19, 2026. Follows [listing, history and liquidity sources](M5-MARKET-REFERENCE-EVIDENCE.md). Source, tests and one bounded live read-only calendar check. No deployment, runtime migration, settings change or enablement. The earnings-date source remains open and needs an owner decision (below).

## Market calendar automation

Sessions were previously loaded by hand from a reviewed NYSE manifest and expire seven days after loading, which stops session-aware work. The calendar now refreshes automatically:

- **Source.** `GET /v2/calendar` on the configured Alpaca account host (the same host and keys as the listing lookups). Contract `alpaca_calendar_v2_v1`, read-only. Times are New York local.
- **Validation.** Each row has exactly `date open close session_open session_close settlement_date`, a weekday date inside the requested range, strictly increasing dates, a 09:30 open and a close no later than 16:00. A close before 16:00 is `early_close`. Anything else fails the whole read, and an incomplete read stores nothing.
- **Days without a session.** Inside a complete range, every date the provider omits, including weekends, is stored as `holiday` (no regular session). Nothing is inferred outside the range.
- **Conflicts.** A stored session from another source (such as the hand-reviewed manifest) that disagrees is never overwritten. It is reported and left to expire, so the calendar fails to unknown rather than guessing. Agreeing sessions are refreshed under the provider source.
- **Schedule.** `php artisan mcp:market-calendar` stores the past 7 and next 60 days; it exits non-zero on an error or conflict. With `ALPACA_DATA_ENABLED` and the new default-off `ALPACA_CALENDAR_ENABLED`, the scheduler runs it daily at 06:10 New York. Stored sessions keep the existing seven-day expiry, so a missed refresh becomes `unknown`.

Bounded live check, October 19 (one read, no database writes): 57 sessions from 2026-10-19 to 2027-01-08. Early closes were on 2026-11-27 and 2026-12-24, and there was no session on 2026-11-26, 2026-12-25 or 2027-01-01, matching the NYSE 2026 calendar. The first session (09:30–16:00 New York) matches the hand-reviewed manifest.

## Daily symbol profiles and playbooks

**Benchmark history.** Reference collection now also reads SPY's split-adjusted daily history with every universe collection, with no listing read for it. That supplies the market filter and relative strength.

**Parameters.** `StrategyParameters` holds the design's starting values as version `swing_parameters_v1_defaults`; each profile records the version and a hash. The owner parameter editor is later work. Watchlist contents and model text cannot change these values.

**Calculator.** `ProfileCalculator`, version `swing_profile_v1`, is pure: it uses exact decimals, comparisons are cross-multiplied, and it makes no network, storage or model use. From completed bars it computes:
- 50- and 200-session simple averages
- 20-session exponential average, seeded with the first window's simple average and rounded to 12 places at each step so it is reproducible
- 14-session average true range (simple mean of true ranges)
- 126-session return for the symbol and SPY
- 20-session high and low, and the prior session's high

Market filter: SPY close above its 200-session average.

Setups, in order:
1. **Breakout:** uptrend (close above the 200-session average, and the 50 above the 200), return above SPY's, ATR at most 6% of close, and a 20-session range of at most 3× ATR. Trigger: the 20-session high.
2. **Pullback:** the same base, a close within 1× ATR of the 20-session exponential average, and still above the 50-session average. Trigger: the prior session's high.
3. **None:** with the first failed reason, `not_in_uptrend`, `relative_strength_weak`, `volatility_too_high` or `no_setup_pattern`.

Levels:
- maximum entry: trigger + 0.5× ATR, rounded down
- reference initial stop: trigger − 2× ATR, rounded up

Entry blockers are recorded separately and never change the setup: `market_filter_off`, and `earnings_date_unknown` on every profile until an earnings source exists. Too little history (fewer than 200 sessions), too little benchmark history, or a benchmark ending on a different session gives `unavailable`, never a guess.

**Storage.** `SymbolProfiles` stores one immutable encrypted record per symbol and request in `mcp_symbol_profiles`, built only from history reads the eligibility rules would accept at the cutoff (complete, at most 30 hours old, current). Each record holds the history and benchmark read IDs, profile and parameter versions, cutoff and input fingerprint; the same inputs and cutoff give the same fingerprint. A reused request with a different cutoff is refused, and future cutoffs are refused. Missing or stale history gives `daily_history_*`, and missing benchmark history gives `benchmark_daily_history_*`. `php artisan mcp:symbol-profiles --account=<id> [--symbol=…]` prints facts only; `--scheduled` runs the idempotent daily pass. With the new default-off `MCP_SYMBOL_PROFILES_ENABLED`, the scheduler runs it at 06:50 New York, after the 06:40 reference refresh. A profile is never an entry permission: signals, eligibility, earnings, sizing and the paper boundary are separate.

## Test-isolation fix

The three PostgreSQL race tests written in this and the previous increments (SEC classification, market references, symbol profiles) committed fixture rows through worker processes without cleaning them up, and listed rows globally. After `SchemaPlacementTest` (which runs the suite's one fresh migration), the new profile race test leaked a synthetic market session into every later feature test. That caused 48 failures, which were diagnosed and fixed before acceptance.

The three tests now scope their queries to their own connection. A shared `IntegrationCleanup` removes every row their fixture connection and portfolios committed, plus synthetic-fixture sessions. Rows leaked by earlier standalone runs were removed from the dedicated `mcp_trading_test` database through the same helper, under the test-database guard. Two consecutive full PostgreSQL runs passed.

## Verification

| Check | Result |
|---|---|
| Full SQLite | **452 passed / 3,244 assertions, 31 PostgreSQL-only skips** |
| Full PostgreSQL, two consecutive runs | **482 passed / 3,531 assertions, 1 existing PHP-FPM environment skip** |
| `composer validate`, Pint, `git diff --check` | Passed |

New tests:
- **`MarketCalendarSyncTest`** (4 tests):
  - holiday and early-close storage, including weekends, with expiry
  - conflicts not overwritten
  - eight malformed calendar cases and HTTP errors storing nothing
  - gates and invalid ranges with no network; command output
- **`ProfileCalculatorTest`** (6 tests):
  - exact breakout levels and ATR; a pullback trigger
  - the three no-trade reasons
  - market filter off as a blocker only
  - short and misaligned history
  - exact, reproducible averages
- **`SymbolProfilesTest`** (4 tests), through the real adapter and storage path:
  - immutable encrypted records with no network
  - reproducible fingerprints
  - request and cutoff idempotency
  - missing, stale and benchmarkless history at point-in-time cutoffs
  - gates, operator and universe refusals, daily idempotency and the command
- **Race tests:** `SymbolProfileConcurrencyTest` (three duplicate workers record one profile, and the state check rejects a setup on an unavailable profile). The updated `MarketReferenceConcurrencyTest` now includes the benchmark history.

## Earnings-date source: owner decision needed

An unknown earnings date blocks every new entry, by design. No current source supplies one:
- **Robinhood:** the catalog advertises earnings data, but its schema is stored only in the runtime database. No tool name or contract is reviewed.
- **Alpaca:** no earnings calendar.
- **SEC:** 8-K Item 2.02 filings record past releases, not scheduled dates.

Options:

| Option | Needs | Notes |
|---|---|---|
| Review the Robinhood earnings tool | Owner permission to read the runtime's stored tool catalog (schemas only), or a fresh read-only catalog discovery | Existing provider and authorization; contract and timeliness unknown until reviewed |
| Alpha Vantage `EARNINGS_CALENDAR` | Owner creates a free API key | One CSV call returns all companies' scheduled reports for 3, 6 or 12 months; reported free limit of about 25 requests per day, enough for a daily refresh; terms and accuracy to verify ([overview](https://www.macroption.com/alpha-vantage-earnings-calendar/)) |
| Finnhub earnings calendar | Owner creates a free API key | Per-range calendar; free-tier limits and rights unverified ([comparison](https://qveris.ai/guides/stock-api-free-comparison/?lang=zh)) |

Until a source is chosen, profiles and future signals carry `earnings_date_unknown`, and no paper entry can be created.

## Migration and configuration impact

Additive migration `2026_10_19_000021_create_mcp_symbol_profiles.php` creates one table: unique request identity, a history index, foreign keys to the reference reads, and a PostgreSQL check that a setup exists only on complete profiles. There is no backfill. New default-off settings: `ALPACA_CALENDAR_ENABLED` and `MCP_SYMBOL_PROFILES_ENABLED`, forced off in the test configurations. Reference collection now writes one extra history record per run (SPY). Next M5 work: the earnings source once chosen, then 5-minute entry and exit signals.
