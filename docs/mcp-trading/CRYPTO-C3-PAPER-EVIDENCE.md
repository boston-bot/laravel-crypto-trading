# Crypto C3: 24/7 paper lifecycle (first increment)

Status: **lifecycle committed in `b54a6e4` and deployed October 26 (migration `000035`, batch 19) with crypto paper activated; the candle-settlement correction is deployed in `0ec26f5` (migration `000036`, batch 20).** This increment turns C2's evidence-only evaluation into a scheduled 24/7 paper cycle with simulated limit orders, fills, exits and portfolio limits, plus a refreshed account fee tier. Candle replay and the crypto dashboard section are the remaining C3 build items. It authorizes no venue order: both crypto write flags stay false and no code path reaches a venue `placeOrder`.

## What changed

**One shared ledger.** The fill, cancel/expiry, position, event and observation bookkeeping moved unchanged from `OfflineSimulator` into `PaperLedger`. Equity paper orders and crypto paper orders write through the same class. The only behavioral addition is in the cash reservation for a notional venue fee (`venue_notional_rate_v1`): it uses the fee at the bound price, where the old code threw for this fee kind. Equity behavior is covered by the existing suites.

**Crypto paper orders (`CryptoPaperOrders`).** Every actionable signal gets exactly one handoff record.
- **Entries.** A limit buy is placed with its bound at ask + 10 bp slippage, rounded up to the price increment. Size, capacity and the loss pause are recomputed from the current ledger and the signal's own quote. The shared `RiskSizer` applies the 5% risk budget, the 25% position cap, available cash and venue increments and minimums.
- **Exits.** A limit sell is placed for the unreserved holding, rounded down to the base increment, with its bound at bid − 10 bp rounded down to the price increment.
- **Fills.** An order may fill on submission against the quote that produced its signal. Later attempts need a strictly newer stored quote. Each fill is capped by the displayed best bid/ask size not already consumed on that observation. The fill price is the adverse bid/ask plus slippage. Fees reserve the greater of the maker and taker rates.
- **Expiry.** Orders expire 600 seconds after the cycle, releasing any reserved cash or quantity. A policy change cancels open orders.
- **Fields.** Orders record `instrument_policy_id` (no equity policy application), `auth_generation` 0, origin `real_data_paper_simulation` and `asset_class` crypto.

**Evaluation v3 (`crypto_stored_evaluation_v3`).** Each five-minute UTC window is frozen once, under the portfolio row lock. Steps, in order:
1. Advance open orders on newer quotes.
2. Value the sleeve: cash plus holdings at a fresh bid. An unknown mark makes equity unknown, never zero.
3. Compute the UTC opening baseline.
4. Evaluate exits for held products with the shared `SignalPositionFacts`, `StrategySignals::exitContext` (now public, with a UTC day option) and `SignalRules::exit`.
5. Evaluate entries for the collection list with the shared `SignalRules::entry`, plus these gates:
   - fee schedule
   - valuation
   - daily loss pause (latched for the UTC day)
   - three open positions, counting pending buys
   - open order on the product
   - two-UTC-day cooldown after an exit
   - market filter
   - chase ceiling
   - sizing
6. Hand signals to paper orders, exits first.

Further behavior:
- **Exit inputs.** Exits use any fresh, valid quote even when volume or spread would block an entry. A disabled, cancel-only or post-only product cannot exit.
- **Held products.** A held product stays evaluated, and `CryptoMarketCollector` keeps collecting it, after it is removed from the configured list.

**Baseline.** The daily-loss baseline is the ledger cash at 00:00 UTC, plus any holdings at that time marked at the open of the stored five-minute candle starting at 00:00. A sleeve opened during the day starts from its opening cash. If that candle was never stored (the host was asleep for more than two hours after midnight), the baseline is unavailable and entries stop for that day. Exits continue.

**Storage.** Profiles are computed once per UTC day: identical stored history reuses the existing immutable profile, which pins all 400 bars and their IDs. Each signal stores its product row, quote, eligibility, cost schedule, ledger facts and the profile reference. Full parameters are kept only on actionable signals; the policy pins them for every other row. This keeps a 25-product, 288-cycle day bounded.

**Fee refresh (`CryptoFeeObservations`).** A new immutable table, `mcp_crypto_fee_observations`, stores each fixed read-only tier GET. It records tier, rates, review reasons and the response hash, never balances or provider bodies; a failure is stored only as `fee_tier_read_unavailable`. `mcp:crypto-fees --scheduled` runs hourly at minute 7. It reads only when there is no complete observation from the last 12 hours and no attempt in the last 50 minutes. Policy contract v3 adds an owner `fee_review`:

| Field | Meaning |
|---|---|
| `pricing_tier` | The reviewed tier |
| `excluded_products` | Exception pairs |
| `cost_scope` | `product_all_in` |
| `tax_treatment` | `excluded_by_owner_direction` or `verified_zero` |
| `reviewed_at` | When the owner reviewed the tier |
| `source` | Where the review came from |

A product receives a fee schedule only when all of these hold:
- an explicit product observation exists and has not expired, or
- the newest complete observation is under one day old at the cutoff, has the reviewed tier, and has only the accepted reasons (product applicability, tax), and the product is not excluded.

A cost-plus or unknown commission is never accepted. The policy also pins `execution`: limit orders, 600-second TTL, displayed best bid/ask fill model, UTC day boundary.

**Schedule and health.** `CRYPTO_PAPER_ENABLED` and `CRYPTO_FEE_REFRESH_ENABLED` both default false. With paper on and collection on, `mcp:crypto-paper --once --scheduled` replaces standalone collection every five minutes, running in the background. It collects, then evaluates every operator-owned crypto sleeve. The `crypto_paper` health check reports:

| Status | When |
|---|---|
| `disabled` | The paper switch is off |
| `critical` | No v3 cycle in 15 minutes |
| `warning` | Refresh is on and no complete fee observation exists from the last 24 hours |
| `ok` | Otherwise |

