# Automated Crypto Trading Pipeline: Engineering Progress

Date: 2026-07-19
Status: Strategy iteration transparency implemented; operational evidence collection pending

## 1. Purpose

This document is an engineering handoff for the automated crypto trading pipeline. It summarizes what is implemented, how the pieces fit together, what has been verified, the application's current local state, and the work that remains before any strategy can be considered for live capital.

The implementation is intentionally research-first. Coinbase remains the only order venue. Kraken is used only for public comparison data, sentiment cannot independently trigger a trade, and no code path automatically enables live trading.

## 2. Current Status

The repository now contains the core architecture needed to collect research data, evaluate versioned strategies, simulate realistic portfolio execution, operate funded paper sessions, explain every evaluation, and supervise the local processes from a focused operations console.

The implementation gates now cover canonical parity, future-data causality, experiment/holdout lineage, exact paper pinning, and stable transparency projections. The remaining work is operational evidence collection: complete the canonical backfill, run one preregistered experiment, explicitly authorize one finalist holdout, and accumulate forward paper evidence.

At the last local verification:

- all database migrations were applied;
- the compiled console loaded successfully at `/dashboard`;
- a Coinbase account snapshot was available;
- no paper session had been started;
- the local runtime supervisor was not running;
- no strategy or spread-performance claim could yet be made from the UI;
- live console approvals remained disabled.

Those empty states are deliberate. The UI now explains the missing prerequisite instead of presenting empty performance metrics as if the strategy silently did nothing.

## 3. Safety Boundary

The following constraints are implemented and should remain invariant:

- Coinbase is the only execution venue.
- Kraken credentials and order submission are not implemented.
- Spread analysis is shadow-only and cannot create a proposal or order.
- The initial universe is BTC, ETH, SOL, LINK, and LTC.
- Directional trading is spot long/flat with no leverage, pyramiding, shorts, derivatives, DEX, or MEV logic.
- Sentiment is stored as an ablation feature and cannot independently trigger a trade.
- Python workers and public collectors receive database/public-feed access, not Coinbase trading credentials.
- Local console routes are restricted to loopback requests.
- Live approvals through the console are disabled until authentication and approver roles exist.
- No migration, command, strategy result, or UI action automatically turns on live trading.

## 4. Implemented Architecture

```text
Coinbase credentials ──> Laravel sync jobs ──┐
                                             │
Coinbase/Kraken public feeds ──> collectors ─┼──> PostgreSQL
Alternative.me sentiment ──────> ingestion ──┘        │
                                                      │ durable engine jobs
                                                      v
                                               Python engine
                                                      │ versioned results
                                                      v
Laravel cycle orchestration ──> risk/policy ──> paper fill or approval gate
             │                                        │
             └──────── activity/audit trail <─────────┘
                                  │
                                  v
                         Trading Operations Console
```

Laravel owns credentialed ingestion, orchestration, risk, policy, approvals, execution, paper accounting, audit records, HTTP endpoints, and the UI. The canonical Python package owns point-in-time feature computation, calibration, historical replay, portfolio simulation, book reconstruction, and engine-worker processing.

PostgreSQL is the durable seam between the two runtimes. Engine workers claim jobs with leases and `FOR UPDATE SKIP LOCKED`, commit the claim before computation, heartbeat while working, and write idempotent versioned results.

## 5. Progress by Subsystem

### 5.1 Strategy engine seam

Implemented:

- a Laravel `StrategyEngine` contract;
- a database-backed Python engine adapter;
- a temporary legacy PHP adapter for parity and rollback;
- durable `engine_jobs` and `engine_results` with schema versions, attempts, leases, expiry, idempotency, and result consumption;
- shared JSON contracts and golden fixtures;
- an installable Python package under `backtest/trading_engine`;
- worker commands for evaluation, backfill, backtesting, sentiment refresh, and public collection;
- trading idempotency keyed to the logical closed four-hour bar rather than the request minute;
- diagnostic evaluations that can display results but cannot create trade decisions or performance attribution.

