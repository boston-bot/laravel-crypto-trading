# Research-first crypto pipeline

## Safety boundary

Coinbase is the only order venue. Kraken is public market-data comparison only. Spread observations never create trade decisions and `research.spread.execution_enabled` is hard-coded `false`. Live mode remains disabled unless an operator separately sets `BROKER_MODE=live` and `TRADING_ENABLED=true`; every live proposal still requires a fresh human approval.

Only Laravel receives Coinbase credentials. The Python engine and feed collector receive `DATABASE_URL` plus public WebSocket URLs. They have no broker secrets or order interface.

## Local processes

For the native local app, start the scheduler, queue worker, Python engine, and public collector under the allowlisted control agent:

```bash
php artisan trading:runtime
```

The local-only `/operations` page reports heartbeats and can request pause, resume, or restart. Those requests are durable database records fulfilled by the control agent; the web process never launches arbitrary commands.

For the containerized layout:

```bash
cp .env.example .env
docker compose -f compose.research.yml up -d postgres
php artisan migrate
docker compose -f compose.research.yml up -d web scheduler queue engine collector
```

Parity, causality, and end-to-end contract gates now pass for the canonical seam, so paper defaults to `STRATEGY_ENGINE_DRIVER=database`. The legacy adapter remains available only for diagnostics. This setting changes signal computation, not the live-trading switches.

Backfill and test:

```bash
php artisan research:backfill-candles --years=5
php artisan research:backtest --start=2020-01-01
php artisan trading:scorecard --broker=coinbase --days=56 --json
```

The backtest reserves the latest 12 months as a locked holdout. Earlier data uses anchored 24-month train, six-month validation, and six-month test windows, rolled every six months with a 30-day embargo. Normal and stressed costs are reported separately.

## Persistent contracts

- `engine_jobs` is the durable handoff. Workers claim with `FOR UPDATE SKIP LOCKED`, commit the lease, compute outside the transaction, heartbeat, and write one idempotent result.
- `market_candles` contains the current source revision; `market_candle_revisions` preserves prior values and their availability timestamps.
- `research_manifests`, `strategy_versions`, and `universe_versions` make completed research reproducible.
- Raw Level 2 payloads use daily PostgreSQL partitions and seven-day retention. One-second book summaries and all spread classifications are permanent.
- Alternative.me sentiment is stored point-in-time and remains ablation-only; it cannot independently trigger a trade.
- `strategy_experiments`, candidates, development/holdout runs, fold metrics, access events, and terminal results preserve the champion/challenger lineage.
- Evidence-eligible paper sessions freeze strategy, universe, and execution-policy pins; cycles and evaluator results must match them exactly.

## AWS mapping

Build the Laravel and Python images unchanged. Run Laravel web, scheduler, queue, Python engine, and public collector as distinct ECS/Fargate services. Use RDS PostgreSQL 16, EventBridge for scheduled wakeups, Secrets Manager only on the Laravel tasks, and CloudWatch for logs/alarms. The engine and collector task roles should have no secret read permission and no outbound access beyond PostgreSQL plus the public data endpoints they require.

## Promotion

No code path promotes a strategy automatically. A version must pass development and a globally single-use 12-month holdout, then run unchanged for at least 90 days, 15 closed round trips, three assets, and two sufficiently observed regimes with reconciliation and no more than 15% drawdown. A satisfied forward-paper gate remains `live_eligible=false`; any live-capital decision requires a separate reviewed design.

See `docs/strategy-research-runbook.md` for experiment creation, monitoring, failure recovery, holdout authorization, paper pinning, and evidence interpretation.