**Migration `000035` (additive, forward-only).**
- `mcp_paper_sim_orders.policy_application_id` becomes nullable, with a new `instrument_policy_id` FK. A PostgreSQL check requires exactly one of the two.
- Order and position symbols widen to 24 characters.
- `mcp_crypto_fee_observations` is created with a state check.
- Existing rows are unchanged; no backfill.

## Behavior not changed

- Equity paper, live execution, watchlists, legacy crypto and AI paths are unchanged.
- Equity live and paper exits call the same `exitContext`. Its earlier-signal scan is now bounded to signals recorded since one day before the episode entry. Signals before the entry cannot describe the episode; the margin tolerates broker clock skew.
- No credential is sent by the paper cycle. The fee refresh uses the existing fixed GET and resolver. No LLM call, no network in evaluation, no venue client.

## Verification

All 840 tests run in each suite:

| Suite | Passed | Skipped | Assertions |
|---|---|---|---|
| SQLite | 728 | 112 PostgreSQL-only | 5,080 |
| Guarded PostgreSQL `mcp_trading_test` | 839 | 1 existing environment skip | 5,990 |

Composer validation, changed-file Pint and `git diff --check` passed. No frontend assets changed.

New tests (`CryptoPaperLifecycleTest`, `CryptoPaperPostgresTest`) cover:
- an entry filled at ask plus slippage with taker-rate fees, no broker order and no venue request
- frozen retries
- a partial fill continuing only on a newer quote, then expiry releasing the reservation
- an initial-stop exit with realized loss, then re-entry cooldown
- the fourth entry refused at three positions
- the daily loss pause latching for the UTC day and resetting at the next boundary
- a policy change cancelling an open order
- tier-review fee derivation: tier mismatch, failed reads, excluded pairs, one-day age, cost-plus refusal
- review validation
- scheduled refresh: recording, redaction, skip windows and failure handling
- a held product removed from the list staying collected and exit-evaluated
- the default-off command, schedule and health switches
- evidence-only mode
- three concurrent PostgreSQL workers committing one cycle, one order and an exact 18-decimal cash balance
- both new check constraints

The two updated C2 tests reflect v3: history moved to the profile, and unknown holding marks replace the old flat-ledger-only rule.

SQLite stores decimal columns as binary floats, so the SQLite feature test compares cash within 10⁻⁹. The PostgreSQL test asserts the exact value.

## Deployment steps

The runtime is on `656fc2f` with crypto paper policy v1 under contract v2, so the scheduled cycle refuses it until step 4.