Current transition state:

- `STRATEGY_ENGINE_DRIVER=database` is the paper default after canonical parity and causality tests passed;
- the legacy evaluator remains installed for diagnostics only and is not promotion-eligible;
- changing the engine driver never enables live trading.

### 5.2 Point-in-time market and research data

Implemented:

- canonical one-hour candle ingestion with `first_seen_at`, `available_at`, finality, quality, revision number, and source hash;
- append-only candle revisions when source values change;
- UTC-aligned four-hour and daily aggregation in the Python engine;
- historical Coinbase candle backfill commands;
- ingestion checkpoints and data-quality incidents;
- immutable strategy versions, universe versions, and research manifests;
- Coinbase fee snapshots and conservative Kraken fee scenarios;
- Alternative.me Fear & Greed ingestion with publication/first-seen times, normalized score, changes, and rolling z-scores;
- native PostgreSQL partitions for short-lived raw market events;
- permanent one-second order-book summaries and executable-depth buckets;
- sequence-gap invalidation and book resynchronization behavior;
- permanent spread observations, including rejected candidates and rejection reasons.

Operational status:

- the schema and ingestion code are present;
- the five-year backfill has not been confirmed complete for every asset;
- Level 2 and spread evidence is prospective and requires the collector to remain running;
- the current console showed unavailable benchmark boundaries, which indicates more canonical candle coverage is still needed.

### 5.3 Strategy research and simulation

Implemented in Python:

- trend, momentum, relative-strength, volatility, participation/liquidity, execution-quality, and regime features;
- point-in-time slicing and no-lookahead invariants;
- monotonic probability calibration and calibration reporting;
- sentiment feature isolation for price-only versus price-plus-sentiment ablation;
- anchored walk-forward folds with a locked 12-month holdout and a 30-day embargo;
- an event-driven multi-asset simulator;
- next-eligible-hour fills instead of same-close fills;
- maker/taker fees, spread, volatility/size-sensitive slippage, partial-fill/rejection hooks, cash, exposure, and missing-price rejection;
- normal and stressed cost scenarios;
- Sharpe, Sortino, drawdown, profit factor, expectancy, calibration, equity, fill, and cost reporting;
- immutable backtest jobs and result consumption into Laravel models.

The application has the research machinery to run the acceptance gates, but it has not yet accumulated evidence proving that a strategy passes them.

### 5.4 Spread feasibility

Implemented:

- Coinbase and Kraken public book representations;
- feed-age and receive-time comparability checks;
- executable VWAP/depth calculations for configured notional buckets;
- both venue directions;
- fee, impact, rebalancing reserve, and 10-basis-point safety-buffer deductions;
- classification as executable, stale, insufficient-depth, fee-uncertain, invalid-book, or negative-after-costs;
- simulated delay scenarios at 250, 500, and 1,000 milliseconds;
- shadow-only research API and UI summaries.

Not implemented by design:

- Kraken credentials or funded inventory;
- transfers or inventory rebalancing;
- simultaneous two-leg order submission;
- triangular, DEX, or MEV routing;
- any spread-based live or paper order path.

### 5.5 Pipeline cycles and explainability

Implemented:

- durable `pipeline_cycles` and ordered `pipeline_cycle_steps`;
- one active cycle per account and mode, enforced in PostgreSQL;
- automatic and manual triggers that coalesce while a cycle is active;
- minute heartbeat scheduling;
- data refresh, eligibility, evaluation, result-consumption, reconciliation, and snapshot steps;
- final four-hour-bar eligibility using point-in-time availability;
- `asset_evaluations` for every engine proposal, including `HOLD`;
- separation between an evaluation, an actionable trade decision, and an execution outcome;
- plain-language explanations, thresholds, warnings, factors, and candle evidence;
- an append-only `activity_events` projection for chronological operator visibility.

Important behavioral change:

- `HOLD` is now a valid evaluation result and creates no trade decision. It is no longer presented as a policy failure.

### 5.6 Paper sessions and accounting

Implemented:

