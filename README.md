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
- PostgreSQL durable strategy-engine jobs with a canonical Python point-in-time feature, calibration, and portfolio replay package.
- Public Coinbase/Kraken Level 2 shadow collection with executable-depth and after-cost spread classification.
- Immutable strategy/universe versions, research manifests, candle revisions, fee snapshots, and Alternative.me sentiment ablation data.

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
php artisan broker:harvest --broker=coinbase --timeframes=1d,4h --interval=60
php artisan broker:health-check --broker=coinbase
php artisan broker:test-coinbase
php artisan strategy:evaluate --sync
php artisan trading:scorecard --broker=coinbase --days=30
php artisan trading:freeze on
php artisan trading:approve-decision <decisionId>
php artisan broker:reconcile-orders --sync
php artisan research:backfill-candles --years=5
php artisan research:backtest --start=2020-01-01
php artisan research:maintain-market-data
php artisan trading:runtime
```

## Trading Operations Console

Open the local-only console at `/dashboard`. It is split into focused views:

- `/dashboard` — latest cycle, outcome explanation, paper summary, and system summary
- `/strategies` — immutable versions, backtests, and recent strategy runs
- `/assets` — per-asset paper contribution beside Coinbase buy-and-hold context
- `/activity` — append-only evaluation, proposal, fill, and operational evidence
- `/paper` — virtual or mirrored session funding, cash ledger, positions, and proposals
- `/operations` — processes, queues, pipeline cycles, and safe runtime controls
- `/research` — point-in-time data health, calibration, backtests, and shadow spreads

The console polls every 10 seconds while visible and every 60 seconds in the background. A failed refresh keeps the last successful view and marks it stale.

Start all local long-running workloads with one command:

```bash
php artisan trading:runtime
```

It supervises the Laravel scheduler, Laravel queue worker, Python strategy worker, and public feed collector. The Operations page can pause, resume, or restart only these allowlisted processes. The scheduler requests a coalesced paper pipeline cycle every minute; trading evaluation remains idempotent per newly closed UTC 4-hour bar.

Before paper orders can be created, open `/paper` and create either a virtual-capital session or a mirror session based on a fresh Coinbase equity snapshot. Mirror mode copies the equity value into paper USD cash; it does not copy live positions.

You can still seed account and historical data directly:

```bash
php artisan broker:sync --broker=coinbase --sync
php artisan research:backfill-candles --years=5
```

Notes:
- `HOLD` is an explicit evaluation result and never creates a trade decision.
- Database-engine proposals expire after 30 minutes and are revalidated at approval time.
- Paper fills post principal and fees to an append-only session ledger before portfolio valuation.
- Live approvals stay disabled in this local console; no implementation path unlocks live mode automatically.
- Keep `BROKER_MODE=paper` while evaluating strategy behavior.

## API Endpoints

- `GET /api/trade-decisions/pending`
- `POST /api/trade-decisions/{tradeDecision}/approve`
- `POST /api/trade-decisions/{tradeDecision}/reject`
- `GET /api/research/data-health`
- `GET /api/research/backtests`
- `GET /api/research/calibration`
- `GET /api/research/spreads`
- `GET /api/ops/v1/overview`
- `GET /api/ops/v1/strategies`
- `GET /api/ops/v1/assets`
- `GET /api/ops/v1/activity`
- `GET /api/ops/v1/paper`
- `GET /api/ops/v1/operations`
- `GET /api/ops/v1/research`

## Safety Defaults

- `BROKER=coinbase`
- `BROKER_MODE=paper`
- `TRADING_ENABLED=false`
- `HUMAN_APPROVAL_REQUIRED=true`
- `TRADING_ALLOWED_ASSETS=BTC,ETH,SOL,LINK,LTC`
- `STRATEGY_ENGINE_DRIVER=legacy` until Python parity and the paper soak are complete

See [docs/research-pipeline.md](docs/research-pipeline.md) for local containers, the engine/collector security boundary, backtest gates, and the ECS/RDS deployment mapping.

## Promotion Gate

Use the scorecard command to decide go/no-go for live rollout from objective evidence:

```bash
php artisan trading:scorecard --broker=coinbase --days=30
php artisan trading:scorecard --broker=coinbase --days=30 --json
```

The scorecard evaluates:

- Recent completed backtest metrics (Sharpe, drawdown, win rate, profit factor)
- Paper trading performance over the requested window (returns, Sharpe, drawdown, hit rate, slippage)
- Operational reliability (critical risk events, rejection rate, data freshness)

## Continuous Harvest

Use the long-running harvest command to keep sync processes running continuously:

```bash
php artisan broker:harvest --broker=coinbase --timeframes=1d,4h --interval=60
```

Useful controls:

- `--max-cycles=1` run one cycle and exit
- `--max-runtime-seconds=3600` run for one hour
- `--sleep-on-error=20` backoff between failed cycles

For true 24/7 operation, run it under a process manager such as Supervisor:

```ini
[program:laravel-broker-harvest]
command=php /path/to/artisan broker:harvest --broker=coinbase --timeframes=1d,4h --interval=60
directory=/path/to/laravel-crypto-trading
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
stdout_logfile=/path/to/storage/logs/broker-harvest.log
stderr_logfile=/path/to/storage/logs/broker-harvest-error.log
```