1. Commit. Take and restore-drill an encrypted backup. Review `000035` with `deploy/macos/migrate.php --pretend`. Deploy with `deploy.sh <rev> --migrate`.
2. Write a private (`0600`) fee review JSON. Use tier `Intro`, the 27 exception pairs from the [October 26 fee page](CRYPTO-C2-RULES-EVIDENCE.md#owner-supplied-signed-in-fee-page--october-26), `product_all_in`, `excluded_by_owner_direction`, the review time and the source.
3. Run `mcp:crypto-fees --actor=1 --record` once: one read-only GET that stores an observation.
4. Run `mcp:crypto-policy --actor=1 --request=<new uuid> --base=1 --fee-review=<file>` to create policy version 2. The opening cash is not credited again.
5. Set `CRYPTO_PAPER_ENABLED=true` and `CRYPTO_FEE_REFRESH_ENABLED=true`. Run `optimize` and `queue:restart`, and restart FPM.
6. Confirm `schedule:list` shows `mcp:crypto-paper` and `mcp:crypto-fees --scheduled` and no standalone `mcp:crypto-market`. Confirm the first scheduled cycle completes and `crypto_paper` is `ok`.

Disable by setting `CRYPTO_PAPER_ENABLED=false` and recaching. Open simulated orders then remain until a later cycle expires them; all history is kept.

## Remaining C3 work

- Candle replay over stored history, labeled separately from forward results.
- A crypto section on the paper dashboard, separate from equities and anything broker-confirmed.
- Reports after costs: drawdown, hit rate, turnover and unavailable evaluations, against buy-and-hold.
- The forward observation period: at least 4 weeks and 30 closed trades.

No profitability is claimed.

## Deployment and activation — October 26

The owner committed the increment as `b54a6e4`. A first deploy at 23:45:55 UTC checked out the older `672a34b`, so for about 90 seconds the runtime ran code without automatic equities execution. The market was closed and no order was involved. The owner redeployed `b54a6e4` at 23:47:20 UTC.

Verified after the redeploy:
- Runtime revision `b54a6e4`; `000034` and `000035` applied (batch 19); readiness 200.
- `mcp:live-cycle` is back on the schedule. The equities activation is intact: enabled (v6), buys not paused, no emergency disable, no blocking reason. All health checks are ok; `automatic_trading` reports disabled only outside the session.

Activation steps performed:
1. Wrote private fee review `runtime/crypto-fee-review-20261026.json` (`0600`): tier `Intro`, the 27 exception pairs, `product_all_in`, `excluded_by_owner_direction`.
2. Ran `mcp:crypto-fees --actor=1 --record`. It made one read-only GET and stored observation 1: `complete`, `Intro`, 0.005 / 0.009, the two accepted reasons. The response hash `2e58aac1…` is identical to the October 23 read.
3. Applied policy version 2 (policy ID 2, contract v3) to portfolio 2. Still one cash entry and $400 cash: no re-credit.
4. Backed up the runtime `.env` (`env-before-crypto-c3-*`) and set `CRYPTO_PAPER_ENABLED=true` and `CRYPTO_FEE_REFRESH_ENABLED=true`. Ran `optimize` and `queue:restart` and restarted FPM. Readiness 200.
5. Confirmed the schedule:
   - `mcp:crypto-paper --once --scheduled` every five minutes, in the background
   - `mcp:crypto-fees --scheduled` hourly at minute 7
   - `mcp:live-cycle` retained
   - no standalone `mcp:crypto-market`
   - both crypto write flags false, `BROKER_MODE=paper`

**First scheduled v3 cycle.** Cycle 271 ran for the 23:50 UTC window and was recorded at 23:50:22. Equity was 400, the baseline was complete and there was no entry pause. No order was placed:
- 16 products: `quote_volume_too_low`.
- 9 products: `candle_conflict_requires_review`.

## Candle settlement correction (deployed in `0ec26f5`)

The nine conflicts were not daily-history problems.
- **What triggered them.** Each collection re-reads the trailing two hours of five-minute candles. Coinbase revises recently closed five-minute candles as late trades settle. A public comparison at 23:5x UTC found revised close, volume, high or low (never open) in 18 of 25 products. For example, AAVE-USD 23:10 was first stored with volume 0.262; the venue later reported 4.776.
- **Why every product was blocked.** The collector counted every difference on the read, and the eligibility check blocked a product whenever any read containing that product or the benchmark had a count. Every read contains BTC-USD, so any five-minute revision on any product latched every product indefinitely.
- **Not a C3 regression.** The C2 manual evaluation at 22:4x passed only because it ran before the second read.

The correction:
- **Settlement delay.** The collector stores five-minute and daily candles only 15 minutes after they close (`crypto.candle_settlement_minutes`), so the first stored observation is near-final. Yesterday's daily candle and the 00:00 UTC baseline candle become available at about 00:15–00:20 UTC. Until then, entries are unavailable; exits are unaffected.
- **Revision records.** Each later differing observation is recorded once in a new table, `mcp_crypto_candle_revisions` (read, original candle ID, product, granularity, observed values, fingerprint; unique per candle and observation). It is never applied. The read-level `candle_conflicts` count is unchanged.
- **Scoped block.** Eligibility now blocks a product only when a daily candle revision exists for that product or the benchmark within its 200-day history window, recorded by a read finished at the cutoff. Five-minute revisions and other products' revisions no longer block. Reads 2–14 counted five-minute revisions only: daily candles were current and not re-read after read 1. Historical read rows are not rewritten.

Migration `000036` is additive (one new table) with a plain `down()`. Verification:

| Suite | Tests | Passed | Skipped | Assertions |
|---|---|---|---|---|
| SQLite | 840 | 728 | 112 PostgreSQL-only | 5,084 |
| PostgreSQL | 840 | 839 | 1 existing environment skip | 5,994 |

Collector tests now cover settlement and revision deduplication. The evaluation tests show that five-minute and other-product daily revisions do not block, while a benchmark daily revision does. Pint and `git diff --check` passed.

To deploy: commit, then `deploy.sh <rev> --migrate` with the private migration credentials (review with `--pretend` first). No configuration change is needed. The first cycle after about 00:20 UTC should show eligible products evaluated normally.

**Deployment follow-up, October 26–27.** The owner committed and deployed `0ec26f5` at 23:58:37 UTC. Migration `000036` applied in batch 20. Readiness 200; schedule unchanged; `crypto_paper` and `crypto_data` ok; both crypto write flags false.
- **Cycle 273 (00:00 UTC):** 16 products `quote_volume_too_low` and 9 `daily_history_missing_or_discontinuous`, as expected while October 26's daily candle settled. No conflict reason. 49 recorded revisions, all five-minute.
- **Cycle 276 (00:15 UTC):** the nine liquid products were evaluated normally. BTC, NEAR and ZEC were `wait/trigger_not_crossed`; ETH, HYPE, QNT, SOL, SUI and XRP were `wait/no_setup`. 16 products were low volume. Equity was 400, the baseline was complete and there was no pause. No order (none was warranted). One fee observation: the hourly refresh correctly skipped a recent observation. No daily revisions.

## Replay and dashboard increment (deployed in `b68b847`)

**Replay engine.** The shared `StrategyReplay` gains a `crypto_limit` order mode instead of a second engine. Equity modes are unchanged; existing replay, experiment and variant tests pass. Differences in crypto mode:

| Setting | Equity modes | Crypto mode |
|---|---|---|
| Profile window | 275 bars | The live 200 bars; replay starts only once a full window exists |
| Price through the trigger | One cent | One price increment |
| Fill rounding | 4 decimals | Entries round up and exits round down to each product's price increment |
| Sizing | Share-based | The instrument-aware `RiskSizer` path (base increments and venue minimums/maximums) |
| Fees | Equity schedule | Venue notional fees at the greater rate |
| Liquidity | Not modeled | Entry needs the prior day's base volume × close of at least $10M, approximating the live 24-hour quote-volume rule |

Crypto results list their limitations:
- daily bars approximate five-minute triggers and stops
- today's collection list, increments and fee tier are applied to the past
- no spread, quote-age or book-size data
- no daily loss pause
- exits do not free cash or capacity until the next day

**`CryptoReplays` and `mcp:crypto-replay --actor --portfolio --request [--start]`.** The replay runs the current policy over stored daily candles. It uses only settled candles from successful reads, contiguous from each product's first stored day to yesterday (UTC). It excludes, with a stated reason, any product with:
- a gap
- any daily revision
- no product metadata
- no current fee schedule
- a fee schedule different from the benchmark's

It requires the benchmark. Runs use the existing `mcp_strategy_replays` table with crypto identity and are labeled `retrospective_daily_replay_not_forward_results`. Retries are frozen per request UUID, and a replay makes no network call, order or ledger change.

**`mcp:crypto-history --days=N` (1–2000, default 1095).** One public read of settled daily candles before each configured product's earliest stored day. It is recorded as a `backfill` read with provenance; any overlap follows the first-observation and revision rules. Backfill reads do not count toward collection health.

**Dashboard (`/mcp/paper/crypto`, nav "Crypto Paper").** An authenticated, operator-scoped, read-only page, using a repeatable-read snapshot on PostgreSQL. It shows:
- switches, with venue writes shown explicitly
- current equity, cash, reserved cash, realized and open P&L, fees and return
- maximum drawdown and a UTC daily-close equity curve rebuilt from the ledger and stored daily closes, with unknown days left blank
- observation progress (closed trades out of 30, days out of 28), hit rate and profit factor
- buy-and-hold since the sleeve opened, for BTC and for an equal weight of the list
- 24-hour cycle coverage and waiting reasons
- open positions with bid marks and recorded stops
- the latest 25 simulated orders with fills and fees
- closed round trips net of every fee
- the latest cycle per product
- the fee tier and review
- the latest replay, labeled retrospective

The `x-money` component accepts an optional `scale` (default 2, so existing pages are unchanged) for small crypto prices.

**Migration `000037` (forward-only).**
- `mcp_strategy_replays.mcp_connection_id` becomes nullable, with new `paper_portfolio_id`, `asset_class` and `venue` columns. Existing rows default to equity / Robinhood agentic.
- A partial unique index covers crypto requests, and a PostgreSQL identity check requires equity rows to have a connection and crypto rows to have a portfolio and no connection.
- The crypto read check constraint now also accepts the `backfill` trigger.

**Verification.**

| Suite | Tests | Passed | Skipped | Assertions |
|---|---|---|---|---|
| SQLite | 848 | 736 | 112 PostgreSQL-only | 5,139 |
| PostgreSQL | 848 | 847 | 1 existing environment skip | 6,053 |

Composer validation, Pint and `git diff --check` passed. No CSS or JS changed, so no asset rebuild is required.

New tests cover:
- crypto-mode window, tick-rounded fills, increment sizing, tick-rounded stop exits with fees, and the liquidity gate
- a labeled replay with stored-data-only frozen retries and no ledger effect
- each exclusion reason and the benchmark requirement
- backfill extent, provenance and health exclusion
- the replay command
- the dashboard's content after a real entry and stop-out: exact closed-trade net, no writes, no venue requests, authentication, empty state and operator gate
- the PostgreSQL replay identity and read-trigger constraints

**To deploy:**
1. Commit, then run `deploy.sh <rev> --migrate` (review `000037` with `--pretend`).
2. Run `mcp:crypto-history --days=1095`.
3. Run `mcp:crypto-replay --actor=1 --portfolio=2 --request=<uuid>`.
4. Open `/mcp/paper/crypto`.

### Deployment and first replay — October 27

Deployed `b68b847` with migration `000037` (batch 21). Readiness 200; schedule unchanged; all health checks ok.

**Backfill.** At 00:35:27 UTC, after that window's cycle, `mcp:crypto-history --days=1095` completed for all 25 products. It inserted 15,402 settled daily candles in 17 seconds (read 24). Products listed less than three years ago have shorter history.

**Replay 1.** `mcp:crypto-replay --actor=1 --portfolio=2`, request `1372958e-485b-4b35-8c41-ebfe2837ac63`. Policy v2: Intro fees at the reserved 0.90% per side, 10 bp slippage, $400, 5% risk, 25% cap, three positions. Status `complete`, nothing excluded. 895 days, 2024-05-15 → 2026-10-26.

| Measure | Replay | Comparison |
|---|---|---|
| Final equity | $308.54 (−22.87%) | BTC buy-and-hold +30.01%; equal weight of today's list +218.33% (strongly inflated by selecting coins on today's volume) |
| Max drawdown | 48.24% | — |
| Closed trades | 82 (17 wins / 65 losses, 20.73%) | — |
| Average win / loss | +27.63% / −8.24% | — |
| Profit factor | 0.79 | — |
| Time invested | 44.92% of days | — |