- explicit paper sessions instead of implicitly borrowing live-account cash;
- virtual funding with operator-entered capital;
- mirror funding from a fresh positive USD-equity value on the selected broker account;
- zero copied live positions in mirror mode;
- one active session per broker account;
- session-scoped paper positions, orders, fills, attribution, and snapshots;
- append-only opening cash, principal, proceeds, and fee ledger entries;
- available-cash and available-quantity enforcement;
- observed quote/reference prices only, with no invented fallback price;
- spread, slippage, and taker-fee modeling;
- weighted-average cost basis including buy fees;
- realized P&L net of relieved cost and sell fees;
- exactly-once fill handling through client order and fill identifiers;
- rejection of session closure while positions or pending orders remain.

Current limitation:

- operational paper sessions may remain unpinned, but evidence-eligible sessions require and freeze the exact holdout-passing strategy, universe, and execution-policy hashes;
- mirror funding does not yet enforce that the selected account is Coinbase and records the mutable broker-account row ID as `source_account_snapshot_id` rather than referencing an immutable account-snapshot record;
- automatic `Liquidate and end` is not implemented; positions must be resolved through normal paper orders before ending a session.

### 5.7 Risk, policy, approval, and execution

Implemented or preserved:

- no leverage or pyramiding;
- configured risk-per-trade and position/exposure limits;
- daily/weekly loss brakes and drawdown kill switch;
- proposal expiry after 30 minutes;
- fresh account, fee, quote, sizing, risk, and policy revalidation at approval;
- material price-change rejection;
- Coinbase market IOC live-order path behind the existing live switches and human approval;
- local paper approval/rejection audit records;
- live console approval rejection until authentication and roles are installed;
- order reconciliation and paper portfolio snapshots.

### 5.8 Local runtime and automation

Implemented:

- `php artisan trading:runtime` as the single local process-supervisor command once the documented Python environment exists;
- supervision of the Laravel scheduler, Laravel queue worker, Python engine worker, and public market collector;
- durable runtime heartbeats and observed/desired process state;
- allowlisted start, pause, resume, and restart requests;
- static server-side commands, working directories, and environments;
- rejection of arbitrary executable or shell input from HTTP requests;
- audited runtime-control requests;
- UI visibility into queue depth, failed jobs, engine jobs, current cycle, process heartbeat, and last error.

The control agent must be started outside the web process. If it is offline, the UI can record a request but cannot launch itself; it displays the supported bootstrap command instead.

### 5.9 Trading Operations Console

The former single noisy dashboard has been replaced with a focused, automatically refreshing console:

- `/dashboard` — latest cycle, primary explanation, paper summary, system summary, evidence trail, and asset contribution;
- `/strategies` — latest decision, rule mechanics, counterfactual, factors, immutable lineage, versions, and runs;
- `/assets` — per-asset paper contribution beside Coinbase buy-and-hold context;
- `/activity` — append-only evaluation, proposal, execution, and operational events;
- `/paper` — session funding, cash ledger, positions, proposals, and session controls;
- `/operations` — supervised processes, queues, engine jobs, pipeline cycles, and safe controls;
- `/research` — champion/challenger evidence, linked normal/stressed curves, robustness, attribution, holdout lifecycle, candle health, and shadow spreads.

UI behavior includes:

- a 10-second visible polling interval and a 60-second background interval;
- exponential retry backoff;
- preservation of the last successful response with an explicit stale state;
- loading, empty, partial, healthy, offline, and failure states;
- prerequisite-specific recovery actions;
- responsive desktop and mobile navigation;
- keyboard focus, semantic structure, live status regions, and reduced-motion support.

The asset screen currently provides actual paper contribution and indicative Coinbase first-close/last-close price context. That context does not model entry/exit fees, liquidation costs, a common manifest, or a fully point-in-time-checked opening boundary, so it must not be interpreted as the approved normalized buy-and-hold benchmark. Both normalized buy-and-hold and normalized single-asset strategy replay remain future work.

## 6. Persistent Data Added

The research and operations work added or extended the following logical modules:

