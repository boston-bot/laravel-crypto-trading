# M5 listing, daily-history and liquidity sources

October 19, 2026. Fills the provider gaps left by [deterministic eligibility](M5-RULE-ELIGIBILITY-EVIDENCE.md): reviewed US exchange-listing evidence, 252 sessions of split-adjusted daily history, and the 20-session average dollar volume. Source, tests and one bounded live read-only check. No deployment, runtime migration, settings change or enablement.

## Source selection

No reviewed Robinhood tool supplies daily bars or the listing exchange: the reviewed operations cover accounts, portfolio, positions, orders, search, quotes, tradability, price book, stock/ETF type, dividends and watchlists. The design names Alpaca as the leading candidate, and the owner's Alpaca development keys already work for corporate actions, so both sources use existing provider access. No subscription was bought.

| Fact | Endpoint | Reviewed contract |
|---|---|---|
| Daily history and dollar volume | `GET https://data.alpaca.markets/v2/stocks/bars` | `alpaca_bars_v2_1day_sip_split_v1`: `timeframe=1Day`, `adjustment=split`, `feed=sip` (consolidated volume, not one venue's share), `sort=asc`, `limit=10000`, symbols batched 25 per request |
| US exchange listing | `GET /v2/assets/{symbol}` on `paper-api.alpaca.markets` (paper keys) or `api.alpaca.markets` (live keys) | `alpaca_assets_v2_listing_v1`: `class`, `exchange`, `status`; documented exchange values AMEX, ARCA, BATS, NYSE, NASDAQ, NYSEARCA, OTC, CRYPTO |

Official references: [historical bars](https://docs.alpaca.markets/reference/stockbars), [asset by symbol](https://docs.alpaca.markets/reference/get-v2-assets-symbol_or_asset_id), [market-data plans](https://docs.alpaca.markets/docs/about-market-data-api). The free plan includes historical data since 2016 and excludes only the latest 15 minutes, at 200 historical requests per minute. The asset endpoint is a reference lookup on Alpaca's account host. The adapter can only issue that one GET path: no account, position or order endpoint is reachable.

## Changed behavior

**Daily bars (`DailyBars`).**
- **Window:** only completed sessions are requested. The window ends the New York day before the as-of time and starts 400 calendar days earlier.
- **Exact numbers:** numbers are kept as their exact JSON lexemes; exponent forms and other non-plain decimals are refused.
- **Bar checks:**
  - exactly the reviewed fields `t o h l c v vw n`
  - stamped at New York midnight and inside the window
  - strictly increasing dates per symbol across pages
  - positive prices, with the low no higher and the high no lower than the open and close
  - non-negative integer volume and trade count
  - USD only
- **Batch refusal:** an unrequested symbol, a repeated or current-session bar, a malformed envelope, a repeated cursor or a page or size limit fails the whole batch.
- **Errors:** provider error bodies are never stored.

**Listing (`Listings`).**
- **Pass:** active `us_equity` on a reviewed national exchange.
- **Known failures:** OTC, inactive, or non-equity class.
- **Unknown:** an asset this provider doesn't know (404), an unreviewed exchange value or a symbol mismatch. A 404 is not proof that a symbol is unlisted.
- **Stored fields:** only `class`, `exchange`, `status` and `fractionable`; Alpaca's own tradability and margin flags aren't kept.

**Evidence (`PaperMarketReferences`, `mcp_market_reference_reads`).** For each symbol and kind there is one immutable encrypted record, scoped to the connection and account like SEC classification, with deterministic request IDs. Network reads happen outside transactions; each record is written under a fresh operator, binding and authorization check. History facts are computed once, exactly, at record time:
- `sessions` (bar count) and first and last session
- `average_dollar_volume_20`: the mean of split-adjusted close × volume over the last 20 sessions, rounded down at cents so rounding can never meet a minimum

Fewer than 20 bars leaves the average unknown. A complete response with no bars is recorded as incomplete (`no_bars_returned`), never as zero history. Failed reads are recorded too, and never hide the last complete evidence.

**Eligibility.** `RuleEligibility` now takes listing and history only from complete records stored by the cutoff:
- **History:** retrieved within `history_max_age_hours` (30), and its last bar within 10 days of the window end. Otherwise `daily_history_stale`, `daily_history_not_current` or `daily_history_missing`.
- **Listing:** within `listing_max_age_days` (7). Otherwise `us_exchange_listing_stale`.

Assessment evidence records the listing and history read IDs and the history contract. With collection off, the existing `daily_history_source_unconfigured` and `us_exchange_listing_unverified` reasons are unchanged. The rule version stays `equity_eligibility_v1`: the checks, thresholds and their meaning are unchanged; only their source is new. Payload, watchlist and model claims still cannot supply these facts.

**Collection.** `php artisan mcp:market-reference --account=<local-id> [--symbol=…]` reads the selected universe, and `--scheduled` runs the idempotent daily refresh. When `ALPACA_DATA_ENABLED`, the new `ALPACA_REFERENCE_ENABLED` and `MCP_PAPER_MARKET_COLLECTION_ENABLED` are all on, the scheduler runs it at 06:40 New York every day. `ALPACA_ACCOUNT_ENVIRONMENT` (`paper` by default) selects the asset host. The command prints facts and identifiers only.

## Bounded live check

A one-off process ran the app's own adapters with the development paper keys: one bars request and four asset lookups, with no database writes and no configuration file changed. October 19, 07:30 Chicago:

| Symbol | Listing | Sessions (window 2025-09-13 to 2026-10-18) | Last session | 20-session average dollar volume |
|---|---|---|---|---|
| AAPL | NASDAQ, listed | 275 | 2026-10-16 | $12,542,449,352.23 |
| SPY | ARCA, listed | 275 | 2026-10-16 | $32,450,207,791.22 |
| BRK.B | NYSE, listed | 275 | 2026-10-16 | $2,157,079,023.69 |
| TCEHY | OTC, not listed | none (no SIP bars for an OTC symbol) | — | — |

An earlier raw probe confirmed the response fields, terminal `null` page tokens, New York-midnight stamps, and that today's bar is absent before the session. These are real provider observations, not a claim about any symbol's eligibility: no owner policy is applied at runtime, and the runtime has neither this code nor the table.

## Verification

| Check | Result |
|---|---|
| Full SQLite | **438 passed / 3,154 assertions, 30 PostgreSQL-only skips** |
| Full PostgreSQL | **467 passed / 3,433 assertions, 1 existing PHP-FPM environment skip** |
| `composer validate`, Pint, `git diff --check` | Passed |

New tests:
- **`MarketReferencesTest`** (10 tests / 110 assertions):
  - query shape and exact lexemes; multi-page and multi-batch joining
  - eleven malformed or untrusted response cases, a repeated cursor and the page limit
  - HTTP and transport failures, gates and credentials with no network
  - listing pass, OTC, inactive, non-equity, unreviewed exchange, mismatch, 404, 429 and the live host
  - immutable encrypted idempotent records with an exact average
  - failed reads keeping prior evidence; point-in-time cutoffs and expiry; stopped bars
  - operator, universe and gate refusals; daily tick idempotency; command output
- **`RuleEligibilityTest`:** a fully verified synthetic symbol now becomes `eligible` with a recorded assessment. A young, thinly traded OTC symbol fails listing, history and liquidity, each on its own recorded reason, and stale history becomes `daily_history_stale`.
- **`MarketReferenceConcurrencyTest`** (PostgreSQL, separate processes): three duplicate workers record exactly one row per kind, the table is placed in `mcp_trading`, and the state check rejects an unknown kind.

## Limits and remaining work

- **Deployment needed:** migration `000020` and these flags must be deployed and enabled, and the owner must apply an eligibility policy, before any real symbol can be assessed `eligible`. All other checks still apply, including fresh broker quotes and tradability within the 20-second quote age.
- **Storage growth:** each daily history record stores the 400-day window, about 20 KB encrypted per symbol per day. At 75 symbols that is roughly 1.5 MB a day; a retention policy is not implemented.
- **Unverified edge cases:**
  - the batch size limit (25 is a local bound)
  - Alpaca's symbol mapping for renamed tickers
  - how the provider adjusts bars after a split lands mid-window; each daily read is a fresh adjusted snapshot, so it does not mix bases
- **Different provider:** listing evidence comes from Alpaca, not Robinhood or an exchange file. Disagreement with Robinhood's instrument is not detected beyond requiring the same symbol.
- **Still missing for the strategy:**
  - an earnings-date source
  - calendar automation
  - daily profiles, signals, sizing and realistic costs
  - collection of every symbol each 5-minute cycle

## Migration and configuration impact

Additive migration `2026_10_19_000020_create_mcp_market_reference_reads.php` creates one table: unique request identity, a history index and a PostgreSQL kind/status check. No backfill. New environment settings `ALPACA_REFERENCE_ENABLED=false` and `ALPACA_ACCOUNT_ENVIRONMENT=paper`, both forced in the test configurations. Next M5 work: an earnings-date source and calendar coverage, then daily profiles and signals.