**Breakdown** (developer analysis of the stored trades; fees recomputed at the reserved 0.90% per side):
- **Fees decide the sign.** Fees were about $116.79 against a −$91.47 net, so the strategy was roughly +$25 before fees over 2.5 years.
- **The fast-moving-average exit is the main loss source.** 56 exits on `daily_close_below_fast_sma` lost $316.67. The 20 stop exits (initial or trailing) gained $255.06. 5 time exits lost $18.23; 1 assumed entry-day stop lost $11.63.
- **Capacity bound often.** The three-position cap skipped 871 setups. Average holding was 10.9 days.

These are retrospective research findings with the stated limitations. They are not a basis for parameter changes without owner review and out-of-sample checks. Forward paper continues unchanged under policy v2.

## Research comparison (deployed)

`mcp:crypto-replay … --variants=<json>` accepts up to six research variants. Each variant may set:
- **`parameters`:** the existing reviewed `ResearchVariants` bounds, e.g. `exit_on_fast_sma_close: false`.
- **`risk`:** the reviewed bounds for open positions, position cap and risk per trade.
- **`costs`:** both fee rates as quoted decimals, from 0 to 0.02 per side. Sizing still reserves the greater rate.

Malformed, out-of-bounds, baseline-equivalent and duplicate variants are refused.

The baseline and every variant run over the full period and over two independent halves. The halves are split at the midpoint, and each starts from $400 with earlier bars only warming profiles. A change that helps should help in both halves, not only in aggregate. Each summary records:
- return, drawdown, trades, hit rate and profit factor
- buy-and-hold benchmarks
- skip counts
- the fees charged on its trades, recomputed with the same cost function
- the exit-reason breakdown

Variants live only in the replay record (adapter `crypto_daily_replay_v2`). They never change the policy, ledger or forward cycle; applying a change requires a new owner-reviewed policy version.

No migration. The full suites pass with 850 tests each:

| Suite | Passed | Skipped | Assertions |
|---|---|---|---|
| SQLite | 738 | 112 PostgreSQL-only | 5,162 |
| PostgreSQL | 849 | 1 existing environment skip | 6,076 |

Planned comparison set:

| ID | Change | Question |
|---|---|---|
| `no_fast_sma_exit` | `exit_on_fast_sma_close: false` | Is the moving-average exit the main loss source? |
| `maker_fees` | 0.50% per side | What if limit orders fill as maker? Assumes every fill is maker. |
| `five_positions` | Five positions, 20% cap | Is the three-position cap costing setups? |
| `zero_fees` | 0% per side | Commission-free sensitivity. A venue's spread markup is not modeled. |
| `no_exit_maker` | First two combined | Do the two fixes add up? |
| `no_exit_maker_five` | All three | All three changes together |

### Comparison results — October 27 (replay 2)

Request `84fc9317-9416-4533-84e1-9d10e615f2b8`, variant file `runtime/crypto-replay-variants-20261027.json`.

