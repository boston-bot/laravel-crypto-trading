# Crypto C3: 24/7 paper lifecycle (first increment)

Status: **source only, October 26, 2026; not committed or deployed.** This increment turns C2's evidence-only evaluation into a scheduled 24/7 paper cycle with simulated limit orders, fills, exits and portfolio limits, plus a refreshed account fee tier. Candle replay and the crypto dashboard section are the remaining C3 build items. It authorizes no venue order: both crypto write flags stay false and no code path reaches a venue `placeOrder`.

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

## Deployment (owner action, not performed)

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
