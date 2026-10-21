# M5 multi-session quote replay and strategy register

October 20, 2026 Central. Source-only continuation of [QUOTE-001](M5-INTRADAY-REPLAY-EVIDENCE.md); no deployment, migration, backfill or runtime configuration change. The [strategy experiment register](STRATEGY-EXPERIMENTS.md) now records hypotheses, fixed comparisons, findings, limitations and superseded evidence. It separates operational behavior and affordability from evidence of a profitable strategy.

## Behavior

`StrategyReplay::runQuoteSessions` accepts two to five full, consecutive verified sessions with the same one or two research symbols. Its version is `intraday_quotes_multisession_v1`. It calls the same session step used by `intraday_quotes_v1`, preserving that mode's output. It shares the existing profile, entry/exit, eligibility, sizing, decimal cost and fill-price implementations. No extra strategy engine or model is introduced.

Cash, filled shares, entry ATR/costs, exit latches, monotonic trailing stops, cooldown history, order IDs and aggregate results carry between sessions. Profiles are rebuilt from each date's preceding completed raw bars. On the next session, only the preceding completed close updates holding age, progress and trailing stops. Current/future daily bars cannot change earlier decisions. Full-session DAY remainders expire and release reservations; their filled shares survive, while the expired remainder cannot fill at the next open. Unfilled exit intentions remain latched and generate fresh intents on later eligible decision quotes.

Each session establishes a fresh opening-equity baseline. Held positions require usable quotes at the sampled open; if any are unavailable, that session blocks all new entries. No later mark replaces that baseline. Exits continue. A daily-loss breach, including a loss immediately after a partial fill, stays latched for the session even if later quotes recover. This strict opening sample is narrower than the forward collector's first-minute window and remains an explicit research coverage limitation.

No session can be skipped. An incomplete acquisition archives no partial performance. Unsupported non-cash actions already reject raw-history acquisition. Multi-session scenarios also reject observed cash-dividend ex-dates inside the replay interval, or missing ex-dates, rather than inventing overnight entitlements. Complete process-date reads do not prove historically complete publication knowledge. Earnings blackouts, historical watchlists, historical classification/listing and account permissions remain assumptions; this is not point-in-time walk-forward acceptance.

Results include dated profiles, per-session opening/closing equity and position state, fills, complete-position trades, and exact closed-trade net P&L versus the marked P&L attributable to still-open episodes. Open episodes include any partial-sale cash flows. Missing terminal marks leave equity, return and the open-episode mark unknown. No forced terminal liquidation creates a synthetic closed trade.

`mcp:intraday-replay --session=...` archives the whole range under the existing encrypted replay table and immutable input fingerprint. UUID retries perform no new requests; changing dates or mode under the same UUID conflicts. Operator scope and authorization-generation checks remain in place. There is no scheduler entry or forward-ledger write.

## QUOTE-002: fixed two-session comparison

Before acquiring new data, the register fixed CRM/MSFT, October 19–20, default parameters and fee schedule, and $500/$10,000 starting cash. Its success criterion concerned chronology and accounting, not return optimization. The period overlaps prior exploratory work and is not a holdout.

October 19 acquisition used **166 read-only requests / 24.365 seconds**. October 20 reused the prior frozen dataset, with no refetch. Together: **158 sample times, 316 symbol-time samples, 310 usable quotes**, with six unknown samples retained. Both balances use exactly the same acquisition and unchanged parameters/settings. Only the development app's existing Alpaca credentials were used; no credential was copied or persisted in artifacts.

| Result | $500 virtual start | $10,000 virtual start |
|---|---:|---:|
| Orders / fills | 0 / 0 | 1 / 1 |
| Closed trades / exact closed-trade net P&L | 0 / $0 | 0 / $0 |
| Terminal open positions | 0 | 1 (CRM, simulated) |
| Final marked equity | $500.00 | $9,994.78 |
| Open-episode marked P&L | $0 | −$5.221745 |

Neither balance opened a position on October 19. Thus the real sample did **not** exercise an overnight holding; unit tests demonstrate carry, partial-fill expiry, following-day stop exits, trailing-stop updates, holding age, missing-opening marks and loss-pause recovery behavior. There were no closed trades from which to estimate a win rate or profitability. The $500 scenario had four trigger evaluations blocked by whole-share sizing. They are repeated evaluations, not four missed completed trades.

This result does not justify a funding change, parameter adjustment, signal activation or a claim that either playbook works or fails. The single-session results remain intact, with archived original source and an isolated replay that reproduces both original outcomes exactly.

## Evidence and verification

[Aggregate report](M5-MULTISESSION-REPLAY-2026-10-20.json) includes settings, parameter/source hashes, coverage, event reasons, per-session states, results and independent checks. Private frozen artifacts are under `storage/app/private/intraday-replay/multisession-2026-10-19-20-final/`:

- `inputs.json`, bound by SHA-256 in both result files; references the reused QUOTE-001 input hash.
- `source-hashes.json`, `replay-500.json`, `replay-10000.json`, `report.json`, `verified-report.json`.
- `run.php replay`: repeats the same frozen acquisition without HTTP/database requests and refuses changed research source hashes. Two identical repetitions passed.
- `verify.php`: independently reconstructs cash/shares/terminal bid equity and the exact P&L partition; verifies snapshot identity, acceptance/source chronology, no DAY fill across sessions, no valuation-only fill, immutable limits, displayed-size bounds, nonreused liquidity and released reservations. Both cases passed.

A pre-verification development run remains in the sibling directory with a `SUPERSEDED.txt` explanation. After a partial-fill loss-latch regression was added, the final run reused the acquisition byte for byte; real-data results remained identical. No unsuccessful or superseded run was relabeled as final evidence.

Full test totals and existing environment skips are recorded in the [checklist](DEVELOPMENT-CHECKLIST.md). No dependencies or frontend assets changed. The normal CLI's encrypted PostgreSQL archive was tested with fake providers in the dedicated test database; this actual acquisition used private development artifacts and did not write the runtime archive or forward paper ledger.

Next within M5: broaden a predeclared evaluation period, obtain dated earnings/universe evidence and adequate opening marks, and accumulate completed trades before judging either playbook or selecting variants. Regular-session runtime cadence and controlled forward-paper acceptance remain separate checks. M5 remains partial; M6 remains unstarted.
