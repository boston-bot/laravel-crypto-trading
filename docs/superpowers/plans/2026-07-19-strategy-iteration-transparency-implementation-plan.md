# Strategy Iteration and Transparency Implementation Plan

Date: 2026-07-19  
Design source: `docs/superpowers/specs/2026-07-19-strategy-iteration-transparency-design.md`  
Status: Ready for implementation

## Outcome

Replace the current loosely coupled live/PHP and replay/Python logic with one versioned Python strategy evaluator, isolate paper capital from live Coinbase account state, add a leakage-resistant champion/challenger experiment system, and expose decision-first explanations plus reproducible research evidence in the local console.

This plan does not enable live trading. It preserves Coinbase-only execution, spot long/cash behavior, human/live safety gates, and the locked 12-month holdout.

## Implementation Rules

- Work test-first in each task: add the failing focused test, run it to confirm the failure, make the smallest implementation, rerun the focused test, then run the affected suite.
- Keep every schema and payload versioned. Never overwrite a completed strategy, universe, experiment, manifest, holdout result, decision trace, or paper ledger entry.
- Do not open or query the locked holdout while implementing development behavior. Tests use synthetic intervals.
- Do not relax the 15% drawdown ceiling, evidence counts, cost stress, or robustness gates in response to results.
- Keep long-running research in queued Python engine jobs. HTTP handlers only validate, enqueue, and project results.
- Make every queued job idempotent and dispatch it after the enclosing database transaction commits.
- Use PostgreSQL constraints for production invariants and application-level equivalents so the default SQLite suite remains useful.
- Commit at each boundary below. Do not mix UI styling, simulator changes, and schema work in one commit.

## Performance Evidence Levels

1. **Integrity** — contracts, point-in-time evidence, accounting, and live/replay parity pass.
2. **Development OOS** — nested walk-forward and robustness gates pass without holdout access.
3. **Locked holdout** — one frozen deployable hash passes the single-use 12-month evaluation.
4. **Forward paper** — the same hash accumulates the required duration, trades, assets, regimes, and no drawdown breach.
5. **Live eligibility** — explicitly outside automatic promotion and outside this implementation.

The UI must name the current evidence level and may not imply that a later level has passed.

## Task 1: Establish Baseline and Contract Fixtures

**Modify**

- `docs/implementation-progress.md`
- `tests/Feature/Trading/ResearchPipelineTest.php`

**Inspect without modifying**

- `contracts/evaluation-result-v1.schema.json`
- `contracts/fixtures/evaluation-result-v1.json`

**Create**

- `contracts/fixtures/portfolio-context-paper-v1.json`
- `contracts/fixtures/portfolio-target-v1.json`
- `contracts/fixtures/order-intent-v1.json`
- `backtest/tests/test_contract_fixtures.py`

**Steps**

1. Run and record the current Laravel, Python, JavaScript, and build baselines without changing application behavior.
2. Add JSON fixtures for a funded paper context, a long/cash target, and a normalized Coinbase order intent.
3. Add Python fixture tests that verify hashes are deterministic and numeric fields are finite.
4. Extend the PHP shared-contract test to read the same fixtures while preserving schema v1 as immutable history.
5. Document the baseline test counts and known failures separately from later implementation failures.

**Verify**

```bash
php artisan test
cd backtest && python -m pytest -q
npm test
npm run build
```

**Commit**

`test: baseline strategy evidence contracts`

## Task 2: Correct Performance Semantics Before Improving Strategy Logic

**Create**

- `app/Data/Analytics/PerformancePeriod.php`
- `app/Services/Analytics/PerformanceProjectionService.php`
- `tests/Feature/Trading/PerformanceSemanticsTest.php`

**Modify**

- `app/Services/Operations/OperationsConsoleQueryService.php`
- `app/Services/Analytics/PerformanceDashboardService.php`
- `resources/js/operations-console.js`
- `resources/views/operations-console.blade.php`
- `tests/Feature/Trading/OperationsConsoleTest.php`
- `tests/Feature/Trading/PerformanceDashboardEndpointTest.php`

**Steps**

