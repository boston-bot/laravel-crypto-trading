# Crypto C1: read-only Coinbase market data — evidence

Status: **source complete October 22, 2026; not deployed or enabled.** Part of the [crypto automation plan](CRYPTO-AUTOMATION-PLAN.md). Collects no account data and produces no signals or orders.

## Owner decisions applied

- Coinbase is the first venue (October 22).
- No API key will be added for now (October 22). C1 therefore uses only Coinbase Advanced Trade **public** market endpoints, with no credentials. Account balances, order history, unrecognized-order detection and reconciliation need an authenticated read. They stay unavailable until a key exists, and they are prerequisites for C5/C7.

## What was built

| Component | Behavior |
|---|---|
| `CoinbasePublicMarketData` | Fixed host `https://api.coinbase.com`, GET only, no `Authorization` header or signing. Three paths: batched `market/products`, single-product `market/product_book?limit=1` and `market/products/{id}/candles`. Product IDs must match `^[A-Z0-9]{1,12}-[A-Z0-9]{1,12}$` before any request. 2 MiB response limit; numbers kept as exact decimal strings; redacted reason codes only. |
| Contract checks | Required fields and types for products; unrequested or duplicate products reject the read; blank 24-hour statistics stored as unknown (null), not zero. A crossed or empty book, or a source time more than 30 s ahead of local time, is not an observation. Candles must be aligned to their granularity, positive and internally consistent. |
| Closed candles only | The venue returns the still-open candle (observed live), so candles ending after retrieval are discarded. Long ranges are split into half-open requests of at most 300 candles. |
| `CryptoMarketCollector` | Validates the configured list (1–25 unique IDs), takes a cache lock and records a `running` read. It fetches products in one batch, then per product: best bid/ask, the trailing 120-minute five-minute window and, when yesterday's UTC daily candle is missing, daily candles back to 400 days. Each product's snapshot, quote and candles are stored in one transaction or not at all. The read ends `complete`, `partial` or `failed` with a short error code, request count, candles inserted and conflicts. |
| Candle provenance | Unique by venue/product/granularity/start. The first observation is kept with its read ID. A later differing observation is counted in `candle_conflicts` on its read and never applied. |
| `CryptoMarketData` | `freshQuote()` returns null when no quote exists or the newest source time is older than `quote_max_age_seconds` (120). `candles()` returns stored closed candles. `health()` feeds a new `crypto_data` operations check: `disabled` while off; `critical` with no complete read in 15 minutes; `warning` when the latest read was partial or failed. |
| `mcp:crypto-market --once [--scheduled]` | One read; JSON outcome; exit 1 on partial, failed or invalid configuration. Scheduled every 5 minutes, 24/7, only when `CRYPTO_MARKET_DATA_ENABLED=true`, with `withoutOverlapping(10)`. |
| Migration `2026_10_22_000028` | Additive, no backfill: `mcp_crypto_market_reads`, `mcp_crypto_product_snapshots`, `mcp_crypto_quotes`, `mcp_crypto_candles` in `mcp_trading`. Decimals `numeric(38,18)`, times `timestamptz(6)`. PostgreSQL CHECK constraints: venue/trigger/status sets, `bid > 0 AND ask >= bid`, candle granularity set and price consistency. |

Settings (`config/crypto.php`): `CRYPTO_MARKET_DATA_ENABLED` (default false); `COINBASE_MARKET_DATA_PRODUCTS` (comma-separated, default empty). Both test configurations pin these off.

## Live contract review (October 22, 2026, unauthenticated, read-only)

A preliminary probe established the response shapes: unknown IDs are silently omitted from `market/products`; `product_book` carries a microsecond source `time`; candles are returned newest first and include the open candle; and there is no public batch best-bid/ask endpoint (`market/best_bid_ask` returned 404).

After implementation, the gateway alone ran against the live API (no database writes): `products(BTC-USD, ETH-USD, NOPE-USD)` returned BTC-USD and ETH-USD (status `online`, base increment `0.00000001`). Best bid/ask was BTC-USD 81319.09/81319.1 and ETH-USD 2386.2/2386.29, with source ages of 0.43 s and 0.10 s. There were 24 closed five-minute candles, the last ending 23:55 UTC at 23:59:47. There were 400 closed daily candles (2025-09-17 to 2026-10-21) from two requests. Six requests in total.

## Tests

- `CoinbasePublicMarketDataTest` (8): request shape and no authorization header; unknown, unrequested, duplicate and malformed products; ID validation before any request; exact quote decimals and source time; crossed, empty, future, mismatched, 429 and 503 books without provider text; closed-only ascending candles; daily chunking; misaligned and inconsistent candles.
- `CryptoMarketCollectorTest` (11): disabled, empty, invalid, duplicate and over-cap configuration with no request or record; a complete read with exact request count, 24 five-minute and 400 daily candles and no open candle; repeated reads without duplicates or daily re-reads; revised candles counted and not applied; an unlisted product and a per-product failure leave that product with no data; metadata failure; a held lock; quote staleness at exactly the maximum age; health transitions; the command.
- `CryptoMarketDataPostgresTest` (3, PostgreSQL only): schema placement; exact `0.00000001` / `150000000` and microsecond round trip; trailing-zero numerics not reported as conflicts; CHECK and unique constraints. It uses per-test transactions, not `RefreshDatabase`: an early `migrate:fresh` would move the suite's existing fixture wipe and leak other integration tests' committed rows into Feature tests (observed, then fixed).
- `OperationsTest` now expects the `crypto_data` check (disabled by default).

Results on the working tree, which also contains another session's uncommitted paper-dashboard work: SQLite **645 passed / 40 PostgreSQL-only skips**; PostgreSQL **685 tests / 4,751 assertions, 1 existing environment skip**. Composer validation and Pint passed on the changed files.

## Deployment (owner action; not performed)

1. Take the normal encrypted backup and review the pending migration, then deploy the committed release with `deploy.sh <committed-release> --migrate`. Only `2026_10_22_000028` should be new for this increment.
2. Set `COINBASE_MARKET_DATA_PRODUCTS` (for example `BTC-USD,ETH-USD`; at most 25) and `CRYPTO_MARKET_DATA_ENABLED=true`, then recache the configuration. This is a data-collection list, not a trading universe.
3. Run `php artisan mcp:crypto-market --once` and expect `"status":"complete"`. Check the **Health** page for `crypto_data` = `ok`.

Disable by setting `CRYPTO_MARKET_DATA_ENABLED=false`; stored data remains.

## Limitations

- No authenticated Coinbase reads (owner decision), so no balances, orders or unrecognized-order detection.
- Best bid/ask is one request per product (no public batch endpoint); 25 products cost about 51–76 requests per cycle. Public rate limits were not measured; a 429 marks the product, and so the read, partial.
- Unattended 24/7 operation (sleep, restart, overnight alerting) is C6 work. The schedule runs whenever the Mac's scheduler runs.
- Nothing consumes this data yet; eligibility, profiles and rules are C2.
