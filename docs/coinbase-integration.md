# Coinbase Integration Notes

## Platform Scope

The codebase now treats Coinbase Advanced Trade as a first-class broker for:

- Account sync (`/api/v3/brokerage/accounts`)
- Product/asset sync (`/api/v3/brokerage/products`)
- Best bid/ask quote sync (`/api/v3/brokerage/best_bid_ask`)
- Candle sync (`/api/v3/brokerage/products/{product_id}/candles`)
- Historical order sync (`/api/v3/brokerage/orders/historical/batch`)
- Live order submit (`/api/v3/brokerage/orders`)
- Order reconciliation (`/api/v3/brokerage/orders/historical/{order_id}`)

Implementation note: this Laravel runtime uses native PHP broker clients for execution and sync. The installed `@coinbase/cdp-sdk` package remains available for optional Node-based tooling/scripts.

## Additional Advanced Trade APIs Available

The Coinbase Advanced Trade surface also includes endpoints that are not yet wired into this strategy runtime, such as:

- Order previews, cancellations, and fill history
- Product books and market trades
- Portfolio management and portfolio fund moves
- Convert quote + convert commit
- Payment methods and key permissions
- Public market endpoints and WebSocket feeds for lower-latency streaming

## Authentication Model

Coinbase requests are signed with a short-lived JWT generated per request using:

- `sub` = `COINBASE_API_KEY`
- `iss` = `cdp`
- `uri` = `<METHOD> <HOST><PATH>`
- `kid` header = `COINBASE_API_KEY`
- `nonce` header claim

The signer supports:

- ES256 PEM private keys
- Ed25519 base64 private keys (if sodium is available)

## Runtime Modes

- Default broker is now `coinbase` (`BROKER=coinbase`).
- Robinhood remains fully available by selecting `--broker=robinhood` in commands or `?broker=robinhood` on broker endpoints.

## Operational Commands

- `php artisan broker:sync --broker=coinbase --sync`
- `php artisan broker:harvest --broker=coinbase --timeframes=1d,4h --interval=60`
- `php artisan broker:health-check --broker=coinbase`
- `php artisan broker:test-coinbase`
- `php artisan strategy:evaluate --broker=coinbase --sync`
- `php artisan trading:scorecard --broker=coinbase --days=30`

## Connectivity Prerequisites

Set these in `.env`:

- `COINBASE_API_KEY`
- `COINBASE_API_PRIVATE_KEY`
- `COINBASE_BASE_URL=https://api.coinbase.com`
