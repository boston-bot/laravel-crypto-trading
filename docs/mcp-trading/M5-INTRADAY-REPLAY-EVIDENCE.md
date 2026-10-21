# M5 historical quote trial

Follow-up: [multi-session mode and comparison register](M5-MULTISESSION-REPLAY-EVIDENCE.md) extend this trial; this document preserves the original single-session scope and evidence. The original frozen source is kept under the private trial directory, and `reproduce-archived-source.php` reproduces both original results exactly after current source changes.

October 20, 2026 (Central time). Source implementation and a completed real-data research trial. No runtime deployment, migration, persistent configuration change, broker mutation or forward paper-ledger write occurred. M5 remains partial; M6 is unstarted.

## What changed

`StrategyReplay` now has an `intraday_quotes_v1` mode. It reuses `ProfileCalculator`, `SignalRules`, `EligibilityRules`, `RiskSizer` and `TradingCosts`. `PaperFillPrice` contains the unchanged adverse bid/ask fill calculation previously inside `OfflineSimulator`; both forward simulation and historical replay now call it. There is no model call, separate language strategy engine, broker order gateway or new database writer.

The independently default-off `MCP_HISTORICAL_QUOTE_REPLAY_ENABLED` flag gates manual acquisition and archival. There is no scheduled historical loop. `mcp:intraday-replay` accepts one completed session, one or two explicit research symbols, a virtual starting balance and a 20–390 minute sample window. The session must have closed at least 20 minutes earlier and be covered by the reviewed cost schedule (currently Q4 2026). Early-close windows stop at the verified close. A 180-second acquisition checkpoint stops further quote requests; individual provider adapters retain their own timeouts and pagination bounds.

`HistoricalQuoteReplays` archives inputs/results in the existing encrypted immutable `mcp_strategy_replays` table, distinguished by replay version and the label `retrospective_sampled_quote_scenario_not_forward_or_point_in_time`. Request UUIDs reuse the same record without another acquisition; changed arguments conflict. Operator/account scoping and authorization generation are rechecked before archival. The snapshot pins parameters, risk/cost settings and owner allocation/daily-loss settings. It never changes their applied versions. No new migration is required beyond already deployed `000026`.

## Data and chronology

The initial access probe returned HTTP 200 using the development app's existing Alpaca credentials. [Alpaca historical quotes](https://docs.alpaca.markets/us/reference/stockquotes-1) support explicit inclusive start/end times, descending timestamp order and symbol as-of mapping. Sampling requests exactly one symbol and the newest record in the preceding 20 seconds. An older-page token is expected with this selection; it is not a claim to have downloaded every quote in the interval. Missing records remain unknown. Response fields, requested symbol, price/size strings, time bounds, feed and currency are checked. Access denial/rate limiting/malformed responses stop acquisition and archive an incomplete outcome without partial performance.