| Window | Dates | BTC buy-and-hold |
|---|---|---|
| Full | 2024-05-15 → 2026-10-26 | +30.0% |
| Early | 2024-05-15 → 2025-08-05 | +85.4% |
| Late | 2025-08-06 → 2026-10-26 | −29.9% |

Return % (profit factor) for each window, then full-period fees:

| Run | Full | Early | Late | Fees (full) |
|---|---|---|---|---|
| Baseline (policy v2) | −22.9 (0.79) | −20.1 (0.71) | +1.2 (1.03) | $116.79 |
| `no_fast_sma_exit` | −41.0 (0.61) | −33.7 (0.56) | −10.4 (0.73) | $75.11 |
| `maker_fees` (0.50%) | −11.4 (0.89) | −14.2 (0.78) | +8.6 (1.21) | $69.83 |
| `five_positions` | −42.2 (0.68) | −34.6 (0.64) | −15.5 (0.71) | $129.05 |
| `zero_fees` | +6.2 (1.06) | −5.7 (0.88) | +19.0 (1.50) | $0 |
| `no_exit_maker` | −35.6 (0.66) | −30.3 (0.59) | −7.1 (0.81) | $43.95 |
| `no_exit_maker_five` | −45.4 (0.65) | −35.2 (0.64) | −17.8 (0.61) | $51.18 |

Findings:
1. **Removing the fast moving-average exit makes results worse in both halves.** The replay-1 exit-reason attribution (that exit "lost" $316.67) was misleading: it cuts losers early, and without it they lose more. The developer hypothesis is withdrawn.
2. **Five positions is worse in both halves.** More concurrent entries add weaker setups and more fees.
3. **Lower fees are the only change that helps in both halves.** Even with zero fees the early half is negative, and over the full period the strategy trails BTC buy-and-hold by about 24 points.
4. **The market filter limited damage in the falling late half** (baseline +1.2% while BTC fell 29.9%), but the strategy captured little of the rising early half (−20.1% against +85.4%).

Conclusion: no tested variant meets the bar of helping and being profitable in both halves. Policy v2 is unchanged, and forward paper continues as an observation, not as a candidate for live crypto. Any different strategy design is an owner decision.

## Strategy redesign — pre-registration (recorded 2026-10-27 01:08 UTC, before any candidate result was computed)

The owner asked for a strategy that wins. The swing playbook failed its comparison above. This section fixes the candidates, settings and pass/fail rules before testing, so that settings are not tuned to the data. History was backfilled to 2,000 days (read 31: 14,784 more settled daily candles).

**Common assumptions.**
- $400 start; each decision is made on day *t*'s close and executed at day *t*+1's open.
- Intro taker fee of 0.90% per side plus 10 bp slippage. Maker 0.50% is reported as well, but pass/fail uses taker.
- Fractional quantities with a $1 minimum order; venue increments are ignored in this research prototype.
- Settled daily candles only.
- Prototype computation uses exact decimals in a scratch research script, not the application engine. Any candidate that passes is then implemented as a versioned playbook in the shared PHP engine, with tests, before any policy change.

**Candidates.** Settings are standard textbook values, not fitted:

| ID | Rule |
|---|---|
| T1 BTC trend | Hold 100% BTC while the prior close is above its 200-day simple moving average; otherwise hold cash |
| T2 BTC+ETH trend | Two 50% sleeves, each applying T1's rule to its own coin |
| T3 Momentum rotation | Every Monday (UTC), rank eligible list coins by 90-day return and hold the top 3 with a positive 90-day return, equal weight. Eligible means at least 200 days of history and prior-day volume × close of at least $10M. Hold only while BTC is above its 200-day SMA; otherwise cash. Only membership changes trade (no rebalancing trims). Known bias: today's list applied to the past. |
| T4 BTC trend, volatility target | T1, with the held fraction = min(1, 50% ÷ annualized 30-day realized volatility of BTC). The fraction is adjusted only when it differs from the current one by more than 20 percentage points. |

**Periods.** The common evaluation period starts on the first day with 200 days of BTC history in the stored data and ends yesterday. It is also split into three equal sub-periods.

**Pass rules.** All must hold, at taker fees:
1. Over the full period, the return-to-drawdown ratio (CAGR ÷ maximum drawdown) is higher than BTC buy-and-hold's.
2. In every sub-period, the maximum drawdown is lower than BTC buy-and-hold's.
3. The full-period net return is positive, and the net return is positive in at least two of the three sub-periods.

A candidate that passes is a forward-paper candidate, not a live strategy. If none passes, that is the result.

### Redesign results (computed after the pre-registration above)

Full period 2021-11-22 → 2026-10-26, split into three sub-periods:
- p1: 2021-11-22 → 2023-07-14, mostly bear.
- p2: 2023-07-15 → 2025-03-05, bull.
- p3: 2025-03-06 → 2026-10-26, choppy and falling.

Results at taker fees (0.90% per side plus 10 bp slippage). Return / max drawdown, in %:

| Strategy | Full | p1 | p2 | p3 | Full CAGR ÷ max DD | Trades | Fees |
|---|---|---|---|---|---|---|---|
| BTC buy-and-hold | +35.0 / 73.3 | −48.8 / 73.3 | +195.8 / 26.2 | −12.6 / 53.1 | 0.09 | 1 | $3.57 |
| T1 BTC trend | +73.6 / 37.6 | +4.8 / 30.1 | +95.7 / 31.9 | −17.0 / 26.3 | 0.31 | 39 | $198.44 |
| T2 BTC+ETH trend | +52.9 / 33.8 | +2.7 / 29.7 | +60.4 / 30.1 | −6.6 / 34.9 | 0.27 | 68 | $161.05 |
| T3 momentum top 3 | +59.7 / 48.2 | −24.4 / 42.0 | +179.7 / 44.2 | −17.1 / 35.0 | 0.21 | 265 | $377.50 |
| **T4 BTC trend, volatility target** | **+85.1 / 34.9** | **+6.0 / 25.6** | **+98.2 / 30.8** | **−11.7 / 23.3** | **0.38** | 45 | $190.96 |