1. Add failing tests proving a buy-and-hold loss is never returned or labeled as strategy return.
2. Define `period_start`, `as_of`, interval, freshness, completeness, strategy/universe IDs, valuation policy, cost policy, and benchmark policy in one projection DTO.
3. Return `null` plus `measurement_state: not_measured` when no eligible paper ledger or replay covers the requested interval. Do not substitute zero.
4. Rename asset-level strategy output to `pnl_contribution_pct` and `held_period_linked_return_pct`.
5. Keep full-period raw asset price return under `buy_and_hold_price_return_pct` and mark it `costs_included: false`.
6. Return portfolio strategy return and relative benchmark return only in a portfolio summary with the formula and both source values.
7. Update `/assets`, `/strategies`, and the overview cards to render `Not measured`, `Incomplete`, and stale states distinctly.

**Verify**

```bash
php artisan test tests/Feature/Trading/PerformanceSemanticsTest.php tests/Feature/Trading/OperationsConsoleTest.php tests/Feature/Trading/PerformanceDashboardEndpointTest.php
npm test
npm run build
```

**Commit**

`fix: separate strategy and benchmark performance`

## Task 3: Introduce Mode-Specific Portfolio Context

**Create**

- `app/Contracts/PortfolioContext.php`
- `app/Data/Portfolio/PortfolioContextSnapshot.php`
- `app/Services/Portfolio/PaperPortfolioContextFactory.php`
- `app/Services/Portfolio/LivePortfolioContextFactory.php`
- `app/Services/Portfolio/PortfolioContextResolver.php`
- `tests/Feature/Trading/PortfolioContextTest.php`

**Modify**

- `app/Services/Risk/ExposureService.php`
- `app/Services/Risk/DrawdownService.php`
- `app/Services/Portfolio/CorrelationService.php`
- `app/Services/Risk/RiskEngine.php`
- `app/Services/Execution/OrderSizingService.php`
- `app/Jobs/EvaluateSignalsJob.php`
- `app/Data/Research/EvaluationRequest.php`
- `app/Services/Execution/ApprovalRevalidationService.php`
- `tests/Feature/Trading/CreateTradeDecisionJobTest.php`

**Steps**

1. Add a failing test with a $10,000 virtual session, $0 live buying power, and unrelated live positions; paper context must still expose the virtual cash and zero paper positions.
2. Define a readonly snapshot containing mode, context ID, strategy/universe IDs, cash, reserved cash, equity, positions, gross and correlated exposure, drawdown, realized-loss windows, valuation time, and content hash.
3. Build paper snapshots only from the active `PaperSession`, its ledger, positions, and portfolio snapshots.
4. Build live snapshots only from fresh `BrokerAccount`, `Position`, and daily snapshot state.
5. Change exposure, drawdown, correlation, risk, and temporary sizing methods to accept `PortfolioContext`, not `BrokerAccount`.
6. Include the context payload and hash in the evaluation job. Fail closed when paper mode has no fully pinned active session.
7. Keep broker account IDs only as orchestration/execution routing identities; they are not paper capital sources.

**Verify**

```bash
php artisan test tests/Feature/Trading/PortfolioContextTest.php tests/Feature/Trading/CreateTradeDecisionJobTest.php tests/Feature/Trading/ExposureServiceDustFilterTest.php tests/Feature/Trading/PolicyEngineTest.php
```

**Commit**

`refactor: isolate paper and live portfolio context`

## Task 4: Make Paper Reservations and Fills Atomic

**Create**

- `database/migrations/2026_07_19_000010_create_paper_order_reservations.php`
- `app/Models/PaperOrderReservation.php`
- `app/Services/PaperTrading/PaperReservationService.php`
- `tests/Feature/Trading/PaperAccountingConcurrencyTest.php`

**Modify**

- `app/Models/PaperSession.php`
- `app/Models/PaperLedgerEntry.php`
- `app/Models/BrokerOrder.php`
- `app/Services/PaperTrading/PaperSessionService.php`
- `app/Services/PaperTrading/PaperExecutionEngine.php`
- `app/Services/PaperTrading/PaperPortfolioValuationService.php`
- `app/Services/Execution/TradeExecutionService.php`
- `tests/Feature/Trading/PaperExecutionTest.php`
- `tests/Feature/Trading/PostgresResearchPipelineTest.php`

**Steps**

