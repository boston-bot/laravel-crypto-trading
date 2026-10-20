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

`StrategyReplay` (`daily_replay_v1`) walks each trading session using only bars completed before it. It reuses the live classes: `ProfileCalculator`, `SignalRules`, `RiskSizer`, `TradingCosts` and `SignalParameters`.
- **Profile window:** each profile uses the trailing 275 sessions, the live 400-day window.
- **Entries:** at the open after a gap, otherwise one cent through the trigger. Limited by the live maximum-chase rule, the market filter, cooldown, the 8-position cap and whole-share 1% risk sizing with a 20% maximum position.
- **Exits:** the live exit rules are applied at the open (stops, close below the 50-day average, time exit), then intraday stops against the day's low. The live trailing, holding and progress rules update after each close.
- **Entry-day stop:** if the entry day's low reaches the stop, the trade is assumed stopped out that day, because daily bars cannot show which came first.
- **Costs:** the reviewed cost schedule and 1 bp slippage.

Stated limitations, carried in every result:
- daily bars approximate the 5-minute triggers and stops
- earnings blackouts are not reconstructed
- the 15-minute opening delay is not modeled
- today's watchlist is applied to the past (selection bias)
- no intraday quotes or book liquidity

`StrategyReplays` reads split-adjusted SIP bars (a longer, replay-only lookback) and stores each run as an immutable, encrypted record in `mcp_strategy_replays`. Each record carries the label `retrospective_daily_replay_not_forward_results`, the input fingerprints (bar hashes, versions, settings) and the result. `php artisan mcp:strategy-replay --account=<id> [--years=3] [--equity=500] [--symbol=…]` is the entry point. No order or ledger change is possible.

### First real replay (development checkout; read-only Alpaca data, no database write)

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

Reading:
- **Lag versus buy-and-hold:** the starting parameters trail buy-and-hold by a wide margin in this period, while holding about 60% of SPY's drawdown. Return over drawdown was about 2.2, against SPY's 3.8.
- **Not the stop width:** wider stops don't close the gap, so the shortfall is structural for this swing design in a strong bull market rather than one mis-set stop.
- **Small account:** at $500, whole-share sizing blocks most setups.

These are retrospective, selection-biased approximations, not forward results and not evidence of an edge. Parameters were not changed.

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

New tests:
- **`StrategyReplayTest`** (4): exact breakout entry and trailing-stop exit, whole-share size zero at $500, gaps beyond the chase limit and a falling market never entering, entry-day stop ordering, determinism.
- **`StrategyReplaysTest`** (3): a labeled, immutable, encrypted record with fingerprint and idempotent request; a missing benchmark recorded as incomplete; gates, operator scope and the command.
- **`CadenceReportTest`** (1): exact slot, span, age, interval, lag and quote-age-limit metrics; the post-close exclusion; an unknown session.
- **Contract:** `ExecutionPoliciesTest` covers the contract checks and refusal; `RealDataPaperFillsTest` now applies fractional shares without fractional limits.

## Migration and configuration impact

Additive migration `2026_10_19_000026_create_mcp_strategy_replays.php` creates one table: unique request identity, a history index, and a PostgreSQL check that complete replays have dates. It follows the other session's uncommitted `000025`. There are no new settings. `DailyBars` accepts an optional longer lookback, for replay only. Next:
- deploy and enable the batch collector, then measure a full session
- decide on fractional market orders and on the strategy parameters, using the replay
- then controlled forward paper evaluation