**Every candidate fails the pre-registered rules.** All four pass rule 1 (better return-to-drawdown than BTC), and T1, T2 and T4 pass rule 3 (positive returns). All four fail rule 2 because in the p2 bull period their drawdowns (about 30–44%) exceeded BTC's 26.2%. The pre-registered verdict is "none passes"; the rules are not changed after the fact.

**Maker-fee sensitivity** (0.50% per side), full period:

| Strategy | Return % | Max DD % | Return ÷ drawdown |
|---|---|---|---|
| T4 | +113.4 | 32.2 | 0.52 |
| T1 | +103.0 | 35.0 | 0.44 |
| T3 | +112.8 | 47.0 | 0.35 |
| BTC buy-and-hold | +35.6 | 73.3 | 0.09 |

Same pass/fail outcome.

**Observations (not pass claims).**
- T4 roughly halved BTC's worst drawdown in every falling period and more than doubled its full-period return.
- In exchange, it captured about half of the 2023–25 bull run, with a somewhat deeper drawdown than BTC there.
- Fees consumed about $191 on a $400 sleeve over five years, so fee level remains material.
- T3 is the most fee-intensive and is biased by using today's list in the past.

The research script is the scratch tool `research.py`, run on an export of stored settled candles. No application, policy, ledger or schedule change was made.

## Strategy redesign round 2 — pre-registration (recorded 2026-10-27 01:13 UTC, before any round-2 result was computed)

The owner asked for a second round in addition to building T4. This is the last planned round: each extra round raises the chance of selecting a candidate that only fit this history by luck, and eight candidates have now been tried. The data, costs, periods and pass rules are identical to round 1 and are not changed. The candidates are classic trend designs with standard textbook settings, not tuned to round-1 results:

| ID | Rule |
|---|---|
| R1 Time-series momentum | Hold 100% BTC while its 365-day return (prior close vs the close 365 days earlier) is positive; otherwise cash |
| R2 Moving-average cross | Hold 100% BTC while its 50-day SMA is above its 200-day SMA; otherwise cash |
| R3 Donchian channel | Enter BTC when the prior close is the highest close of the last 55 days; exit when the prior close is the lowest of the last 20 days; otherwise keep the current state |
| R4 BTC+ETH volatility-targeted trend | Two independent 50% sleeves, each applying T4's rule (200-day SMA filter, 50% volatility target, 20-point adjustment band) to its own coin |

The full table of all eight candidates will be reported, including failures.

### Round 2 results

Same data, costs and periods as round 1 (taker fees). Return / max drawdown, in %:

| Strategy | Full | p1 | p2 | p3 | Full CAGR ÷ max DD | Trades | Fees |
|---|---|---|---|---|---|---|---|
| BTC buy-and-hold | +35.0 / 73.3 | −48.8 / 73.3 | +195.8 / 26.2 | −12.6 / 53.1 | 0.09 | 1 | $3.57 |
| **R1 365-day momentum** | **+239.6 / 28.7** | **+15.8 / 4.1** | **+195.8 / 26.2** | **−2.8 / 28.7** | **0.98** | **4** | **$40.80** |
| R2 50/200 cross | +25.7 / 41.8 | −7.0 / 37.7 | +85.5 / 26.1 | −28.6 / 31.3 | 0.11 | 11 | $44.00 |
| R3 Donchian 55/20 | +31.8 / 43.4 | −14.5 / 25.0 | +118.7 / 21.2 | −27.7 / 38.8 | 0.13 | 38 | $182.51 |
| R4 BTC+ETH volatility target | +65.8 / 29.2 | +6.8 / 23.5 | +61.8 / 29.2 | −2.1 / 29.6 | 0.37 | 88 | $158.77 |

**Every round-2 candidate also fails.**
- **R2 and R3** pass rule 2 but fail rule 3: negative in two thirds.
- **R1 and R4** fail rule 2. R1 fails by an exact tie: it held BTC for the whole p2 bull period, so its drawdown equals BTC's 26.2%. A rule requiring a strictly lower drawdown cannot be met by any strategy that is fully invested for a whole period.
- **Verdict:** under the pre-registered rules, none of the eight candidates passes.

**Observations (not pass claims).**
- R1 has the best full-period result of all eight: about seven times BTC's return-to-drawdown ratio, a worst loss of 4% in the crash third, and low fees.
- R1's evidence rests on only 4 trades in five years. Its result depends on a handful of timing decisions, so it is the most exposed to luck.
- T4 makes ten times as many decisions, with a smaller edge.
- Both should be judged on forward paper, not on this history.

## Regime playbooks in the application (deployed in `73c244e`)

At the owner's request, T4 was built into the application, together with R1, the strongest round-2 result. They share one playbook family; the applied policy selects the version. No new migration.

**Rules (`RegimeRules`, `regime_rules_v1`).**
- Pure and exact-decimal.
- Two signals: `sma_above` (prior close above its N-day average) and `return_positive` (prior close above the close N days earlier).
- An optional volatility target: held fraction = min(1, target ÷ annualized volatility of 30 daily log returns). Log returns use a 40-digit atanh series; the square root comes from brick/math. No binary floats are used.
- `rebalance()` enters from flat, exits to flat, and otherwise trades only outside the band.
- `needed()` states the history required: N closes for an average, N + 1 for a return, window + 1 for volatility.

**Playbook versions (`CryptoParameters::named`).**

| Version | Research ID | Rule |
|---|---|---|
| `crypto_regime_trend_v1` | T4 | BTC, 200-day average, 50% volatility target, 20-point band |
| `crypto_regime_momentum_v1` | R1 | BTC, 365-day return, fully held, 100-point band (enter and exit only) |

The swing version and its hash are unchanged.

**Policy contract `crypto_paper_policy_v4`.**
- `mcp:crypto-policy … --playbook=<version>` records the playbook. Contract-v3 swing policies remain valid.
- Regime policies carry `REGIME_OWNER`: $400, a **100% position cap and one position** (the swing limits were 25% and three), and the same 5% daily loss pause.
- `CryptoPolicies::compatible()` requires the contract, fingerprint, the code's playbook definition, matching sleeve limits and execution settings.