1. Add reservation rows keyed uniquely by `paper_session_id` and order-intent idempotency key, with amount, status, reserved/released timestamps, and intent hash.
2. Lock the paper session and reservation rows before checking available cash or quantity.
3. Reserve cash and write the matching append-only ledger entry in one transaction before order execution.
4. Convert reservation to fill, release it on rejection/cancellation, update position, create order event, and post ledger entries atomically.
5. Make retrying the same intent return the existing reservation/order/fill without duplicate ledger entries.
6. Calculate paper peak equity and drawdown per session, never across all sessions for the broker account.
7. Add PostgreSQL concurrency tests proving two simultaneous buys cannot overspend and two workers cannot duplicate a fill.

**Verify**

```bash
php artisan test tests/Feature/Trading/PaperExecutionTest.php tests/Feature/Trading/PaperAccountingConcurrencyTest.php
RUN_POSTGRES_TESTS=1 php artisan test tests/Feature/Trading/PostgresResearchPipelineTest.php
```

**Commit**

`fix: make paper accounting transactional and idempotent`

## Task 5: Version the Decision Trace and Order-Intent Contract

**Create**

- `contracts/evaluation-result-v2.schema.json`
- `contracts/fixtures/evaluation-result-v2.json`
- `app/Data/Research/DecisionTrace.php`
- `app/Data/Trading/PortfolioTarget.php`
- `app/Data/Trading/OrderIntent.php`
- `app/Enums/EvaluationResolution.php`
- `database/migrations/2026_07_19_000011_add_decision_trace_and_order_intent.php`
- `tests/Feature/Trading/DecisionTraceContractTest.php`

**Modify**

- `backtest/trading_engine/contracts.py`
- `app/Data/Research/EvaluationResult.php`
- `app/Models/AssetEvaluation.php`
- `app/Models/TradeDecision.php`
- `app/Enums/TradingDecisionStatus.php`
- `app/Jobs/ConsumeEngineResultsJob.php`
- `app/Jobs/CreateTradeDecisionJob.php`
- `config/research.php`
- `tests/Feature/Trading/ResearchPipelineTest.php`

**Steps**

1. Define schema v2 with a portfolio target and one trace per expected asset.
2. Require strategy family/version, rank, portfolio state, rule checklist, factor contributions, gross edge, cost estimate, net edge, primary explanation, reason codes, counterfactual, evidence hash, parameter hash, and optional normalized order intent.
3. Store the complete versioned trace and its canonical hash on `asset_evaluations`.
4. Store `hold`, `blocked_by_evidence`, `blocked_by_strategy`, `blocked_by_portfolio_risk`, and `blocked_by_policy` as distinct evaluation resolutions.
5. Continue creating no `TradeDecision` for HOLD. For non-HOLD actions, map risk and policy failures to their specific statuses.
6. Remove Laravel-generated generic HOLD explanations when a v2 engine trace exists; Laravel may add only orchestration/actionability suppression context.
7. Keep v1 consumption read-compatible for old records, but make v1 non-promotable.

**Verify**

```bash
php artisan test tests/Feature/Trading/DecisionTraceContractTest.php tests/Feature/Trading/ResearchPipelineTest.php tests/Feature/Trading/CreateTradeDecisionJobTest.php
cd backtest && python -m pytest tests/test_contract_fixtures.py -q
```

**Commit**

`feat: persist versioned strategy decision traces`

## Task 6: Build the Canonical Python Strategy Evaluator

**Create**

- `backtest/trading_engine/strategy_definition.py`
- `backtest/trading_engine/strategy_state.py`
- `backtest/trading_engine/strategy_families.py`
- `backtest/trading_engine/portfolio.py`
- `backtest/trading_engine/evaluator.py`
- `backtest/tests/test_strategy_families.py`
- `backtest/tests/test_strategy_state.py`
- `backtest/tests/test_portfolio_evaluator.py`

**Modify**

- `backtest/trading_engine/features.py`
- `backtest/trading_engine/worker.py`
- `backtest/trading_engine/database.py`
- `backtest/trading_engine/contracts.py`
- `contracts/fixtures/evaluation-result-v2.json`

**Steps**

