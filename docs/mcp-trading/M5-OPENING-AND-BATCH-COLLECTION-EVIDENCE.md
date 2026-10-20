# M5 opening valuations and batched collection

October 19, 2026. M5 source increment; migration `000025` and this increment are not deployed or enabled. No runtime configuration, policy, paper balance or brokerage approval was changed. M6 remains unstarted.

## Deployment checkpoint

After the owner reported deployment, development and frozen runtime checkouts were clean at `80225da`. Runtime migrations `000023` and `000024` were applied as batch 10. Doctor and loopback `/ready` checks passed. Runtime uses the restricted application role in `postgres` / `mcp_trading`; no assumption that schema and database names match was made. Read-only inspection found no applied signal execution policy, $500 cash, $0 reserved and no paper positions. Signals, signal paper handoff, real-data paper fills, AI and brokerage writes remained disabled. This supersedes earlier source-only deployment statements in the signal evidence files.

## Opening reference

`PaperSessionValuations` records immutable encrypted evidence for one paper portfolio, authorization generation and exchange date. `opening_minute_bid_unchanged_ledger_v1` values cash plus positive paper positions at stored bid quotes whose source and quote retrieval times both fall in the first minute of the verified session. This is a liquidation reference from the opening minute, not an official opening-auction price. It reuses the existing broker collector and adds no provider or subscription.

The portfolio must predate the open. Until first capture, any paper cash entry, fill, corporate-action application/entitlement/block since the open prevents reconstruction from current accounting. Blocked positions, negative quantities, dividend receivables, unknown calendar, wrong account authorization, missing/late/future quotes and unverified observed corporate actions fail closed. Evidence can be captured later only if accounting has stayed unchanged since the open. Equity uses exact decimals and explicit half-even rounding to the ledger's 18 places; the fingerprint records the inputs and rounding rule.

Connection and portfolio row locks serialize capture with paper accounting. A unique database key prevents duplicate captures. Once captured, the opening reference stays fixed despite subsequent trades. Authorization changes cannot reuse it. The combined paper cycle attempts capture before fills; the batch collector attempts it after collection. `MCP_PAPER_SESSION_VALUATIONS_ENABLED` defaults false.

Buy handoff records the valuation ID and rule in its baseline evidence. Submission independently resolves the baseline and verifies its amount, excluding the candidate order itself to prevent circular validation. Existing unchanged cash-only inception and same-session verified signal-order baselines remain supported; same-session reuse now also checks authorization generation and rejects future acceptance times. Missing held-portfolio opening evidence blocks new buys. Exits still use `not_required_for_exit`; their price, accounting and permission checks remain independent.

## Batched collection

`MCP_PAPER_BATCH_COLLECTION_ENABLED` defaults false and also requires the existing input/collection gates. `mcp:market-cycle --once [--account=<local-id>]` attempts the selected watchlist/holding read universe, with holdings first and oldest observations first within each priority group, rather than rotating only five symbols per tick. This allows incomplete cycles to make progress on older unheld symbols. Nominating/data-only role limits and entry policy are unchanged. A local 250-symbol processing ceiling rejects oversized universes instead of silently dropping holdings.

Quotes use the existing reviewed batch adapter, five symbols per chunk with a local ceiling of 25. Instruments, tradability, displayed book and optional reference/action evidence keep their supported exact-symbol contracts. The cycle shares a broker session/catalog context; the gateway may renew a session when its own limits require it. No unsupported bulk API is invented. Same-window UUIDs reuse immutable per-symbol records. Missing symbols retain unknown prices. Collection never evaluates signals, places orders or fills paper trades.

The scheduler chooses the batch command instead of the old collector when enabled, every five minutes in verified regular sessions and hourly otherwise. The old dispatcher and queued jobs skip while batch mode is selected. Collection uses the existing cache lock, checks broker backoff before reads, and enforces a monotonic 240-second processing budget. One in-flight provider request can finish after the deadline. Auxiliary reads stop when the budget/backoff guard fails. Unattempted symbols remain missing or stale; they do not receive guessed quotes. Quote source/retrieval times stay independent of later enrichment timestamps. Execution's 20-second freshness rule is unchanged.

This implements bounded collection attempts, not measured fresh coverage of 150 symbols. Live throughput, opening-minute acquisition under provider latency, book matching and throttling still need observation before paper activation. A missed opening window means no new held-portfolio entries that day; it is not repaired with a later price.

## Fractional contract and replay limits

Read-only inspection of the saved runtime MCP capability catalog found quantity/type/time-in-force strings for `place_equity_order` and `review_equity_order`, without fractional LIMIT permission, precision or supported-combination guarantees. This does not establish the agentic account's fractional contract. No review, approval or submission call was made. Whole shares remain required. At $500 equity and a 20% position cap, an instrument above the $100 position budget can still produce zero quantity despite full eligibility.

Historical replay/parameter confirmation remains a separate M5 acceptance item. Current adjusted daily-bar history does not reconstruct historical watchlist revisions, contemporaneous earnings knowledge, intraday bid/ask observations, book liquidity or accounting. Reusing today's data at old decision cutoffs would introduce information unavailable then. No trade-performance result, historical P&L, optimized parameter set or replay readiness is claimed. The backtesting skill's no-lookahead guidance was used for this assessment; repository instructions require the existing PHP rule engine and exact decimals, so no Python strategy engine or additional market-data package was introduced.

## Persistence and verification

Migration `2026_10_19_000025_create_mcp_paper_session_valuations.php` adds one table, `mcp_paper_session_valuations`, with encrypted evidence, positive exact-decimal equity, immutable provenance and a unique portfolio/generation/date key. No existing records are modified or backfilled. Only the guarded test database was migrated. Deploy with the existing separate schema-owner procedure; both new flags remain false unless deliberately configured. No frontend assets or dependency versions changed.

Tests use fake broker/provider clients and refuse unexpected HTTP. Coverage includes exact bid valuation and encrypted evidence, unchanged-ledger reconstruction, immutable reuse after cash movement, late/future/old-authorization quotes, missing action coverage, blocked accounting, batched holdings priority/oldest-evidence rotation, shared session, same-window reuse, missing-symbol isolation, rate limits, budget exhaustion, preserved stale quote times, separate gates, old queued-job suppression and off-session cadence. Three PostgreSQL workers verify one committed valuation. Existing handoff/fill/exit and collector tests remain in the regression suite.

Final test totals are recorded in the [development checklist](DEVELOPMENT-CHECKLIST.md). Initial fixture helper/required-field mistakes and command placement after the legacy-schedule early return were corrected. A later overlapping PostgreSQL test invocation caused interference in the dedicated test database; both runs were stopped and PostgreSQL was rerun alone. Operator data was untouched. No live collection cycle, opening capture, paper activation, populated restore of the new evidence or browser review is claimed.

Next within M5: measure read-only cadence and opening capture after deployment; implement point-in-time replay and validate starting parameters; establish the fractional LIMIT contract; apply reviewed signal execution settings before controlled forward paper evaluation. Combined M5 acceptance remains open. Live execution is a separate milestone and remains unauthorized.
