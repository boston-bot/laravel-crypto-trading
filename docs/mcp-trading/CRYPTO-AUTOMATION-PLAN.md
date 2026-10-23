# Crypto automation plan (Coinbase and Robinhood Crypto)

Status: **adopted by the owner October 22, 2026; C0 and C1 are deployed in `261de7e`, with crypto collection disabled.** C2 stored-data evaluation and virtual-sleeve code is now implemented in source, with runtime/replay acceptance still pending; C3–C7 remain planned work. Coinbase key permissions were read successfully from development configuration; Robinhood permissions remain unverified. Credentials are now recognized in both development and deployed configuration (presence-only follow-up); no additional authenticated request was made. This plan does not authorize brokerage mutations, live mode, API-key permission changes or resuming any legacy schedule. [AGENTS.md](../../AGENTS.md#crypto-track-adopted-october-22-2026) records the standing constraints; the [checklist](DEVELOPMENT-CHECKLIST.md#crypto-track--coinbase-and-robinhood-crypto) records evidence.

## Summary

The legacy crypto code can already sign requests to Coinbase Advanced Trade and the Robinhood Crypto API, sync accounts, quotes and candles, score assets and place market orders. Enabling its live path takes configuration only, but that path does not meet this repository's execution invariants and must not be used. The recommended route reuses the legacy clients for transport and signing only. Crypto data, rules, paper evaluation and execution go through the same deterministic engine and the M6 execution boundary being built for equities.

Estimated effort: about **6–10 weeks of focused development** if crypto is built before equity M6, or **about 4–6 weeks** if equity M6 is finished first. Both figures exclude a forward-paper observation period of at least 4 weeks. These are planning estimates, not measurements.

## Legacy state before C0 (inspected October 22)

| Component | Location | Treatment |
|---|---|---|
| Coinbase client, JWT signer, mapper | `app/Services/Broker/Coinbase/` | Reuse signing and HTTP transport behind new read and execution gateways. Don't call `placeOrder` from anywhere except the new execution boundary. |
| Robinhood Crypto client, signer, mapper | `app/Services/Broker/Robinhood/` | Same as Coinbase |
| Sync jobs (account, assets, candles, positions, orders) | `app/Jobs/Sync*Job.php` | Replace with batched reads into `mcp_trading` that carry provenance. Legacy jobs stay behind `LEGACY_CRYPTO_SCHEDULES_ENABLED` (currently `false`). |
| Feature, regime, scoring and entry/exit services | `app/Services/Strategy/` | Reference material only. About 130 `(float)` casts appear in strategy, risk and sizing code. Thresholds may be carried over only through an explicit, owner-approved policy mapping. |
| `RiskEngine`, `PolicyEngine`, `OrderSizingService` | `app/Services/Risk/`, `app/Services/Execution/` | Superseded by `RiskSizer`, signal admission and owner policy versions |
| `TradeExecutionService` and `SubmitBrokerOrderJob` | `app/Services/Execution/`, `app/Jobs/` | Retire the live branch (checklist M5 item "retire … the legacy crypto live order path"). It calls the broker before saving a local order record, sends unbounded market orders and float quantities, and has no submission ownership or ambiguous-outcome handling. |
| `EvaluateSignalsJob` (daily 09:00) and the approval controller/command | `app/Jobs/`, `app/Http/Controllers/TradeDecisionApprovalController.php`, `ApproveTradeDecisionCommand` | Approval dispatches `SubmitBrokerOrderJob` whether or not the legacy schedule flag is on. Disable it in C0. |

Runtime flags observed in `.env`: `BROKER=coinbase`, `BROKER_MODE=paper`, `LEGACY_CRYPTO_SCHEDULES_ENABLED=false`, `BROKERAGE_WRITES_ENABLED=false`. With these values, no legacy order can reach a broker.

**Shared-flag hazard (resolved in C0):** `BROKERAGE_WRITES_ENABLED` gated both the legacy crypto clients and the future M6 equities executor. Enabling it for an equities pilot while `BROKER_MODE=live` would have re-armed the legacy crypto path.

## How existing invariants apply to crypto

Every invariant in AGENTS.md applies unchanged: exact decimals, deterministic versioned rules, input fingerprints, immutable intent before submission, one idempotency key per logical order, reconciliation before new exposure, separate read and execution gateways, and simulated results kept visibly separate from broker-confirmed results. Crypto differs in the following ways:

| Topic | Equities (current) | Crypto (proposed) |
|---|---|---|
| Session | Exchange calendar, regular session | Continuous 24/7. The daily profile boundary is a fixed UTC time set as a versioned setting. Exits and risk checks run at all hours. |
| Symbol nomination | ChatGPT-maintained Robinhood watchlists | Recommended: an owner-applied, versioned allowlist of trading pairs. Watchlist intake for crypto is an owner decision. |
| Eligibility | Classification, corporate actions, earnings, liquidity, spread, history | Venue tradability and status, minimum order size, price and quantity increments, 24-hour volume, spread, history length. No earnings or corporate-action checks. |
| Costs | Commission plus SEC/FINRA sell fees | Coinbase: maker/taker rate for the account's fee tier, read from the venue and stored as a versioned setting. Robinhood Crypto: spread-based pricing, modeled from observed bid/ask. Rates are verified at implementation time, not taken from this document. |
| Quantity | Whole or fractional shares | Venue base and quote increments from product metadata; round down with explicit decimal rounding |
| Volatility | Equity parameter set | Separate parameter versions. Equity values are not reused without replay evidence. |
| Unrecognized orders | Agentic-account orders the app didn't create pause entries | Same for each crypto venue, including manual orders and any legacy-path order |

**Single engine:** AGENTS.md forbids a second strategy engine or database writer. Crypto support therefore extends the existing signal, profile, sizing and paper components with an asset-class/venue dimension instead of building a parallel crypto engine. Generalizing their session and share-unit assumptions is part of C2's effort.

## Owner decisions

| Decision | Needed by | Safe behavior until decided |
|---|---|---|
| ~~Adopt this plan; amend AGENTS.md, the index and the implementation-plan non-goals~~ Adopted October 22 | C0 | — |
| ~~Venue(s)~~ Coinbase first (October 22) | C1 | — |
| Any coins (owner, October 22): no owner-imposed coin restriction; venue tradability and deterministic eligibility/data-quality checks still apply. Evaluation is bounded to the configured collection universe (at most 25 products); automatic discovery remains unimplemented. | C2 | No crypto signals; do not interpret this as an unbounded API scan |
| Paper sleeve (owner, October 22): $400 starting virtual cash, 5% risk per trade, 25% maximum position, 3 open positions, 5% daily loss pause | C2 | Source policy/ledger support implemented; no runtime crypto policy or opening balance applied yet |
| Order with respect to equity M6: before or after | C4 | Shared execution boundary follows the equity schedule |
| Keys added to development `.env` (owner, October 22); runtime subsequently recognizes both (presence check only). Coinbase permission GET confirms view/trade/transfer all true; Robinhood permissions unverified. | C1/C5 | C1 still uses public endpoints. Authenticated monitoring/reconciliation is not implemented by configuring keys; C5/C7 gates remain incomplete |
| Always-on hosting: the Mac with sleep disabled, or another host making outbound connections only | C6 | No unattended overnight operation |
| Live pilot limits and explicit authorization | C7 | Paper only |

## Milestones

### C0 — Adoption and containment (1–2 days) — deployed October 22, Robinhood permissions unverified

Delivered: the plan was adopted and the documents amended; venue flags `COINBASE_WRITES_ENABLED` and `ROBINHOOD_CRYPTO_WRITES_ENABLED` were added; the live branch of `TradeExecutionService` was removed (any non-paper mode is refused before a broker call); the approval API and command refuse outside paper mode; and `php artisan broker:key-permissions` was added. October 22 follow-up: both keys now exist in development and are recognized in runtime configuration (presence-only follow-up). A bounded Coinbase permission read succeeded: view, trade and transfer are all permitted. Robinhood Crypto documents no permission read, so its key's permissions stay unverified. Configuring a key does not enable application writes. [Evidence](DEVELOPMENT-CHECKLIST.md#crypto-track--coinbase-and-robinhood-crypto).

Original scope:


- Owner adopts the plan; amend AGENTS.md, [README](README.md) and the [implementation plan](IMPLEMENTATION-PLAN.md) to add a crypto track with its own release gates.
- Split write gating into per-venue flags (for example `COINBASE_WRITES_ENABLED`, `ROBINHOOD_CRYPTO_WRITES_ENABLED`), default `false`, independent of the equities execution flag.
- Remove or hard-disable the live branch of `TradeExecutionService`, and stop the approval controller/command from dispatching broker submissions.
- Record each key's actual permissions through a read-only call (Coinbase documents a key-permissions endpoint; verify Robinhood's equivalent). Recommend view-only keys until C7.