- `engine_jobs`, `engine_results`;
- `strategy_versions`, `universe_versions`, `research_manifests`;
- `market_candle_revisions`, `ingestion_checkpoints`, `data_quality_incidents`;
- `fee_schedule_snapshots`, `sentiment_observations`;
- `raw_market_events`, `order_book_summaries`, `spread_observations`;
- `pipeline_cycles`, `pipeline_cycle_steps`, `asset_evaluations`;
- `paper_sessions`, `paper_ledger_entries`;
- `activity_events`, `operator_actions`;
- `runtime_processes`, `runtime_control_requests`.

Reproducibility-sensitive records are append-only where appropriate. PostgreSQL adds partial unique indexes for active sessions/cycles and logical-bar evaluations, native raw-event partitions, immutable research triggers, and `SKIP LOCKED` worker claiming.

## 7. Local Operating Workflow

### 7.1 Prepare the application

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Create the Python environment expected by the runtime configuration and install its declared dependencies:

```bash
python3 -m venv backtest/venv
backtest/venv/bin/pip install -r backtest/requirements.txt
```

Keep these safety defaults during research and paper operation:

```dotenv
BROKER=coinbase
BROKER_MODE=paper
TRADING_ENABLED=false
HUMAN_APPROVAL_REQUIRED=true
STRATEGY_ENGINE_DRIVER=database
OPERATIONS_LOCAL_ONLY=true
LIVE_CONSOLE_ACTIONS_ENABLED=false
```

### 7.2 Seed and backfill data

```bash
php artisan broker:sync --broker=coinbase --sync
php artisan research:backfill-candles --years=5
```

### 7.3 Start the complete local runtime

```bash
php artisan trading:runtime
```

This is a long-running foreground command. It supervises all four local workloads. If only Laravel queue jobs need to be processed, run:

```bash
php artisan queue:work
```

### 7.4 Start paper operation

1. Open `/paper`.
2. Choose virtual capital or a fresh Coinbase-equity mirror.
3. Start the paper session.
4. Keep `trading:runtime` running.
5. Allow automatic minute cycles, or use the safe manual cycle controls.
6. Use `/activity` to inspect each evaluation and outcome.

The minute heartbeat does not create a new strategy decision every minute. It performs due operational work and evaluates trading logic only when a newly final, point-in-time-eligible four-hour bar exists.

### 7.5 Run research

```bash
php artisan research:backtest --start=2020-01-01
php artisan trading:scorecard --broker=coinbase --days=56 --json
```

## 8. Interfaces and Endpoints

Focused console read projections are available under:

- `GET /api/ops/v1/overview`
- `GET /api/ops/v1/strategies`
- `GET /api/ops/v1/strategy-decisions/latest`
- `GET /api/ops/v1/strategy-decisions/{assetEvaluation}`
- `GET /api/ops/v1/assets`
- `GET /api/ops/v1/activity`
- `GET /api/ops/v1/paper`
- `GET /api/ops/v1/operations`
- `GET /api/ops/v1/research`
- `GET /api/ops/v1/research-lab/experiments`
- `GET /api/ops/v1/research-lab/experiments/{strategyExperiment}`
- `GET /api/ops/v1/research-lab/runs`

CSRF-protected local mutation routes support:

- requesting or coalescing a paper pipeline cycle;
- synchronizing operational data;
- starting and ending paper sessions;
- requesting allowlisted runtime state changes;
- approving or rejecting paper decisions.

Existing broker and research read APIs remain available. While local-only mode is active, console pages and console-used APIs reject non-loopback requests.

## 9. Verification Evidence

### 9.1 Strategy-transparency completion gate (2026-07-19)

| Area | Result |
| --- | --- |
| Laravel unit/feature suite | 81 passed, 460 assertions; 10 PostgreSQL-only tests skipped in SQLite |
| PostgreSQL integration gate | 11 passed, 23 assertions |
| Python synthetic/parity/causality suite | 36 passed; one local LibreSSL warning |
| JavaScript unit suite | 14 passed in 3 Vitest files |
| Production frontend build | Vite build succeeded; 60 modules transformed |
| PHP formatting | Full Laravel Pint check passed |