**Live 24/7 cycle.** Regime policies evaluate only the playbook product plus any holdings:
- The target comes from yesterday's settled close. The current fraction is the bid-marked holding ÷ marked equity.
- One rebalance per UTC day, retried if an order expires unfilled.
- Buys pass liquidity, fee, valuation, daily-loss-pause and open-order gates.
- An exit to cash is never held back by those gates or by the once-per-day limit.
- A holding outside the playbook, such as a leftover swing position, is sold.
- Sizing moves the marked value to target × equity, capped by the position limit and unreserved cash, reserving the greater fee rate. Quantities round down to the venue increment.
- Signals are immutable `regime_rules_v1` evidence with no symbol profile.

**Replay.**
- `StrategyReplay::regime()` replays the same rules at each day's open with venue ticks and increments.
- `mcp:crypto-replay … --playbook=<version>` replays any playbook for research without changing the applied policy.
- Regime replay variants may change only fees.

**Validation against the research.** The application replay was run on the same exported candles as the research script:
- **First run:** it diverged in the first trend-design sub-period. The cause was an off-by-one in the application: it required 201 closes for a 200-day average, which skipped the 2021-11-22 entry.
- **Fix:** `RegimeRules::needed()` and T4's `min_history_days` = 200.
- **After the fix:** every period of both playbooks at both fee levels matches.

| Playbook | Full return % | Max drawdown % | Executions | Fees |
|---|---|---|---|---|
| T4 at taker fees | +85.13 | 34.93 | 45 | $190.96 |
| R1 at taker fees | +239.60 | 28.69 | 4 | $40.80 |

The one visible difference is T4's p1 drawdown, 25.87% against 25.6% in the research, from rounding to the $0.01 price tick.

**Verification.**

| Suite | Tests | Passed | Skipped | Assertions |
|---|---|---|---|---|
| SQLite | 858 | 746 | 112 PostgreSQL-only | 5,212 |
| PostgreSQL | 858 | 857 | 1 existing environment skip | 6,126 |

Pint and `git diff --check` passed. New tests cover:
- the exact volatility against a 50-digit independent reference (17 digits agree)
- the history needs of each signal, band behavior and enter/exit-only momentum
- live entry to target, holding inside the band and on the same day, and an exit to cash on a trend break
- selling a holding outside the playbook, and the loss pause blocking buys but not sells
- playbook validation and compatibility of contract-v3 swing policies
- a research replay of another playbook leaving the applied policy unchanged, and the fee-only variant rule

**Owner decision before activation.** Applying either regime policy changes the paper sleeve's limits from a 25% cap and three positions to a 100% cap and one position. It ends observation of the swing playbook, which its replays already disqualified. On the dashboard, regime playbooks are observed by days rather than a 30-trade target, since they trade rarely.

### Deployment, replays and activation — October 27

The owner deployed `73c244e` (no migration).

**Replays recorded on runtime data**, both research-only with no policy change:
- **Replay 3 (T4).** 2021-11-22 → 2026-10-26: +85.13%, max drawdown 34.93%, $190.96 fees. At maker fees: +113.43%, drawdown 32.21%. BTC buy-and-hold over the same period: +36.37% (this column uses the venue open/close convention).
- **Replay 4 (R1).** Starts 2022-05-07, after its 366-day history requirement, to 2026-10-26: +239.60%, max drawdown 28.69%, $40.80 fees. BTC over that window: +122.25%.

**Activation.** The owner chose T4. At 01:43 UTC policy version 3 (policy ID 3, contract v4, `crypto_regime_trend_v1`) was applied with the existing fee review. Limits are now a 100% cap, one position and a 5% daily loss pause. Still one cash entry and $400; no positions or open orders at activation.

**First T4 cycle (294, 01:45 UTC).**
- **Inputs.** Yesterday's close $80,008.79 against a 200-day average of $72,985.07: in trend. Annualized 30-day volatility 25.07%, so the target fraction is 1.
- **Order.** `regime_entry` BUY, simulated limit order 5 at a $80,229.17 bound.
- **Fill.** 0.00494124 BTC at $80,229.17 (ask plus 10 bp), fee $3.57 (0.90%). Remaining cash $0.0005.
- **State.** No pause; baseline complete; `crypto_data` and `crypto_paper` ok. No broker order.

## T4 robustness study — pre-registration (recorded 2026-10-27 02:19 UTC, before results)

The owner prefers deeper backtesting to weeks of forward-paper observation. These tests examine the one fixed strategy, T4 (`crypto_regime_trend_v1`). They do not select among new candidates, and no setting is changed by them. Costs remain 0.90% per side plus 10 bp slippage, with decisions on the prior close and execution at the open.

**Data.** Coinbase public daily candles from each product's earliest available day. Fetched read-only and without credentials into the research scratch area. Before any use by the application, the data would be re-collected through the application backfill.

**Tests.**
1. **Long history.** T4 against BTC buy-and-hold over the full available BTC history, reported by calendar year.
2. **Rolling start dates.** Every 1-year and 3-year window starting on the first of each month: the share with a positive return, the share beating BTC on return ÷ max drawdown, and median and worst outcomes.
3. **Nearby settings.** Average 150, 200 or 250 days × volatility target 40, 50 or 60% × band 10, 20 or 30 points: 27 combinations, each against buy-and-hold on the full history.
4. **Other coins.** T4's unchanged rule applied to each coin with at least 3 years of Coinbase history, against that coin's buy-and-hold.
5. **Block bootstrap.** 2,000 synthetic 3-year paths of BTC daily returns, resampled in 30-day blocks; T4 against buy-and-hold on each path.

**T4 counts as robust if all of these hold.**
- (a) Over the full history, T4's return ÷ max drawdown beats BTC buy-and-hold's.
- (b) At least 70% of rolling 3-year windows beat BTC on return ÷ max drawdown, and at least 70% have a positive return.
- (c) At least 20 of the 27 nearby settings beat buy-and-hold on return ÷ max drawdown over the full history.
- (d) T4 beats buy-and-hold on return ÷ max drawdown for a majority of the other coins.
- (e) In the bootstrap, T4's median max drawdown is lower than buy-and-hold's, and T4's return ÷ max drawdown beats buy-and-hold's on more than 60% of paths.