Quote event times retain all nine nanosecond digits. Future quotes, including one nanosecond after the cutoff, cannot enter decisions or fills. Sizes are shares: [Alpaca's November 2025 change](https://docs.alpaca.markets/us/v1.1/changelog/marketdata-bid-and-ask-size-display-change) is the reviewed unit basis, and older quote dates are refused. Only regular `R` quote conditions are usable; other conditions remain unknown. [NYSE quote-condition specifications](https://beta.nyse.com/publicdocs/nyse/data/Daily_TAQ_Client_Spec_v4.1a.pdf) describe that regular condition.

Profiles use only raw daily bars from before the replay date, ending on the calendar's previous regular session. The existing `DailyBars` adapter gained an explicit raw option; its eligibility/live default remains split-adjusted. Raw profiles avoid mixing today's split-adjustment basis with historical unadjusted quotes. Corporate-action process windows cover the warm-up span in bounded chunks. Unsupported non-cash actions refuse the scenario instead of guessing price/quantity adjustments. Cash dividends before this flat-start session are not adjusted; there are no carried positions or dividend entitlements. Observed process-window queries are not proof of historically complete publication knowledge.

Decisions run every five minutes from the verified open, with the existing 15-minute entry delay. Previously accepted orders process first on each decision tick, matching the combined forward paper cycle's ordering. A new intent cannot fill using its decision quote: source time must be strictly later than acceptance and later than the last consumed quote. The quote one second before the sample-window end is for valuation only and cannot fill an order. A full-session DAY remainder expires; shorter-window remainders remain pending.

Whole-share fills use adverse ask/bid prices and slippage, respect immutable limit bounds and displayed size, and charge the reviewed execution costs. Partial fills keep remaining reservations. Position/cash/sleeve/risk and daily-loss admission are rechecked using current sampled bid marks; unavailable marks block buys. Stop exits remain independent of entry pauses. Closed-position P&L includes all partial-execution cash flows/fees. Missing final position marks produce null final equity/return rather than an entry-price or stale-mark substitution. Drawdown is measured over known sampled marks only.

## Real-data trial: October 20 session

The trial used CRM/MSFT for **08:30–15:00 Central**, starting flat. These are fixed research selections, not a reconstructed historical watchlist. Inputs used the development app's own market-data credentials. Only three non-secret existing owner rules were read from runtime: 100% active sleeve, 0% cash floor, 20% daily-loss pause. Signal execution defaults supplied 1% planned entry risk, 20% maximum position, eight positions, whole-share limits, 1 bp slippage, 5 bp offset and 20-second quote age. No signal policy or virtual balance was applied to the running app.

- Acquisition: **166 read-only HTTP requests, 22.487 seconds**, 79 sampled times across the session.
- Prior daily history: **276 raw bars each for CRM, MSFT and SPY**, September 15, 2025 through October 19, 2026. October 20 daily bars never entered profiles.
- Usable samples: **154 / 158** symbol-time observations. Four remained unavailable; no quote was fabricated or forward-filled into eligibility.
- Both balance comparisons use the exact same saved snapshot and parameters.

| Result | $500 virtual start | $10,000 virtual start |
|---|---:|---:|
| Orders / fills | 0 / 0 | 1 / 1 |
| Closed trades | 0 | 0 |
| Open positions at end | 0 | 1 |
| Final marked equity | $500.00 | $9,994.78 |
| Marked P&L | $0.00 | −$5.22 |
| Trigger evaluations skipped at zero whole-share size | 4 | 0 |

The $10,000 marked loss is unrealized, with an open position. This trial establishes a functioning historical quote path, not strategy performance or an edge. The $500 result confirms the existing whole-share affordability constraint for these two symbols. Repeated trigger evaluations are not distinct trades.

### Evidence and reproducibility

[Machine-readable verified report](M5-INTRADAY-REPLAY-2026-10-20.json) records metrics, settings, source hashes and checks. Full normalized data, orders, fills and curves are private under `storage/app/private/intraday-replay/full-session-2026-10-20/` (ignored by Git):

- `inputs.json`: snapshot SHA-256 `1dfe3566c06fd4099c157b6aa74027a812225566b4beb646b9993325a68a7c2a`.
- `replay-500.json`, `replay-10000.json`: both bind that snapshot hash.
- `verify.php` independently reconstructs cash, shares and final bid-marked equity from fills; checks acceptance/source chronology, displayed-size and limit bounds, liquidity watermarks and snapshot identity. All passed.
- `run.php replay` repeats both cases from the cache without provider or database requests, refusing changed source hashes. `fetch` acquires a new snapshot and is not an exact reproduction command.

An earlier first-hour acquisition was an exploratory pilot before aligning fill sampling with the five-minute forward cycle. It is kept separately in `pilot-first-hour/` and is not the full-session evidence above. Initial fixture failures from fixed-UTC daily timestamps across DST and reused HTTP-fake callbacks were corrected; no runtime data was involved.

## Verification and limits

Automated tests use synthetic providers and refuse unexpected HTTP. They cover exact numeric/time parsing, future nanoseconds, empty selections, unit-era restrictions, denied/rate-limited access, independent gates, scope, encrypted archival/idempotency, the CLI, raw-profile basis, future daily-bar exclusion, opening delay, whole-share zero sizing, spread/limit rejection, later-source fills, partial fills, reused-liquidity refusal, unavailable final marks and DAY expiry without valuation-only fills. Full-suite totals are in the [checklist](DEVELOPMENT-CHECKLIST.md). No frontend assets or dependency versions changed.

Remaining limitations are explicit in each result: fixed-universe selection; historical earnings blackouts, listing/classification and broker permissions assumed; event-time sampling does not establish original arrival latency; sampled displayed liquidity does not establish queue priority or all intervening fills; retrospective provider corrections remain possible. This mode starts flat for one session and does not carry overnight positions or model multi-session corporate-action entitlements. It does not replace the daily replay's multi-year exploration, point-in-time walk-forward acceptance, or measured regular-session operational checks.

Runtime was rechecked afterward: batch collection/opening valuations remain enabled; signals, handoff, fills, AI and brokerage writes remain disabled. Cash stays $500, reserved cash $0, ledger version 1, no paper orders/positions. No private settings or credentials were copied between apps; only the non-secret owner policy fields above were exported. No broker mutation, runtime archive write, migration or deployment occurred.

Next within M5: extend historical evaluation across sessions with carried positions and dated earnings/universe/action evidence, review the whole-share order policy, and finish live collection/forward-paper acceptance. M6 remains a separate unauthorized milestone.