The completed gate includes canonical transport/order-intent parity, appended-future causality for decisions/fills/equity, preregistration through immutable terminal holdout, locked-value redaction, database immutability/concurrency, paper evidence pinning, and the decision/research transparency projections. These synthetic and contract checks establish reproducibility; they are not evidence that a strategy is profitable.

### 9.2 Strategy-transparency baseline (2026-07-19)

The pre-change baseline for the strategy-transparency implementation was captured before application behavior changed:

| Area | Result |
| --- | --- |
| Laravel unit/feature suite | 50 passed, 281 assertions; 4 PostgreSQL-only tests skipped |
| Python synthetic unit suite | 13 passed; one LibreSSL compatibility warning from urllib3 |
| JavaScript unit suite | 6 passed in 1 Vitest file |
| Production frontend build | Vite build succeeded; 56 modules transformed |

Known environment failures are tracked separately from implementation failures:

- `cd backtest && python -m pytest -q` could not start because this shell has no `python` alias.
- `/usr/local/bin/python3 -m pytest -q` and the existing `backtest/venv` initially lacked pytest.
- After installing the test runner in the existing repository virtual environment, `backtest/venv/bin/python -m pytest -q` passed all 13 baseline tests.

The immutable evaluation-result v1 schema and fixture remain unchanged. New portfolio-context, portfolio-target, and order-intent v1 fixtures are shared by PHP and Python tests, which verify canonical SHA-256 hashes and reject non-finite numeric data.

The implementation was verified on 2026-07-16.

| Area | Result |
| --- | --- |
| Laravel unit/feature suite | 40 passed, 230 assertions; 3 PostgreSQL-only tests skipped in the default SQLite run |
| PostgreSQL integration suite | 3 passed, 6 assertions |
| Python synthetic unit suite | 12 passed |
| Production frontend build | Vite build succeeded |
| PHP formatting | Laravel Pint passed on the new and directly modified operations files |
| Static frontend check | `node --check resources/js/operations-console.js` passed |
| Migration state | All migrations through `2026_07_16_000007_create_operations_console_tables` applied |
| PostgreSQL behavior | Native partitions, `SKIP LOCKED`, and immutable strategy-version tests passed |
| Browser verification | Desktop and mobile console loaded with no browser console errors |
| Interaction verification | Asset-window refresh, paper setup state, Operations state, auto-refresh, and prerequisite CTAs verified |

Representative automated coverage includes:

- point-in-time slicing and no-lookahead feature invariants;
- UTC candle aggregation;
- monotonic calibration;
- next-hour fills and missing-price rejection;
- Level 2 sequence recovery and deterministic Kraken checksum behavior;
- after-cost spread classification;
- walk-forward holdout isolation;
- engine-job idempotency per closed four-hour bar;
- candle revision preservation;
- expired approval failure;
- paper ledger posting and session scoping;
- loopback-only console access;
- session-start idempotency and mirror funding;
- pipeline-cycle coalescing;
- runtime-control allowlisting.

The Python suite now covers a synthetic end-to-end experiment and future-data causality, but it still does not run a live PostgreSQL worker, historical Coinbase backfill, or public collector against external feeds. PostgreSQL leasing is covered separately from PHP; captured-feed and live collector integration remain operational work.

## 10. Known Limitations and Deferred Work

The following items are incomplete or intentionally deferred:

1. The five-year candle dataset and prospective Level 2 dataset still need to be collected and monitored for gaps.
2. Canonical parity and causality are automated; the legacy adapter remains intentionally available for diagnostics until a separate removal decision.
3. No strategy has yet passed the operational development, holdout, and 90-day/15-round-trip paper evidence gates.
4. No active paper session has been started in the verified local environment.
5. Only explicitly evidence-eligible sessions count toward promotion evidence; operational unpinned paper sessions do not.
6. Mirror funding does not yet enforce Coinbase or reference an immutable account-snapshot record.
7. The runtime processes were implemented and inspected but were not left running after verification.
8. Fully normalized per-asset strategy-versus-buy-and-hold replay remains to be added to the asset view.
9. `Liquidate and end` for paper sessions is not implemented.
10. Dedicated UI controls for retrying failed cycle boundaries and running retention/maintenance are not yet exposed.
11. Python database-worker, backfill, and live collector integration tests remain incomplete; synthetic end-to-end experiment coverage is present.
12. Authentication, operator/approver roles, and production authorization are not implemented.
13. AWS ECS/Fargate, RDS, Secrets Manager, EventBridge, and CloudWatch deployment is documented as a mapping, not deployed infrastructure.
14. Arbitrage execution, Kraken funding, transfers, inventory balancing, and two-leg orders remain out of scope.
15. Live trading remains intentionally locked and should not be enabled based only on implementation completeness.

## 11. Recommended Next Engineering Steps

1. Run the Coinbase historical backfill and resolve all reported candle gaps or quality incidents.
2. Start `php artisan trading:runtime` and verify sustained heartbeats for the scheduler, queue, engine, and collector.
3. Run captured-feed and prospective Level 2 collection long enough to characterize feed gaps and after-cost spread feasibility.
4. Review the automated canonical parity and causality gates before any engine contract change.
5. Run one preregistered four-family experiment and review every development gate in Research Lab.
6. Authorize the single-use holdout only for one passing frozen finalist, then start an evidence-eligible pinned paper session and complete the 90-day/15-round-trip gate.
7. Compare modeled and realized slippage, reconcile every paper fill, and resolve every critical data incident.
8. Finish normalized asset benchmark replay, failure retry/maintenance controls, and paper liquidation workflow.
9. Add authentication and explicit operator/approver roles before exposing the console outside loopback or deploying it to AWS.
10. Design any live-capital path separately after the exact immutable version passes every evidence gate. Continue to require fresh human approval and Coinbase IOC revalidation.

## 12. Key File Map

| Concern | Primary location |
| --- | --- |
| Approved operations-console design | `docs/superpowers/specs/2026-07-16-trading-operations-console-design.md` |
| Research architecture and deployment | `docs/research-pipeline.md` |
| Engine contract and adapters | `app/Contracts`, `app/Services/Research` |
| Python engine | `backtest/trading_engine` |
| Shared JSON contracts | `contracts` |
| Pipeline jobs and orchestration | `app/Jobs/RunPipelineCycleJob.php`, `app/Services/Operations` |
| Paper sessions and ledger | `app/Services/PaperTrading`, `app/Models/PaperSession.php`, `app/Models/PaperLedgerEntry.php` |
| Runtime supervisor | `app/Console/Commands/TradingRuntimeCommand.php`, `config/operations.php` |
| Console pages and actions | `app/Http/Controllers/OperationsConsolePageController.php`, `app/Http/Controllers/OperationsActionController.php` |
| Console query projections | `app/Services/Operations/OperationsConsoleQueryService.php` |
| Console frontend | `resources/views/operations-console.blade.php`, `resources/js/operations-console.js`, `resources/css/app.css` |
| Console routes | `routes/web.php`, `routes/api.php`, `routes/console.php` |
| Research/operations migrations | `database/migrations/2026_07_16_000005_create_research_pipeline_tables.php` through `2026_07_19_000015_add_paper_evidence_gate.php` |
| PHP integration coverage | `tests/Feature/Trading` |
| Python engine coverage | `backtest/tests`, including append-future causality and end-to-end experiment gates |

## 13. Promotion Reminder

Implementation completion is not evidence of profitability. The latest 12 months remain locked during development, earlier data must pass the configured walk-forward folds under normal and stressed costs, and the same immutable strategy must then survive the pinned forward-paper gate. Satisfaction remains evidence only and cannot enable live trading. Any live-capital consideration needs a separate reviewed design and must preserve Coinbase-only execution, risk brakes, fresh revalidation, and human approval.