### T4 robustness results

**Data.** Coinbase public daily candles, BTC from 2015-07-20.
- A first fetch had one missing day per 350-day page. The pages requested 351 inclusive candles against the API's 350 maximum. It was refetched with 349-day pages.
- BTC is gap-free, and its 2,000 overlapping days match the application-stored settled candles exactly.
- Genuine gaps (BCH's 2018 halt, the first ETH and LTC trading days) are excluded by using each coin's gap-free tail.
- **Sanity check:** the float research simulation reproduces the exact-decimal application replay for 2021-11-22 → 2026-10-26: +85.1% return, 34.9% drawdown.

**1. Long history.** BTC 2016-02-05 → 2026-10-26, 10.7 years:

| | Return | CAGR | Max drawdown | Return ÷ drawdown | Trades |
|---|---|---|---|---|---|
| T4 | +6,312% | 47.4% | 57.8% | 0.82 | 108 |
| Hold | +20,317% | 64.2% | 83.8% | 0.77 | 1 |

T4 trailed holding in every strong-up year: 2017, 2019, 2020, 2021, 2023, 2024. It protected capital in 2018 (−25.6% vs −73.4%) and 2022 (0.0% vs −64.2%), and it also lost more than holding in 2025.

**2. Rolling start dates.**

| Windows | Count | T4 positive | Beats hold on return ÷ drawdown | Beats hold on return | T4 worst | Hold worst |
|---|---|---|---|---|---|---|
| 3-year | 92 | 100% | 49% | 16% | +7.1% | +9.4% |
| 1-year | 116 | 67% | 39% | 29% | — | — |

Median max drawdown over 3-year windows: 48.6% for T4, 76.7% for hold.

**3. Nearby settings.** 20 of 27 beat hold: all 9 with a 150-day average, 4 of 9 with 200 days, 2 of 9 with 250 days. The chosen 200-day setting sits near the edge. Switching to 150 days after seeing this would be fitting to the data and is not proposed.

**4. Other coins.** T4 beats each coin's own buy-and-hold on 9 of 19.

**5. Block bootstrap** (2,000 paths of 3 years). T4 beats hold on return ÷ drawdown in 21.3% of paths. Median max drawdown is 50.1% for T4 against 59.7% for hold. T4 lost money in 23.7% of paths, hold in 11.4%. Thirty-day blocks break up multi-month trends, which biases this test against trend following; that was known when it was pre-registered, and the result stands.

**Pre-registered verdict.** Checks (a) and (c) pass; (b), (d) and (e) fail. **T4 is not robust under the stated rules.**

**Interpretation.**
- The earlier 2021–2026 window began at the November 2021 peak, which flattered trend following.
- Over the longer history, T4 is a lower-drawdown way to hold BTC (worst drop 58% against 84%; no losing 3-year window), not a way to beat it on return. It gave up about 17 points of compound annual return.
- The T4 forward paper sleeve continues unchanged.

## Hold/T4 blends and venue cost study (October 27)

### Blends

Stated before computing:
- 50/50 and 70/30 BTC buy-and-hold/T4, set once at the start.
- 50/50 rebalanced on the first of each month, with the 0.90% fee and 10 bp slippage charged on both sides of the amount moved.

BTC history 2016-02-05 → 2026-10-26, the same float research simulation as the robustness study (`blend.py`):

| | CAGR | Worst drop | Return ÷ drop | 3-year median return | Worst 3-year return | 3-year median worst drop |
|---|---|---|---|---|---|---|
| Hold | 64.2% | 83.8% | 0.77 | 227% | +9% | 76.7% |
| T4 | 47.4% | 57.8% | 0.82 | 118% | +7% | 48.6% |
| 50/50 set once | 57.9% | 70.6% | 0.82 | 179% | +13% | 62.0% |
| 70/30 set once | 60.7% | 77.1% | 0.79 | 199% | +11% | 68.2% |
| **50/50 monthly rebalance** | **58.7%** | **65.5%** | **0.90** | **185%** | **+21%** | **60.3%** |

These are research observations, not a pass under the earlier pre-registered rules. No policy was changed.

### Venue cost study (source, pending deployment)

The owner holds a separate Robinhood Crypto account and asked whether moving to it would help. This increment adds a measurement, not a trading path:

- **`RobinhoodCryptoQuotes`.** One fixed signed GET of Robinhood Crypto best bid/ask, including Robinhood's spread, for up to 10 symbols.
  - It reuses the existing signer and credential resolver.
  - Fixed host and path; no retries or redirects; exact decimal parsing.
  - Bad responses are refused with a generic error, never a provider body or signed request.
  - No order path uses it.
- **`CryptoSpreadStudy` and `mcp:crypto-spreads --once`.** Each run pairs those quotes with Coinbase's live best bid/ask for the same products. It appends one line to the private file `storage/app/private/crypto-spread-study.jsonl` (`0600`). Costs per side, in basis points of the Coinbase mid:

| Field | Meaning |
|---|---|
| Robinhood buy | Robinhood ask − Coinbase mid |
| Robinhood sell | Coinbase mid − Robinhood bid |
| Coinbase taker | Half the spread plus the observed taker fee |
| Coinbase maker | Observed maker fee minus half the spread, assuming a fill |

- **Schedule.** `CRYPTO_SPREAD_STUDY_ENABLED` defaults off. When on, it samples every five minutes, at minute 3 of each cycle. Default symbols: BTC, ETH, SOL, XRP, DOGE, LTC.

**Verification.** 861 tests in each suite, all passing apart from the existing skips:

| Suite | Passed | Skipped | Assertions |
|---|---|---|---|
| SQLite | 749 | 112 PostgreSQL-only | 5,233 |
| PostgreSQL | 860 | 1 existing environment skip | 6,147 |

Tests cover the exact signed request and query, the basis-point arithmetic, file permissions and redaction, generic failure handling for errors and unreviewed or crossed quotes, and the default-off schedule.