Acceptance: tests show that no code path reaches `placeOrder` on either client while the new flags are off, including with `BROKER_MODE=live` and `BROKERAGE_WRITES_ENABLED=true`.

### C1 — Read-only crypto market data (1–2 weeks) — deployed October 22, collection off

Delivered for Coinbase using public endpoints only (new development credentials do not change this gateway): products, best bid/ask, closed five-minute and daily candles, immutable reads with provenance, a `crypto_data` health check, and migration `000028`. Authenticated account and order monitoring remain to be implemented and verified. [Evidence](CRYPTO-C1-MARKET-DATA-EVIDENCE.md).

Original scope:


- Read-only gateways for both venues with a tool/endpoint allowlist, reusing the existing signers.
- Batched best bid/ask and candles for allowlisted pairs and held positions, saved to `mcp_trading` as decimal strings with source time, retrieval time, completeness and provenance. Additive migrations only.
- Product metadata (status, increments, minimum sizes), account balances and order history for reconciliation and detection of orders the app didn't create.
- A continuous calendar, a staleness policy, and health/alert integration. Stale or missing data produces no signal.

Acceptance: fake-client unit tests that reject unexpected network calls; PostgreSQL integration tests for persistence and concurrency; one bounded live read-only check per venue, recorded as evidence.

