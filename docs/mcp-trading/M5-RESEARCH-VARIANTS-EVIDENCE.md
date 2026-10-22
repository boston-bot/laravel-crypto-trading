# M5 research variants: fractional market orders and rule switches

October 21, 2026. Owner decisions recorded today:
- **Fractional shares.** Robinhood allows fractional quantities only on regular-hours market orders ([contract review](M5-REPLAY-FRACTIONAL-CADENCE-EVIDENCE.md#fractional-order-contract)), so this means guarded fractional market orders.
- **Bounded strategy variants.** The levers the earlier replay pointed at, beyond stop width, become testable.

This increment adds research support for both and runs the registered comparison [DAILY-003](STRATEGY-EXPERIMENTS.md). It changes no live parameter, owner setting, forward paper path, deployment or runtime configuration, and places no order.

## What changed

- **`ResearchVariants`** is the single place for research bounds. Both the daily replay and the Experiments page use it.

  | Kind | Keys and bounds |
  |---|---|
  | Decimals | `initial_stop_atr` 1–5, `trailing_stop_atr` 1–8, `max_chase_atr` 0.1–2 |
  | Session counts | `time_exit_sessions` 1–60, `cooldown_sessions` 1–20, new `market_filter_sma_sessions` 50–200 |
  | On/off switches (new) | `exit_on_fast_sma_close`, `market_filter_required` |

  Owner risk limits (risk per trade, maximum position, open positions) cannot be varied.
- **`SignalRules`:**
  - `exit_on_fast_sma_close=false` turns off the close-below-50-day exit. Stops, time exits and the earnings exit still apply.
  - `marketFilterBlocks()` lets `market_filter_required=false` stop the SPY filter from blocking entries; the market state is still recorded.
  - Live parameters never carry either key, so live signals are unchanged; `swing_signals_v1` output is identical for every existing parameter set.
- **`RiskSizer`** takes an optional quantity scale, used only by the fractional market mode:
  - The scale is 0 (whole shares, the default and live behavior) or up to the broker's 6 decimals. Every cap rounds down.
  - A fractional size below the $1.00 minimum order value is refused as `below_fractional_minimum_order_value`.
  - Whole-share output is byte-identical to before.
- **`StrategyReplay` (`daily_replay_v3`)** takes `order_mode=fractional_market`:
  - Sizing goes to 6 decimals.
  - Entries, exits and planned risk use a separate `market_slippage_bps` (default 5 bp, bounds 1–50). That is five times the limit-order assumption, because market orders give up price control.
  - Results carry the order mode, and the limitations name the slippage assumption and the assumed per-symbol fractional eligibility.
  - The defaults reproduce v2.
  - Any other order mode (for example a fractional limit) is refused.
- **`mcp:strategy-replay --variant=id:key=value,…`:** up to six variants replay the same downloaded history beside the live baseline, in one immutable record. Variants identical to the baseline or to each other are refused, comparing effective values.
- **Experiments page:** gains the market filter length and the two on/off switches. Order mode stays daily-replay only, because the quote experiments model limit-order fills from sampled quotes.

The forward paper path is unchanged and still places whole-share limit orders. Using fractional shares there needs three more things:
- a market-order fill path that applies the price guard at submission
- a per-symbol fractional-eligibility source (the broker quote does not assert it)
- an owner-applied settings version

That is the next increment, not part of this one.

## Results (DAILY-003)

The protocol, including the decision rule, was written into the [experiment register](STRATEGY-EXPERIMENTS.md) before any data was fetched:
- **Universe:** DAILY-002's 23 nominating symbols (revision 314) and SPY.
- **Data:** one fresh split-adjusted SIP daily snapshot (3 read-only requests, 2.6 s, sha256 `5aa73e42…`); development checkout; no database write or broker call.
- **Periods:** discovery 2023-10-20 to 2025-10-17 (500 sessions); reserved holdout 2025-10-20 to 2026-10-19 (251 sessions).
- **Baselines:** at $500, fractional market orders at 5 bp; at $10,000, whole shares.

Each replay took 12–24 s. Full figures, including breakout/pullback splits and exit reasons, are in the [machine-readable report](M5-DAILY-003-2026-10-21.json).

**Fractional market orders at $500**

| | Discovery: whole shares | Discovery: fractional 5 bp | Holdout: whole shares | Holdout: fractional 5 bp |
|---|---|---|---|---|
| Setups skipped (size zero) | 472 | 69 | 256 | 29 |
| Closed trades | 24 | 110 | 16 | 64 |
| Return after costs | +4.07% | +16.82% | +1.18% | +3.28% |
| Max drawdown | 6.14% | 11.28% | 2.64% | 8.12% |
| Time invested | 39% | 85% | 52% | 96% |

- **Affordability:** fractional sizing makes $500 tradable. The account then behaves like the $10,000 whole-share account: +19.55% in discovery and +3.13% in holdout at $10,000.
- **Drawdown:** it rises because the account is actually invested.
- **Slippage:** at 10 bp instead of 5, the discovery return was 1.9 points lower (+14.93%). In the holdout it was 0.3 points higher (+3.58%), from a different trade path. The slippage assumption matters but does not decide the outcome.
- **Owner decision:** this supports the decision. Next is the forward paper path.

**Rule variants** (return after costs / return ÷ max drawdown, against the baseline for that account size)

| Variant | $500 discovery | $500 holdout | $10,000 discovery | $10,000 holdout | Verdict |
|---|---|---|---|---|---|
| Baseline | +16.82% / 1.49 | +3.28% / 0.40 | +19.55% / 1.87 | +3.13% / 0.40 | — |
| No close-below-50-day exit | +28.75% / 2.98 | −0.46% / −0.05 | +28.03% / 2.87 | +0.44% / 0.05 | **Fails holdout.** A discovery-period fit. |
| No market filter | +28.39% / 2.17 | +9.75% / 1.33 | +29.82% / 2.43 | +8.59% / 1.24 | **Passes the rule, then rejected by the bear check** (below) |
| Market filter 100 sessions | +17.40% / 1.51 | +7.84% / 1.14 | +9.93% / 0.93 | +8.15% / 1.18 | **Fails** ($10,000 discovery) |

**Bear check, 2021-10-20 to 2023-10-19.** This includes 2022. Separate snapshot `32bdf37d…`, 503 sessions; SPY −5.48%, equal-weight universe +2.08%. Added after seeing results; it can reject, not select.

| | $500 fractional baseline | $500 no market filter | $10,000 baseline | $10,000 no market filter | $500 filter 100 | $10,000 filter 100 |
|---|---|---|---|---|---|---|
| Return | −4.50% | −3.24% | −0.63% | −1.48% | +7.71% | +11.00% |
| Max drawdown | 16.52% | 18.40% | 15.51% | 16.86% | 12.53% | 11.87% |

- **No market filter:** raised the drawdown at both account sizes and lowered the return at $10,000, so it is rejected.
- **Market filter 100 sessions:** did best here, but it already failed the decision rule. It stays a hypothesis for a new, pre-registered test on a different period or universe, not a candidate.

**What this says about the strategy**

| Period | Baseline ($10,000) | SPY buy-and-hold |
|---|---|---|
| Discovery | +19.55% | +55.97% |
| Holdout | +3.13% | +14.86% |
| 2021–2023 | −0.63%, 15.5% max drawdown | −5.48% |

- **Profile:** the defaults trail buy-and-hold in rising markets and lose less in a falling one. That is the usual trend-following shape, not an edge.
- **Pullbacks vs breakouts:** pullbacks produced almost all trades and all of the profit. Breakouts were rare (1–6 per period) and lost money in most runs.
- **Parameters:** no parameter change is recommended. These are retrospective, selection-biased daily approximations and not forward results.

## Risk profiles (DAILY-004)

**The owner's goal for this account:** $500 in the Robinhood agentic account, meant to find opportunities and earn a high return. Long-term core investing happens separately, from a different watchlist this app does not read. "Core candidates" and "Growth opportunities" both continue to nominate.

**What the research replay can now vary:** a risk profile, through `risk_per_trade_pct` (0.25–3), `max_position_pct` (5–50) and `max_open_positions` (1–8). These research bounds are wider than the owner settings allow today (1%, 20%), so a more concentrated profile can be evaluated before any decision. Each result records the profile it used, and the baseline keeps the applied owner settings.

**Protocol:**
- Recorded in the [register](STRATEGY-EXPERIMENTS.md) before data was fetched.
- Same 23 symbols, default rules, $500, fractional market orders at 5 bp.
- One snapshot, sha256 `8ea008c7…` (4 read-only requests).

| Period | | Current (1%, 20%, 8) | Moderate (2%, 35%, 5) | Aggressive (3%, 50%, 3) | SPY / QQQ hold |
|---|---|---|---|---|---|
| 2021-10 to 2023-10 | Return | −4.50% | **+2.06%** | −0.72% | −5.48% / −4.25% |
| | Max drawdown | 16.52% | 21.67% | 19.64% | |
| | Worst day / worst trade | −2.9% / −$8.52 | −5.7% / −$16.65 | −5.5% / −$24.68 | |
| 2023-10 to 2025-10 | Return | +16.82% | +33.48% | **+48.95%** | +55.97% / +68.01% |
| | Max drawdown | 11.28% | 12.39% | 15.25% | |
| | Worst day / worst trade | −4.3% / −$9.08 | −5.5% / −$13.41 | −5.9% / −$20.35 | |
| 2025-10 to 2026-10 | Return | +3.28% | **+8.25%** | +6.80% | +14.86% / +18.32% |
| | Max drawdown | 8.12% | 12.74% | 16.34% | |
| | Worst day / worst trade | −2.7% / −$6.60 | −3.9% / −$13.18 | −4.2% / −$16.50 | |
| All periods | Closed trades | 88 / 110 / 64 | 60 / 72 / 45 | 42 / 52 / 37 | |

Reading:
- **Decision rule:** both larger profiles qualify. Each beats current on return in every period and on return ÷ drawdown in all three.
- **Moderate vs aggressive:** moderate is the better balance. It wins two of three periods with lower drawdown in two, while aggressive's advantage comes from one strong period.
- **Why it helps:** concentration puts more of the account to work. Larger positions fill the cash, which is also why more small setups are skipped as unaffordable.
- **Buy-and-hold:** neither larger profile comes close to SPY or QQQ in the two rising periods. All three profiles lose less than both in 2021–2023.
- **Gap risk:** the worst trades were overnight gaps through the stop (BKNG −12.6%, NVDA −13.8%). A bigger position makes the same gap cost more.
- **Not modeled here:** the daily loss pause.
- **Applying moderate:** it would need an owner decision and a code change, because applied settings are bounded at 1% risk and a 20% maximum position, matching the October 18 owner decision.
- **Caveat:** these are selection-biased daily approximations with fewer trades, not forward results.

## Verification

| Check | Result |
|---|---|
| Full SQLite | **589 passed / 4,044 assertions, 35 PostgreSQL-only skips** (624 total) |
| Full PostgreSQL | **623 passed / 4,372 assertions, 1 existing PHP-FPM environment skip** (624 total) |
| `composer validate`, changed-file Pint, `git diff --check` | Passed |

No frontend asset changed (Blade only), and no migration was added.

New or extended tests:
- **Rule switches:** default to live behavior and never suppress stops; unknown market state blocks entries.
- **Fractional sizing:** 6-decimal round-down, both sides of the $1 minimum, the scale bound, unchanged whole-share output.
- **Daily replay:** fractional fill price and quantity at $500; a refused fractional-limit mode; the market-filter switch on a falling benchmark.
- **Intraday replay:** the market-filter switch; blocking stays the default.
- **Replay service:** variants share one history read and are stored typed; baseline restatements, owner risk keys, fractional limits, untyped switches, out-of-bounds values, duplicates and too many variants are refused; the command parses `--variant`.
- **Experiments:** typed switches through the definition and the form; invalid choices are refused.
- **Risk profiles:** a research profile changes only sizing and capacity and is recorded with each result; out-of-bound, untyped and unknown risk keys (including the daily loss pause) are refused; the command parses risk keys.

## Migration and deployment impact

No migration. Deploying changes experiment source hashes: completed reports stay readable, and an unfinished experiment pauses and must be started again. The replay record's payload gains `variants`, and the input fingerprint includes them.
