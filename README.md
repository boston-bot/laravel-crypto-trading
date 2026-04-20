# Brevix Crypto Trading (Coinbase + Robinhood)

Laravel service foundation for Coinbase Advanced Trade and Robinhood Crypto automation with strict risk guardrails, full decision journaling, paper mode, and approval-gated live mode.

## What Is Implemented

- Broker domain models, enums, and migrations for credentials, accounts, assets, positions, orders, strategy runs, trade decisions, policy checks, and risk events.
- Broker integration layer for Coinbase (`CoinbaseClient`, JWT signer, mapper) and Robinhood (`RobinhoodClient`, signer, mapper) with queued sync jobs.
- Strategy + risk + policy pipeline that creates deterministic trade decisions with full context capture.
- Paper execution flow with simulated fills and live execution flow with approval gating.
- Kill switch command and order reconciliation workflow.
- API endpoints and artisan command for human approval/rejection.
- Strategy run skill metadata capture from project-level `.agents/skills/*`.

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Core Commands

```bash
php artisan broker:sync --broker=coinbase --sync
php artisan broker:sync --broker=robinhood --sync
php artisan broker:sync-market-data --broker=coinbase --timeframe=1d --sync
php artisan broker:health-check --broker=coinbase
php artisan broker:test-coinbase
php artisan strategy:evaluate --sync
php artisan trading:freeze on
php artisan trading:approve-decision <decisionId>
php artisan broker:reconcile-orders --sync
```

## API Endpoints

- `GET /api/trade-decisions/pending`
- `POST /api/trade-decisions/{tradeDecision}/approve`
- `POST /api/trade-decisions/{tradeDecision}/reject`

## Safety Defaults

- `BROKER=coinbase`
- `BROKER_MODE=paper`
- `TRADING_ENABLED=false`
- `HUMAN_APPROVAL_REQUIRED=true`
- `TRADING_ALLOWED_ASSETS=BTC,ETH`