1. Parse and validate immutable strategy definitions; reject unknown schemas, missing parameters, non-finite values, and hashes that do not match content.
2. Expand features to the frozen multi-horizon trend, momentum, pullback, volatility, breadth, execution, extension, and rank-persistence inputs without using future rows.
3. Implement the four approved behaviors: trend rotation, pullback in trend, protected momentum, and defensive cash.
4. Implement `flat`, `entry_confirming`, `open`, `exit_confirming`, and `cooldown` states with stronger entry than continuation thresholds.
5. Enforce no-chasing zones, zero-to-three positions, maximum 40% per asset, 100% gross, correlation-aware selection, and cash when net edge is inadequate.
6. Enforce ordinary 2–21 day holding, early protective exits, and no thesis renewal in the initial families.
7. Return an evaluation for every universe member, including every failed rule and a realistic nearest counterfactual.
8. Make `worker.evaluate_job()` call this evaluator with the pinned definition, point-in-time universe, evidence cutoff, and portfolio context; remove `score_latest()` from the promotable path.

**Verify**

```bash
cd backtest && python -m pytest tests/test_strategy_families.py tests/test_strategy_state.py tests/test_portfolio_evaluator.py -q
```

**Commit**

`feat: add canonical long cash strategy evaluator`

## Task 7: Add Point-in-Time Universe Membership

**Create**

- `database/migrations/2026_07_19_000012_create_universe_memberships.php`
- `app/Models/UniverseMembership.php`
- `app/Services/Strategy/PointInTimeUniverseService.php`
- `backtest/trading_engine/market_evidence.py`
- `tests/Feature/Trading/PointInTimeUniverseTest.php`
- `backtest/tests/test_market_evidence.py`

**Modify**

- `app/Models/UniverseVersion.php`
- `app/Services/Strategy/UniverseSelectionService.php`
- `app/Services/MarketData/CanonicalMarketEvidenceService.php`
- `backtest/trading_engine/database.py`
- `backtest/trading_engine/backtest_runner.py`
- `app/Console/Commands/ResearchBackfillCandlesCommand.php`
- `config/research.php`

**Steps**

1. Store immutable membership intervals with asset, Coinbase product ID, quote currency, effective bounds, listing/delisting times, trading state, precision, minimums, evidence time, and content hash.
2. Build universe versions from time-versioned Coinbase product evidence, not the current enabled asset list alone.
3. Consolidate live and replay candle selection around canonical source, derived interval, finality, valid/verified quality, `available_at`, `first_seen_at`, and logical close.
4. Validate archived revision JSON before numeric casts so malformed revisions cannot re-enter replay.
5. Prevent prelisting candles and later universe versions from affecting earlier bars.
6. Implement halt/delisting forced exits and mark the fold incomplete when no reliable exit price exists.
7. Extend historical collection toward all available Coinbase history, with an initial target of eight years. If four outer folds still cannot be formed, mark the experiment `inconclusive`; never shorten the holdout after inspection.

**Verify**

```bash
php artisan test tests/Feature/Trading/PointInTimeUniverseTest.php tests/Feature/Trading/MarketEvidenceReliabilityTest.php
cd backtest && python -m pytest tests/test_market_evidence.py -q
```

**Commit**

`feat: version point in time trading universes`

## Task 8: Make Order Intents and Execution Policy Replay/Paper-Compatible

**Create**

- `backtest/trading_engine/execution_policy.py`
- `app/Services/Execution/OrderIntentValidator.php`
- `tests/Feature/Trading/OrderIntentParityTest.php`
- `backtest/tests/test_execution_policy.py`

**Modify**

- `app/Services/Execution/OrderSizingService.php`
- `app/Services/Execution/ApprovalRevalidationService.php`
- `app/Services/PaperTrading/PaperExecutionEngine.php`
- `app/Services/Execution/TradeExecutionService.php`
- `backtest/trading_engine/simulation.py`
- `backtest/trading_engine/contracts.py`
- `contracts/fixtures/order-intent-v1.json`

**Steps**

1. Make the Python portfolio target the sole sizing authority.
2. Normalize delta weights to quantity using Coinbase precision, minimum size/notional, 100% gross cap, and the versioned execution policy.
3. Change Laravel `OrderSizingService` into compatibility validation or replace call sites with `OrderIntentValidator`; it must not independently resize.
4. Revalidation may refresh quote drift and veto risk/policy failures, but it must not alter the canonical target or quantity.
5. Fill only on the first executable observation strictly after the decision cutoff. Record the observation ID/time.
6. Align fee, half-spread, slippage, participation, partial fill, rejection, and mark-to-market formulas across simulator fixtures and paper execution.
7. Add golden tests that feed one context/target/market observation through Python and PHP and assert identical normalized intent and accounting outcomes within the declared decimal precision.