### C2 — Eligibility, profiles, rules and costs (1–2 weeks) — stored-data source increment October 22

Owner inputs confirmed October 22: any coins subject to venue eligibility, $400 starting virtual cash, 5% risk per trade, 25% maximum position, at most 3 open positions, and a 5% daily loss pause. Initial dollar equivalents are $20 risk, $100 position cap and $20 daily loss pause. Risk is the maximum planned stop loss including modeled costs, not a required spend; the position/cash caps may bind first. A pause blocks new entries while exits continue. These are paper research inputs, not live authorization or an applied policy. Parameter validation/replay and cost verification remain required.

Source increment: the shared profile/signal/sizing/cost engine now supports a separate crypto virtual sleeve and immutable stored-data evaluations. Default off; migration `000029` pending deployment. Product-specific fee verification, held-portfolio lifecycle and replay remain incomplete. [Implementation, tests and exact boundaries](CRYPTO-C2-RULES-EVIDENCE.md).

October 23 source follow-up adds a manual, read-only Coinbase spot/CBE fee-tier report for operator review, explicit product-all-in cost scope and timezone-qualified fee observations. Crypto sizing uses the greater maker/taker rate because either liquidity role may be more expensive. Account rates still require pair-specific applicability/tax/commission review; no real schedule or runtime policy has been applied. C2's actual fee-verification gate remains open; lifecycle/replay belongs to C3.

Original scope:

- Add an asset-class/venue dimension to eligibility, profiles, signals, sizing and paper accounting; generalize session and quantity-unit assumptions.
- Crypto eligibility rules, profile calculator inputs on the UTC daily boundary, and a crypto playbook with its own parameter versions.
- Versioned cost settings per venue; sizing that respects venue increments; a separate crypto sleeve with virtual cash.

Acceptance: exact-decimal unit tests; immutable signal records carrying rule and parameter versions, symbol profile and input fingerprint; equity tests unchanged.

### C3 — Replay and forward paper (about 1 week to build, at least 4 weeks to observe)

- Candle-based replay over stored history, labeled separately from forward results.
- A five-minute forward-paper cycle running 24/7, with realistic bid/ask fills, fees and slippage.
- A crypto section on the paper dashboard, separate from equities and from anything broker-confirmed.

Acceptance: reports after costs (drawdown, hit rate, turnover, unavailable evaluations) compared against buy-and-hold of the allowlisted pairs. Judge results only after at least 30 closed trades.

### C4 — Shared execution boundary (2–4 weeks; equals equity M6)

Implement the M6 checklist items and mandatory fault tests in a venue-agnostic way. If equity M6 is complete, this milestone reduces to registering the crypto venues and their gates.

### C5 — Crypto order adapters (about 1 week per venue)

- Limit orders only, with price bounds derived from a fresh quote, quote-age and spread checks at submission, and one stable `client_order_id` per logical order. Verify each venue's documented duplicate-ID behavior; never resubmit after a timeout without reconciliation.
- Map venue order states into the shared lifecycle, including partial fills, cancellations and rejects.
- Run every M6 fault test against fake venue clients.

### C6 — 24/7 operations (2–5 days)

- Keep the runtime host awake and supervised (launchd), and reconcile before any new entry after a restart or sleep.
- Overnight alerts for stale data, unreconciled orders, unrecognized orders and the daily loss pause; independent heartbeat.
- Backup/restore drill covering crypto tables and stale-intent handling.

### C7 — Authorized live pilot (owner action)

One venue, small explicit limits, write flag enabled only for the pilot window, with broker-confirmed results recorded. This plan supplies no live authorization.

## Effort summary

| Milestone | Crypto first (before equity M6) | After equity M6 |
|---|---|---|
| C0 Containment | 1–2 days | 1–2 days |
| C1 Market data | 1–2 weeks | 1–2 weeks |
| C2 Rules and costs | 1–2 weeks | 1–2 weeks |
| C3 Replay and paper build | ~1 week | ~1 week |
| C4 Execution boundary | 2–4 weeks | ~2 days |
| C5 Adapters (both venues) | ~2 weeks | ~2 weeks |
| C6 24/7 operations | 2–5 days | 2–5 days |
| **Total development** | **~6–10 weeks** | **~4–6 weeks** |
| Forward-paper observation | at least 4 weeks / 30 closed trades | same |

Recommendation: finish equity M5 and M6 first, do C0 now, and run C1–C3 alongside M6 if capacity allows.

## Principal risks

- **Legacy re-arming:** a shared flag or an approval route could reach a legacy order path. Mitigated in C0.
- **Unattended overnight failures:** the host sleeps, the network drops or an alert goes unread while positions are open. Mitigated in C6; exits must not depend on an operator being awake.
- **Volatility and gaps:** price moves between evaluations can exceed equity-calibrated stops. Requires separate parameter versions and replay evidence.
- **Venue contract drift:** endpoints, fee tiers and increments change. Reads are versioned and verified; missing or changed fields produce no signal.
- **Overfitting to recent crypto regimes:** judge only on point-in-time replay plus forward paper, after costs.
