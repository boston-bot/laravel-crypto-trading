# Crypto C2: stored-data rules and virtual sleeve

Status: **source implementation, October 22–23, 2026; not committed, deployed or enabled.** Requires migration `2026_10_22_000029_add_crypto_strategy_context`. No runtime policy, virtual opening balance, signal, order or fill was created by this development work.

## Owner inputs

The owner confirmed: any coins subject to eligibility, $400 virtual starting cash, 5% risk per trade, 25% maximum position, 3 open positions and a 5% daily loss pause. At the initial balance those amounts are $20 risk, $100 position notional and $20 daily loss. The risk budget is a maximum planned stop loss including modeled costs; the position cap will often bind first. It does not mean spending 5% of cash on every entry. No setting authorizes live trading.

“Any” means no owner-imposed coin allowlist. The implemented venue is still Coinbase USD spot. Evaluation is bounded to the configured collection list (1–25 products); there is no whole-exchange discovery loop. Include `BTC-USD` in collection for the benchmark. Missing metadata, benchmark, fees or history produces unavailable evidence.

## Shared engine and storage

- Existing `ProfileCalculator`, `SignalRules`, `PaperFillPrice`, `RiskSizer` and `TradingCosts` perform the calculations. Crypto adapters select and verify stored inputs; they do not implement a second set of entry/exit rules or use legacy crypto strategy services.
- Existing `Portfolio`, `CashEntry`, `SymbolProfile`, `StrategyCycle` and `StrategySignal` tables/models hold the virtual cash and immutable evidence. New instrument policies are encrypted immutable versions. Equity connections, calendars, parameters, scales and fees retain their existing behavior.
- Asset class and venue are explicit columns. A crypto portfolio belongs to an authenticated local operator identity (CLI operator ID checked against configured operator emails), has no agentic-account connection, and is separate from equity cash and broker holdings. An explicit policy command creates its sole opening cash entry; a retry cannot credit cash again. Later policy versions do not alter cash.
- `CryptoStrategyEvaluation` records eligibility, profile, parameter hash, policy ID/hash, the full frozen input data and observation IDs/times, shared calculator/rule versions and an input fingerprint. The configured collection universe is pinned in the cycle; watchlist revision is explicitly not applicable. Signals are visibly paper evidence and not broker executable.
- A portfolio row lock serializes duplicate evaluation workers. A five-minute UTC window and policy version identify a cycle. Retrying returns the first committed result rather than recomputing it against later quotes. A changed list in the same window is refused. Failed work rolls back the entire cycle, profiles and signals.

## Inputs, profile and sizing

`CryptoStrategyInputs` accepts only data retrieved and published by a completed/partial successful product read at or before the cutoff. It verifies online USD spot status, disabling/restricted flags, positive and consistent venue constraints, at least $10M observed quote volume over 24 hours, a midpoint spread of at most 50 bp, a quote at most 120 seconds old, and metadata at most 900 seconds old. Empty books fail. Unknown quote volume is not estimated. A stale quote by one microsecond is unavailable.

History must contain 200 consecutive closed UTC daily candles ending at the current UTC day boundary, with availability times no later than the evaluation cutoff. Missing days and incomplete benchmark history block entries. A recorded candle contradiction latches an unavailable reason until a future explicit review mechanism exists; this increment has no review bypass. It does not silently replace the first stored observation.

`crypto_swing_research_v1` is an independent, unvalidated research hypothesis: BTC benchmark / 100-day market filter, 30/100-day trend, 60-day relative strength (inclusive so BTC can qualify against itself), 14-day ATR, 20-day EMA/range, 12% ATR ceiling, range at most 4 ATR, pullback within 1 ATR, maximum chase 0.25 ATR, initial stop 2.5 ATR, trail 3.5 ATR, 14-day time exit and 2-day cooldown. These are not imported legacy thresholds, equity defaults or claimed profitable parameters. Position episode progression, cooldown application and held-portfolio integration belong to C3.

Crypto prices/indicators retain 18 decimals (24 in EMA recursion). Equity defaults retain their previous 8-decimal indicators, 12-decimal EMA recursion and 4-decimal levels/stops. Earnings is explicitly not applicable. The shared exit rules still prioritize stops when other references are missing; C2 does not yet run exits on a held crypto portfolio.

The shared sizer handles arbitrary base increments, including non-powers of ten, plus base/quote minimum and maximum sizes. Entry bounds round up to the venue price increment after adverse ask/slippage modeling and must remain inside the chase ceiling. Quantity rounds down. Risk reserves include entry/exit taker fees and adverse exit slippage, with explicit rounding reserves. No SEC, TAF, CAT or equity fractional minimum is applied to crypto. Slippage is an unvalidated 10 bp research assumption. Base-size orders use base increments and price ticks; quote increments would also need applying to any future quote-size order adapter.