**Verify**

```bash
php artisan test tests/Feature/Trading/OrderIntentParityTest.php tests/Feature/Trading/PaperExecutionTest.php
cd backtest && python -m pytest tests/test_execution_policy.py -q
```

**Commit**

`refactor: unify order intent and execution policy`

## Task 9: Correct Simulator Equity, Benchmarks, and Nested Walk-Forward Selection

**Create**

- `backtest/trading_engine/benchmarks.py`
- `backtest/trading_engine/attribution.py`
- `backtest/trading_engine/robustness.py`
- `backtest/trading_engine/experiment_runner.py`
- `backtest/tests/test_nested_walk_forward.py`
- `backtest/tests/test_equity_linking.py`
- `backtest/tests/test_benchmarks.py`
- `backtest/tests/test_robustness.py`

**Modify**

- `backtest/trading_engine/contracts.py`
- `backtest/trading_engine/walk_forward.py`
- `backtest/trading_engine/backtest_runner.py`
- `backtest/trading_engine/simulation.py`
- `backtest/trading_engine/metrics.py`
- `config/research.php`
- `backtest/config/settings.yaml`

**Steps**

1. Replace the fixed 24-hour future label and independent per-asset thresholds with the canonical adaptive state machine and cross-sectional portfolio target.
2. Use inner training and validation for bounded parameter selection; outer test data may measure but never select.
3. Create immutable fold child strategy definitions and record their calibration boundaries and hashes.
4. Mark every open position at every final common four-hour bar and at fold end without recording a fake close.
5. Start each fold at normalized NAV 1.0 and geometrically link non-overlapping OOS paths so fold resets do not erase losses.
6. Calculate the 15% ceiling from the linked net high-water-mark series and report fold-level drawdowns separately.
7. Implement equal-weight point-in-time universe, BTC, and cash benchmarks, including monthly rebalancing and modeled benchmark costs.
8. Implement net asset contribution and held-period linked return with the pinned attribution policy.
9. Enforce the approved numeric trade, asset, fold, regime, concentration, neighbor-stability, cost-stress, and reconciliation gates.
10. Return `inconclusive` for insufficient evidence and `failed` for a breached gate.

**Verify**

```bash
cd backtest && python -m pytest tests/test_nested_walk_forward.py tests/test_equity_linking.py tests/test_benchmarks.py tests/test_robustness.py -q
```

**Commit**

`fix: enforce nested walk forward portfolio evidence`

## Task 10: Persist Experiments, Candidates, Folds, and Metrics

**Create**

- `database/migrations/2026_07_19_000013_create_strategy_experiments.php`
- `app/Models/StrategyExperiment.php`
- `app/Models/StrategyExperimentCandidate.php`
- `app/Enums/ExperimentStatus.php`
- `app/Enums/EvaluationStage.php`
- `app/Services/Research/ExperimentService.php`
- `tests/Feature/Trading/StrategyExperimentPersistenceTest.php`

**Modify**

- `app/Models/StrategyVersion.php`
- `app/Models/BacktestRun.php`
- `app/Models/BacktestRunMetric.php`
- `app/Jobs/ConsumeBacktestResultsJob.php`
- `app/Services/Research/BacktestOrchestrator.php`
- `app/Console/Commands/RunResearchBacktestCommand.php`
- `tests/Feature/Trading/PostgresResearchPipelineTest.php`

**Steps**

1. Add immutable experiment and candidate-specification rows with objective, constraints, search budget, seeds, regimes, costs, attribution/benchmark policies, development boundary, and anchored holdout interval.
2. Link fold child and final deployable `StrategyVersion` records to candidate specification and calibration boundaries.
3. Add `development`, `outer_oos`, `holdout`, and `stress` evaluation stages to runs.
4. Persist fold, regime, asset, cost, holding-period, exit-reason, benchmark, and parameter-neighborhood metrics as queryable groups.
5. Keep large curves, fills, and diagnostics in immutable result payloads referenced by manifest/hash.
6. Change the command to create a complete preregistered experiment and enqueue its candidate jobs; do not synthesize mutable strategy definitions from current config after enqueue.
7. Make result consumption verify engine, strategy, universe, experiment, execution-policy, and manifest hashes before completing a run.

