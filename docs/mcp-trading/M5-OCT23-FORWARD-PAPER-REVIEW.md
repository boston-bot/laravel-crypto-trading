# Friday forward-paper review — inspected October 25, 2026

M5 remains partial. This is a stored-data operational review, not a live-trading readiness decision. Runtime and source were clean at `e597630` when inspected; the deployed quote-refresh implementation is inherited from `d5cb58b`. Reads used one PostgreSQL repeatable-read, read-only transaction in database `postgres`, schema `mcp_trading`, scoped to the authorized operator's agentic connection, authorization and paper portfolio. No broker/provider request, policy change, evaluation, fill or deployment was triggered by this review.

## CRWD paper episode

| Item | Recorded result |
|---|---|
| Entry | October 23, 09:40:51 Central (14:40:51 UTC) |
| Order / signal / fill | Paper order 1 / signal 1703 / fill event 2 |
| Mode | `real_data_paper_simulation`; no broker submission |
| Quantity | `0.201128` shares, fully filled; no exit fill |
| Fill price | `$250.125` |
| Cost basis / cash debited | `$50.307141`; recorded fee `$0` |
| Remaining virtual cash / reserved | `$449.692859` / `$0` |
| Entry ATR | `$12.36277857` |
| Initial stop | `$225.39944286` (entry minus two entry ATRs) |
| Initial price-distance risk | `$4.97300185645392`; this excludes gaps and future exit costs |
| Last exit evaluation | October 23, 14:55:51 Central: `holding_exit_not_triggered` |
| Last recorded trailing stop | `$225.39944286`; no latched exit |
| Position review block | None recorded |

The fill predates deployment at 10:08 Central and must not be attributed to the quote-refresh fix. Its accepted observation 6365 already had a matching book. The accepted ask was `$250.00`; the simulated fill price includes the applied 5 bps slippage. Exact decimal checks reconcile quantity, fill notional/cash delta, cost basis, remaining cash and the two-ATR stop. Virtual cash plus basis is exactly `$500`.

The last Friday quote (input 8425, bid source time 14:55:44 Central) had bid `$257.58`. Applying that historical bid gives position value `$51.80655024` and unrealized paper P&L `$1.49940924`. This is neither an official closing valuation nor a fresh Sunday mark. No realized profit or strategy edge is established by this open position.

## Quote/book recovery and its cost

Observations are grouped by local recording time within Friday's 08:30–15:00 Central session, split at the verified deployment checkpoint 10:08:49 Central. The before/after windows and symbol populations differ; the comparison is descriptive, not a controlled experiment.

| Measurement | Before deployment | After deployment |
|---|---:|---:|
| Stored observations | 602 | 1,926 |
| Exact quote/book matches | 150 (24.9%) | 1,518 (78.8%) |
| Recorded refreshes / completed refreshes | 0 / 0 | 1,144 / 1,144 |
| Matches recovered by refresh | 0 | 815 (71.2% of refreshes) |
| Remaining price/quantity mismatches | 434 | 328 |
| Stale/future quote/book observations | 18 | 80 |
| CRWD matched observations | 1 / 20 | 44 / 56 |
| CRWD recovered matches | 0 | 38 / 50 refreshes |
| Evaluations with source age at most 20 s | 576 / 602 (95.7%) | 1,801 / 1,926 (93.5%) |
| Evaluation source age, p95 | 19.4 s | 21.7 s |
| Collection span within a five-minute slot, p95 | 68.4 s | 93.1 s |

The refreshed path directly recovered 815 matching observations, while 703 post-deployment matches needed no refresh. It also added 1,144 completed quote reads. No refresh errors or execution/reference-read errors were recorded in these observation payloads. This does not prove that no provider-level rate limiting occurred elsewhere; provider billing/request totals were not audited.

Matching still does not establish full entry/fill readiness. The unchanged freshness, classification, corporate-action, portfolio and policy gates remain separate. Some observations also age between matching and evaluation. Longer collection spans and lower source freshness warrant investigation, but the evolving universe and different session periods prevent attributing the difference solely to the extra reads. Do not relax exact equality or the 20-second quote limit.

## Cadence and blockers

The existing read-only `stored_cadence_v2` report measured **76 of 78** expected session slots/cycles (97.4%), 2,528 observations across 40 symbols and 94.0% of evaluations within 20 seconds. Missing cycle slots were **13:25 and 14:30 Central** (18:25 / 19:30 UTC). They occurred well after deployment; their causes were not established by this review. Usable quote observations at recording were 2,434 / 2,528; full execution coverage is stricter.

Friday's final stored entry evaluations still blocked TMO for an unreconciled known corporate action; ELV for that action condition plus missing SEC classification; and PLTR for missing verified listing/history/classification evidence. These are historical outcomes, not claims that all evidence is still absent Sunday.

By Sunday, new complete reference evidence exists: PLTR listing/history reads 345/344 and classification 167; ELV listing/history reads 317/316 and classification 153. Both classifications use `operating_issuer_sole_listed_security`. These records do not retroactively clear Friday evaluations or establish a fresh Monday entry. Corporate-action reconciliation and current profile/quote checks still need to be verified at the next session.

Friday watchlist revision 1148 contains 13 raw nominees: CRWD, DDOG, ELV, HAS, MEDP, PG, PLTR, RNG, SM, TMO, TRV, XLE and XOP. Historical membership was reconstructed from that immutable revision's roles; the `nominations()` helper also consults the **current** external-order gate and therefore must not be used to infer an empty historical list from today's gated result.

At the Sunday review, there were **zero unreviewed external orders**. The current entry gate was `broker_order_history_unverified`: the latest complete stored order read was at 15:01:01 Central, older than its five-minute requirement. This is not an external-order incident and was not cleared manually. All three live-write flags remain false; AI decisions/research remain disabled. Sunday data does not authorize entries.

## Verification and next work

Read-only database inspection, existing cadence-report computation, exact-decimal ledger/stop reconciliation and clean source/runtime Git checks completed. No application code or assets changed, so no PHPUnit/build rerun or migration was required for this evidence-only review. No full-session performance acceptance or readiness gate is advanced.

Next M5 work: explain the two missing cycles and reduce quote age at evaluation while keeping the bounded refresh and exact-match checks; verify the new PLTR/ELV reference records feed current profiles; inspect the TMO/ELV known-action reconciliation block. Continue forward-paper measurement before considering any M6 work. Live execution remains unimplemented and unauthorized.