## Fees and remaining acceptance

There is **no assumed Coinbase fee tier**. A policy can be recorded without fees, but each product remains unavailable until its explicit product-specific fee observation is provided. Inputs contain version, maker/taker decimal rates, source and observed/expiry times. Observation validity is capped at one day. Missing, future or expired schedules block entry evaluation. Maker rebates are not supported; negative rates are refused. October 23 correction: this evidence mode models the **greater of maker and taker rates** on both sides at 18-decimal ceiling precision; maker is not necessarily cheaper. Venue-specific execution rounding/tax/commission verification remains a C3/C5 acceptance requirement.

Coinbase documents that fees depend on product, account tier and liquidity role, including separate schedules for some pairs: [Advanced fees](https://help.coinbase.com/en/coinbase/trading-and-funding/advanced-trade/advanced-trade-fees), rechecked October 23. Its [transaction-summary GET](https://docs.cdp.coinbase.com/api-reference/advanced-trade-api/rest-api/fees/get-transaction-summary) exposes account fee-tier fields. The October 23 source command below supports this read, but a global account-tier rate must not be assumed to apply to every product. No real fee observation was imported during this increment.

### October 23 cost review follow-up (source only)

`mcp:crypto-fees --actor=<configured-operator-id> [--credential=<active-Coinbase-id>]` now reads exactly one fixed Coinbase GET: `/api/v3/brokerage/transaction_summary?product_type=SPOT&product_venue=CBE`. It reuses the existing signer and credential resolver, not the legacy order client. The host/path cannot be overridden by broker configuration; redirects and retries are disabled. Missing/wrong-venue credentials, malformed/oversized responses, negative or excessive rates and request/signing failures produce a generic failure without provider bodies or exception chains. No scheduler or network call was added to policy application or evaluation.

The command returns bounded review evidence: contract, exact decimal account rates, pricing tier, request/retrieval times, unknown source time, raw-response SHA-256 and review reasons. It omits balances, total fees, credentials and signed requests. Nonzero/unknown tax and cost-plus/unknown commission are explicit review blockers. Every successful report still requires product-applicability review; it is not an importable policy schedule and makes no database, cash, flag or order change.

Fee imports now require `"cost_scope": "product_all_in"`: the applying operator attests that each pair's rates include all applicable percentage charges and that no unmodeled fixed charge applies. Confirm product exceptions, taxes and cost-plus commissions using the signed-in venue fee page; do not set this field on an unreviewed account-tier report. Retain the report hash in the observation's source reference when using it. Timestamps must be explicit ISO 8601 strings with a timezone; ambiguous dates are refused. The policy retains this scope and the `maximum_maker_taker_v1` role assumption in its immutable encrypted payload and signal evidence.

At source implementation completion, actual account rates/product applicability had **not** been established. The subsequently authorized account-tier read is recorded below; product applicability and tax treatment remain unverified. C2's operational fee-verification gate remains open. Held-portfolio lifecycle, candle replay and forward fills remain C3 work. No new migration beyond pending `000029` is needed for this follow-up.

The updated policy contract is `crypto_paper_policy_v2`; stored evaluation is `crypto_stored_evaluation_v2`. Older source-only policy assumptions are refused before a cycle is created and require a new explicit policy version. There is no automatic policy rewrite or opening-cash credit.

Verification, October 23: complete SQLite suite **710 tests / 4,575 assertions, 669 passed / 41 skips**; complete guarded PostgreSQL suite **710 tests / 4,953 assertions, 709 passed / 1 existing FPM environment skip**. After the final policy/evaluator version guard, focused SQLite **25 tests / 189 assertions** and PostgreSQL **26 tests / 206 assertions**, all passed, including concurrent workers. Composer validation, changed-source Pint and diff whitespace checks passed. No frontend assets or dependencies changed. Tests prove fixed GET scope, redacted errors, no retry, no policy/cash/flag effect, unsupported cost/tax review reasons, all-in product scope, explicit timezones, maker-more-expensive sizing and refusal of incompatible policy versions. Actual authenticated fee-tier connectivity and pair-specific applicability remain unverified; fake responses are not live fee evidence.

The pure `PortfolioEntryLimits` gate supports unknown marks/baseline, an inclusive daily loss threshold, a latched pause and the open-position cap. C2 evaluation values only an unchanged, flat opening ledger. Once any paper order exists or the ledger changes, entries are unavailable until C3 supplies verified marked equity, the UTC opening baseline, episode facts, pending-order capacity and persistent daily pause handling. C2 creates neither reservations nor fills; multiple candidate signals are not promises that all can be bought.

The runtime now recognizes both crypto key pairs, verified through presence booleans only. No credential values were printed or copied. The IDE selection exposed private keys in the conversation; owner rotation was advised and has not been verified. No further authenticated broker request used those keys. Account monitoring/reconciliation and Robinhood key permission verification remain incomplete. Runtime crypto collection and all live write flags remain off.

## Migration and verification

Migration `000029` adds asset/venue identity to the four shared evidence/portfolio tables, an optional local portfolio owner, a policy table, crypto profile portfolio linkage and cycle policy linkage. It widens profile/signal symbols to 24 characters and permits null agentic connection IDs for crypto only. Existing rows default to equity / Robinhood agentic; existing cash and payloads are not rewritten. PostgreSQL checks enforce instrument/connection identity; partial unique indexes preserve crypto idempotency despite null connection IDs. The migration is forward-only: automatic rollback refuses rather than deleting legitimate crypto data to restore NOT NULL. Review the DDL and take a verified backup before runtime deployment.

Tests use fakes and the guarded `mcp_trading_test` database. New coverage includes exact fractional/tiny prices, non-power-of-ten increments, fee/cash/risk bounds, loss/position thresholds, immutable/encrypted policy and signal history, fee-input injection, expired/unknown fees, metadata restrictions, spread/liquidity checks, microsecond freshness, future input availability, missing days/benchmark, conflicts, retry freezing, operator isolation, transactional recovery, disabled defaults and concurrent policy/evaluation workers. Existing equity tests remain in the complete suites.

Verification: Composer validation, Pint and `git diff --check` passed. Complete suites after the core increment: SQLite **704 tests / 4,495 assertions, 663 passed / 41 skips**; PostgreSQL **704 tests / 4,873 assertions, 703 passed / 1 existing FPM environment skip**. A final metadata currency-identity guard was then covered by focused runs: SQLite **18 tests / 106 assertions** and PostgreSQL **19 tests / 123 assertions**, all passed, including concurrent policy/evaluation retries. No frontend assets changed. No real brokerage mutation or runtime migration was used as a test.

Next: C3 deterministic replay and forward-paper integration, including position episodes/exits, persistent UTC baselines and loss pauses, fee verification, actual paper handoff/fills and separate crypto dashboard reporting. Nothing in C2 completes C3–C7 or establishes strategy profitability.

## Authorized live read-only fee-tier check — October 23

After the owner explicitly approved the one-time read, the new fixed-GET gateway was loaded from the development source into a foreground process using the installed runtime's existing bootstrap, configured operator and credential resolver. No C2 deployment was needed. The development checkout had no configured operator; its operator lookup returned none. Runtime operator/credential lookups used a read-only PostgreSQL transaction. No credentials were printed or copied, and no browser/ChatGPT credentials were used.

Exactly one Coinbase fee-tier GET completed with HTTP **200**, request start **2026-10-23T11:59:57.350248Z**, retrieval **2026-10-23T11:59:57.796147Z** (06:59:57 Central). Query scope was `product_type=SPOT&product_venue=CBE`. The redacted report returned:

| Field | Observed value |
|---|---|
| Pricing tier | `Intro` |
| Maker fee rate | `"0.005"` (0.50%) |
| Taker fee rate | `"0.009"` (0.90%) |
| Status | `requires_product_review` |
| Reasons | `product_applicability_requires_owner_review`, `tax_unknown_or_requires_all_in_review` |
| Provider source time | Unknown; retrieval time is recorded separately |
| Raw-response SHA-256 | `2e58aac1f8e8035986119a2fd201b3d666d2ffea035c5b1e0111b60a6025edeb` |

These are observed account-tier rates, not verified all-in fees for any individual pair. Tax treatment remains unresolved; the report does not establish that tax is zero or that an additional tax applies. Do not import an all-in product schedule from this report alone. No policy, virtual cash, signal, order, fill, migration, deployment, credential or flag change was made. The helper only performed database reads and the one fixed remote GET; no order or preview endpoint was used.

C2 now has verified authenticated fee-tier connectivity and actual account-rate evidence. Remaining fee acceptance: confirm pair applicability and tax/commission treatment against the venue's signed-in product fee information, then explicitly apply current product-specific observations. The one-day policy validity limit still applies; this observation cannot become an indefinite fee default. No tests were rerun for this evidence-only follow-up; the preceding source verification remains applicable.
