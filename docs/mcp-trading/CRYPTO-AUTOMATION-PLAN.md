# Crypto automation plan (Coinbase and Robinhood Crypto)

Status: **adopted by the owner October 22, 2026; C0 done in source, except the live key-permission check, which is blocked (no crypto credentials configured).** C1 (Coinbase public market data) is complete in source and not deployed; C2–C7 are planned work, not implemented functionality. This plan does not authorize brokerage mutations, live mode, API-key permission changes or resuming any legacy schedule. [AGENTS.md](../../AGENTS.md#crypto-track-adopted-october-22-2026) records the standing constraints; the [checklist](DEVELOPMENT-CHECKLIST.md#crypto-track--coinbase-and-robinhood-crypto) records evidence.

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
| Symbol universe: allowlisted pairs, or a crypto watchlist | C2 | No crypto signals |
| Crypto sleeve: virtual capital, risk per trade, maximum position, maximum open positions, daily loss pause | C2 | Paper evaluation blocked |
| Order with respect to equity M6: before or after | C4 | Shared execution boundary follows the equity schedule |
| API keys: owner declined adding a key for now (October 22) | C1/C5 | Public market data only; no balances, orders, unrecognized-order detection or reconciliation, so C5/C7 stay blocked until a key exists |
| Always-on hosting: the Mac with sleep disabled, or another host making outbound connections only | C6 | No unattended overnight operation |
| Live pilot limits and explicit authorization | C7 | Paper only |

## Milestones

### C0 — Adoption and containment (1–2 days) — done in source October 22, key check blocked

Delivered: the plan was adopted and the documents amended; venue flags `COINBASE_WRITES_ENABLED` and `ROBINHOOD_CRYPTO_WRITES_ENABLED` were added; the live branch of `TradeExecutionService` was removed (any non-paper mode is refused before a broker call); the approval API and command refuse outside paper mode; and `php artisan broker:key-permissions` was added. Blocked: no Coinbase or Robinhood Crypto credentials exist in `.env` or `broker_credentials`, so no key's actual permissions could be read. Run the command after any key is configured. Robinhood Crypto documents no permission read, so its key's permissions stay unverified. [Evidence](DEVELOPMENT-CHECKLIST.md#crypto-track--coinbase-and-robinhood-crypto).

Original scope:


- Owner adopts the plan; amend AGENTS.md, [README](README.md) and the [implementation plan](IMPLEMENTATION-PLAN.md) to add a crypto track with its own release gates.
- Split write gating into per-venue flags (for example `COINBASE_WRITES_ENABLED`, `ROBINHOOD_CRYPTO_WRITES_ENABLED`), default `false`, independent of the equities execution flag.
- Remove or hard-disable the live branch of `TradeExecutionService`, and stop the approval controller/command from dispatching broker submissions.
- Record each key's actual permissions through a read-only call (Coinbase documents a key-permissions endpoint; verify Robinhood's equivalent). Recommend view-only keys until C7.

Acceptance: tests show that no code path reaches `placeOrder` on either client while the new flags are off, including with `BROKER_MODE=live` and `BROKERAGE_WRITES_ENABLED=true`.

### C1 — Read-only crypto market data (1–2 weeks) — Coinbase done in source October 22, not deployed

Delivered for Coinbase using public endpoints only, because no key is configured: products, best bid/ask, closed five-minute and daily candles, immutable reads with provenance, a `crypto_data` health check, and migration `000028`. Account and order reads wait for a key. [Evidence](CRYPTO-C1-MARKET-DATA-EVIDENCE.md).

Original scope:


- Read-only gateways for both venues with a tool/endpoint allowlist, reusing the existing signers.
- Batched best bid/ask and candles for allowlisted pairs and held positions, saved to `mcp_trading` as decimal strings with source time, retrieval time, completeness and provenance. Additive migrations only.
- Product metadata (status, increments, minimum sizes), account balances and order history for reconciliation and detection of orders the app didn't create.
- A continuous calendar, a staleness policy, and health/alert integration. Stale or missing data produces no signal.

Acceptance: fake-client unit tests that reject unexpected network calls; PostgreSQL integration tests for persistence and concurrency; one bounded live read-only check per venue, recorded as evidence.

### C2 — Eligibility, profiles, rules and costs (1–2 weeks)

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
