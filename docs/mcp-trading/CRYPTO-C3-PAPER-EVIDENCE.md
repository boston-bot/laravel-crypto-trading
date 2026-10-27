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

## Replay and dashboard increment (source, pending deployment)

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