**Verify**

```bash
php artisan test tests/Feature/Trading/StrategyExperimentPersistenceTest.php tests/Feature/Trading/ResearchPipelineTest.php
RUN_POSTGRES_TESTS=1 php artisan test tests/Feature/Trading/PostgresResearchPipelineTest.php
```

**Commit**

`feat: persist champion challenger experiments`

## Task 11: Implement the Single-Use Holdout Vault

**Create**

- `database/migrations/2026_07_19_000014_create_holdout_access_records.php`
- `app/Models/HoldoutInterval.php`
- `app/Models/HoldoutAccessEvent.php`
- `app/Enums/HoldoutStatus.php`
- `app/Services/Research/HoldoutGuard.php`
- `app/Services/Research/HoldoutEvaluationService.php`
- `app/Console/Commands/OpenResearchHoldoutCommand.php`
- `tests/Feature/Trading/HoldoutGuardTest.php`
- `backtest/tests/test_holdout_evaluator.py`

**Modify**

- `backtest/trading_engine/database.py`
- `backtest/trading_engine/worker.py`
- `backtest/trading_engine/experiment_runner.py`
- `app/Jobs/ConsumeBacktestResultsJob.php`
- `app/Services/Research/ResearchStatusService.php`
- `tests/Feature/Trading/PostgresResearchPipelineTest.php`

**Steps**

1. Anchor `[holdout_start, holdout_end)` when the experiment is created and keep it unreadable to ordinary development jobs, APIs, exports, and logs.
2. Require an explicit local command naming the frozen finalist hash before enqueueing the sole holdout job.
3. Record append-only authorization and access events with interval, actor, purpose, candidate, manifest, engine, and code hashes.
4. Add a PostgreSQL exclusion constraint preventing reuse of any revealed overlapping interval and a unique constraint allowing only exact idempotent retries.
5. Start holdout replay at NAV 1.0, all cash, flat state, and use only the declared pre-start feature warmup.
6. Apply positive normal/stressed return, normal/stressed drawdown no greater than 15%, completeness, minimum 10 trades/two assets, concentration, execution, and data-quality gates.
7. Persist exactly one terminal result: `passed`, `failed`, or `inconclusive`.
8. Prove in tests that development repositories cannot query the interval and that a second candidate or overlapping future experiment is denied.

**Verify**

```bash
php artisan test tests/Feature/Trading/HoldoutGuardTest.php
RUN_POSTGRES_TESTS=1 php artisan test tests/Feature/Trading/PostgresResearchPipelineTest.php
cd backtest && python -m pytest tests/test_holdout_evaluator.py -q
```

**Commit**

`feat: enforce single use locked holdout`

## Task 12: Pin Forward Paper Evidence to the Frozen Finalist

**Create**

- `app/Services/Research/PaperEvidenceGateService.php`
- `tests/Feature/Trading/PaperEvidenceGateTest.php`

**Modify**

- `app/Http/Requests/StartPaperSessionRequest.php`
- `app/Services/PaperTrading/PaperSessionService.php`
- `app/Models/PaperSession.php`
- `app/Services/Operations/PipelineCycleService.php`
- `app/Jobs/RunPipelineCycleJob.php`
- `app/Services/Research/ResearchStatusService.php`
- `resources/js/operations-console.js`
- `tests/Feature/Trading/OperationsConsoleTest.php`

**Steps**

1. Require non-null frozen strategy and universe versions for any evidence-eligible paper session.
2. Expose explicit strategy/universe selection when starting a research paper session; default only when one holdout-passing finalist exists.
3. Reject cycles whose pinned versions differ from the session or evaluator output.
4. Compute `collecting_evidence`, `failed`, or `satisfied` using 90 days, 15 closed round trips, three assets, two regimes with 20 common bars each, reconciliation, and a drawdown ceiling of 15% inclusive.
5. Suppress new entries immediately above 15% paper drawdown or on manifest/reconciliation failure.
6. Do not convert a satisfied paper gate into live eligibility automatically.

**Verify**

```bash
php artisan test tests/Feature/Trading/PaperEvidenceGateTest.php tests/Feature/Trading/OperationsConsoleTest.php tests/Feature/Trading/SnapshotPaperPortfolioJobTest.php
```

**Commit**

`feat: pin forward paper promotion evidence`

## Task 13: Build Stable Transparency Read APIs

**Create**

- `app/Services/Operations/StrategyTransparencyQueryService.php`
- `app/Services/Operations/ResearchLabQueryService.php`
- `app/Http/Resources/DecisionInspectorResource.php`
- `app/Http/Resources/StrategyExperimentResource.php`
- `app/Http/Resources/ResearchRunResource.php`
- `tests/Feature/Trading/StrategyTransparencyEndpointTest.php`
- `tests/Feature/Trading/ResearchLabEndpointTest.php`

**Modify**

- `app/Http/Controllers/Api/V1/OperationsConsoleDataController.php`
- `routes/api.php`
- `app/Services/Operations/OperationsConsoleQueryService.php`

**Steps**

1. Add latest-decision and decision-detail projections by asset/cycle.
2. Return immediate answer, rule mechanics, and full audit layers without asking the browser to interpret raw JSON.
3. Add experiment list/detail projections with champion/challenger results, folds, robustness, costs, benchmarks, attribution, rejection reasons, and holdout state.
4. Add performance metadata and measurement state to every projection.
5. Eager-load bounded relationships and paginate experiments/runs to prevent N+1 queries and unbounded payloads.
6. Keep all endpoints loopback-only and read-only; the holdout-open command remains outside HTTP.

**Verify**

```bash
php artisan test tests/Feature/Trading/StrategyTransparencyEndpointTest.php tests/Feature/Trading/ResearchLabEndpointTest.php tests/Feature/Trading/OperationsConsoleTest.php
```

**Commit**

`feat: expose strategy transparency projections`

## Task 14: Implement the Decision-First Strategies UI

**Create**

- `resources/js/console/strategy-inspector.js`
- `resources/js/console/performance-formatters.js`
- `resources/js/console/strategy-inspector.test.js`

**Modify**

- `resources/js/operations-console.js`
- `resources/views/operations-console.blade.php`
- `resources/css/app.css`
- `tests/Feature/Trading/OperationsConsoleTest.php`

**Steps**

1. Make `/strategies` open on the latest decision, with asset and logical-bar selection.
2. Layer 1 shows action, plain-language reason, eligibility, actionability, family/version, position/cash impact, and measured/not-measured evidence.
3. Layer 2 shows passed/failed rules, rank, score, probability, gross edge, costs, net edge, regime, capacity, blocker category, and counterfactual.
4. Layer 3 shows factor contributions, immutable thresholds, timestamps, thesis comparison, sizing/risk/policy/execution/accounting trail, and audit links.
5. Give HOLD the same visual depth as ENTER/EXIT and never color it as an operational failure.
6. Preserve keyboard navigation, semantic headings, focus states, responsive layout, reduced motion, stale-data messaging, and HTML escaping.
7. Add unit tests for measurement labels, blocker grouping, counterfactual rendering, and unsafe text escaping.

**Verify**

```bash
npm test -- strategy-inspector.test.js
npm run build
php artisan test tests/Feature/Trading/OperationsConsoleTest.php tests/Feature/Trading/StrategyTransparencyEndpointTest.php
```

**Commit**

`feat: add decision first strategy inspector`

## Task 15: Implement the Research Lab UI

**Create**

- `resources/js/console/research-lab.js`
- `resources/js/console/research-charts.js`
- `resources/js/console/research-lab.test.js`

**Modify**

- `resources/js/operations-console.js`
- `resources/css/app.css`
- `resources/views/operations-console.blade.php`
- `tests/Feature/Trading/OperationsConsoleTest.php`

**Steps**

1. Expand `/research` from data health into a candidate cockpit while retaining candle health and operational incidents.
2. Show champion/challenger status and evidence level before performance numbers.
3. Render linked OOS normal/stressed equity, drawdown, folds, regime/asset attribution, holding periods, exits, turnover, costs, and benchmark comparisons.
4. Render parameter-neighborhood stability and precise rejection reasons.
5. Show the holdout lifecycle including `locked`, `authorized`, `running`, `passed`, `failed`, and `inconclusive`; never reveal locked values.
6. Add `Not measured`, `Incomplete`, and stale states for every chart/table rather than plotting zeros.
7. Add responsive and accessible chart summaries so evidence remains readable without color or pointer interaction.

**Verify**

```bash
npm test -- research-lab.test.js
npm run build
php artisan test tests/Feature/Trading/ResearchLabEndpointTest.php tests/Feature/Trading/OperationsConsoleTest.php
```

**Commit**

`feat: add champion challenger research lab`

## Task 16: Run Parity, Causality, and End-to-End Gates

**Create**

- `tests/Feature/Trading/CanonicalEngineParityTest.php`
- `tests/Feature/Trading/StrategyExperimentWorkflowTest.php`
- `backtest/tests/test_append_future_causality.py`
- `backtest/tests/test_end_to_end_experiment.py`
- `docs/strategy-research-runbook.md`

**Modify**

- `docs/implementation-progress.md`
- `docs/research-pipeline.md`
- `.env.example`
- `compose.research.yml`

**Steps**

1. Prove appended future candles cannot change past factors, ranks, states, targets, intents, fills, or equity.
2. Run golden parity at identical strategy, universe, logical bar, evidence cutoff, portfolio context, and execution policy.
3. Run a synthetic end-to-end experiment through enqueue, worker, result consumption, fold linking, candidate selection, final freeze, and a synthetic holdout interval.
4. Verify every run and UI number reconciles to versions, manifests, fills, costs, and policies.
5. Switch paper default from legacy PHP to database/Python only after parity passes; retain the legacy adapter for diagnostics until a separate removal decision.
6. Document experiment creation, monitoring, failure recovery, holdout authorization, paper pinning, and evidence interpretation.
7. Run formatting, all suites, production build, and PostgreSQL integration tests.

**Verify**

```bash
vendor/bin/pint --test
php artisan test
RUN_POSTGRES_TESTS=1 php artisan test tests/Feature/Trading/PostgresResearchPipelineTest.php tests/Feature/Trading/StrategyExperimentWorkflowTest.php
cd backtest && python -m pytest -q
npm test
npm run build
```

**Commit**

`test: verify canonical strategy research workflow`

## Operational Rollout After Implementation

1. Apply migrations and keep `STRATEGY_ENGINE_DRIVER=legacy` during parity verification.
2. Extend/repair canonical candle history and verify point-in-time universe coverage.
3. Create one preregistered development experiment with the four approved families and fixed bounded search budget.
4. Run development only. Do not authorize holdout access.
5. Review integrity, fold count, trade/regime evidence, normal/stressed curves, attribution, and robustness in Research Lab.
6. Freeze one finalist only if every development gate passes.
7. Explicitly authorize the one holdout evaluation for that exact hash.
8. If it passes, start a new paper session pinned to that exact strategy/universe/execution-policy set.
9. Collect forward evidence until the numeric paper gate is satisfied or failed.
10. Make any live-trading decision in a separate reviewed design; this workflow does not enable it.

## Completion Checklist

- [ ] Asset losses are unambiguously labeled as market benchmarks, not strategy returns.
- [ ] A virtual $10,000 paper session can evaluate and reserve capital despite $0 live buying power.
- [ ] Concurrent paper decisions cannot overspend or duplicate fills.
- [ ] HOLD, evidence, strategy, risk, and policy outcomes are distinct.
- [ ] Replay and live paper use the same canonical strategy definition and order-intent semantics.
- [ ] Candidate families use point-in-time cross-sectional portfolio logic and adaptive 2–21 day state.
- [ ] Four or more linked outer folds and all numeric robustness gates are enforced.
- [ ] The 12-month holdout is globally single-use across overlapping revealed intervals.
- [ ] Experiments, candidates, versions, manifests, policies, folds, and results are immutable and reproducible.
- [ ] Strategies UI explains the latest decision in three layers.
- [ ] Research Lab shows candidates, costs, robustness, benchmarks, attribution, and holdout lifecycle.
- [ ] Forward paper evidence remains pinned and cannot automatically enable live trading.
- [ ] Laravel, PostgreSQL, Python, JavaScript, build, formatting, parity, and causality checks pass.
