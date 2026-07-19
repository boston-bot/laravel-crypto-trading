# Trading Operations Console handbook

This is the developer/operator guide for the local Trading Operations Console. It explains what each screen means, how to run the implemented workflows, and how to change a strategy without invalidating its evidence lineage.

The detailed research protocol remains in the [strategy research runbook](./strategy-research-runbook.md). This handbook summarizes that protocol from the UI and operator perspective.

## Contents

- [Safety boundaries](#safety-boundaries)
- [Mental model and terminology](#mental-model-and-terminology)
- [Initial local setup](#initial-local-setup)
- [Runtime and refresh behavior](#runtime-and-refresh-behavior)
- [Screen reference](#screen-reference)
- [Daily operating workflow](#daily-operating-workflow)
- [Market-data workflow](#market-data-workflow)
- [Operational paper workflow](#operational-paper-workflow)
- [Decision review workflow](#decision-review-workflow)
- [Research workflow](#research-workflow)
- [Holdout workflow](#holdout-workflow)
- [Forward-paper evidence workflow](#forward-paper-evidence-workflow)
- [Troubleshooting](#troubleshooting)
- [Changing a strategy](#changing-a-strategy)
- [Command reference](#command-reference)
- [Readiness checklists](#readiness-checklists)
- [Source map](#source-map)

## Safety boundaries

Treat these as invariants, not configuration suggestions:

1. The console is local-only. Do not expose it publicly.
2. Coinbase is the only execution venue. Kraken observations are comparison-only and spread feasibility remains shadow-only.
3. Keep `BROKER_MODE=paper`, `TRADING_ENABLED=false`, and `HUMAN_APPROVAL_REQUIRED=true` while using this handbook.
4. Passing development research, passing the holdout, or satisfying forward-paper evidence does **not** enable live trading. The paper gate always reports `live_eligible=false`.
5. Holdout authorization is command-only. Never reveal locked holdout values through HTTP, logs, ad hoc SQL, or debugging code.
6. Immutable strategy, universe, manifest, experiment, holdout, and evidence-session records must not be edited in place. Create a new version or experiment.

Any live-capital workflow needs a separate reviewed design and explicit authorization. It is outside this guide.

## Mental model and terminology

### One cycle, several different facts

The paper pipeline records these steps separately:

`data refresh → eligibility → evaluation → result consumption → reconciliation → snapshot`

Do not collapse them into “the strategy traded.”

| Term | Meaning | What it does not prove |
|---|---|---|
| Evaluation | The engine assessed one asset at one logical bar using a pinned context. | That the result was actionable or consumed. |
| HOLD | A complete neutral strategy result. The recorded rules did not justify a position change. | An operational error. |
| Actionable decision | A result survived evidence, strategy, portfolio-risk, and policy checks and may create a proposal. | Human approval, submission, or fill. |
| Proposal | A requested paper order awaiting the configured approval step. | An order or reserved fill. |
| Approval/rejection | The operator accepted or rejected a pending proposal. | A fill; the quote may still become stale or policy may block it. |
| Order and order event | The execution layer attempted and tracked the paper order lifecycle. | Final accounting until reconciliation completes. |
| Fill | Executed paper quantity and price. | Correct ledger, position, or attribution until reconciled. |
| Attribution | P&L and costs linked back to assets, holdings, exits, or regimes. | Portfolio cash/equity reconciliation by itself. |
| Reconciliation | Order, reservation, ledger, position, manifest, and accounting checks agree. | Future profitability. |
| Portfolio snapshot | Point-in-time cash, exposure, equity, and drawdown after the cycle. | A complete performance interval if coverage is missing. |

### Measurement states

| UI state | Interpretation | Response |
|---|---|---|
| `Not measured` | No eligible ledger, replay, or candidate evidence covers the requested result. | Generate eligible evidence; do not treat the value as zero. |
| `Incomplete` | Some evidence exists, but the requested interval or required folds are not fully covered. | Finish the missing work before comparing results. |
| `Stale` | A result was measured but its valuation or source data is too old. | Refresh data/runtime and re-evaluate. |
| `Measured` | The required result exists and is current enough for the screen's purpose. | Interpret it together with lineage, costs, and gates. |
| Operational failure | A worker, engine job, cycle step, schema, or reconciliation failed. | Repair the pipeline; this is not a strategy rejection. |
| Strategy blocker | The engine ran correctly and an entry/exit rule did not pass. | HOLD is expected unless the hypothesis says otherwise. |
| Evidence failure | Lineage, point-in-time data, manifest, schema, cost, or accounting proof is invalid. | Do not promote or rewrite the result; repair and create new evidence. |

### Research lineage

- A **strategy version** freezes a definition, parameters, engine version, calibration boundary, and content hash.
- A **universe version** freezes the eligible asset set and membership rules.
- A **research manifest** identifies the point-in-time inputs used by a run.
- An **experiment** preregisters the objective, constraints, four strategy families, search budgets, seeds, cost/execution policies, development interval, and locked holdout.
- A **candidate** is one bounded family specification inside an experiment.
- A **finalist** is exactly one development-passing candidate frozen as a deployable `final` strategy version.
- A **holdout** is a globally single-use interval that stays locked until it is authorized for that exact finalist.
- An **evidence-eligible paper session** pins the holdout-passing finalist, universe, and execution-policy hash for forward collection.

## Initial local setup

### 1. Configure the safe canonical runtime

Ensure `.env` contains:

```dotenv
DB_CONNECTION=pgsql
BROKER_MODE=paper
TRADING_ENABLED=false
HUMAN_APPROVAL_REQUIRED=true
PAPER_HUMAN_APPROVAL_REQUIRED=true
STRATEGY_ENGINE_DRIVER=database
STRATEGY_ENGINE_SCHEMA_VERSION=2.0
STRATEGY_ENGINE_VERSION=0.2.0
```

`database` is the canonical Laravel/Python handoff: Laravel writes immutable engine jobs and Python consumes them through PostgreSQL. The `legacy` driver is diagnostic-only and cannot supply promotion evidence.

`HUMAN_APPROVAL_REQUIRED` protects live-mode policy. Paper mode has the separate `PAPER_HUMAN_APPROVAL_REQUIRED` switch, which defaults to `false` in code. Set it to `true` as shown when you want actionable paper decisions to stop in the Paper screen for explicit approval/rejection. Leaving it false permits the paper pipeline to continue without that operator pause; it does not weaken the independent live-mode boundary.

### 2. Install and initialize

```bash
composer install
npm install
cd backtest
python -m venv venv
venv/bin/pip install -r requirements.txt
cd ..
php artisan migrate
npm run build
```

Serve the Laravel UI using your normal local stack (for example, Herd) or start a development server in another terminal:

```bash
php artisan serve
```

With the default Artisan server, open `http://127.0.0.1:8000/dashboard`.

Configure Coinbase credentials using the repository's local credential flow, then establish an account snapshot:

```bash
php artisan broker:test-coinbase
php artisan broker:sync --broker=coinbase --sync
```

### 3. Run the development gates

```bash
vendor/bin/pint --test
php artisan test
RUN_POSTGRES_TESTS=1 php artisan test \
  tests/Feature/Trading/PostgresResearchPipelineTest.php \
  tests/Feature/Trading/StrategyExperimentWorkflowTest.php
cd backtest && venv/bin/python -m pytest -q && cd ..
npm test
npm run build
```

Do not collect research evidence from a tree that fails these gates.

## Runtime and refresh behavior

Start the managed runtime in its own terminal and leave it running:

```bash
php artisan trading:runtime
```

It supervises four allowlisted workloads:

- Laravel scheduler
- Laravel queue worker
- Python strategy engine
- Public market collector

Open `/operations`. A healthy runtime has current heartbeats, all expected processes running, no unexpected failed queue jobs, and no engine jobs stuck beyond their lease. The Operations buttons send pause, resume, and restart requests to the running local control agent; they cannot bootstrap an absent control agent. If the runtime is offline, start `trading:runtime` in a terminal first.

The console refreshes every 10 seconds while visible and every 60 seconds in the background. Failures use exponential retry up to 120 seconds. When a refresh fails after a successful load, the page keeps the last good data and labels it stale. Treat that data as historical, not current.

## Screen reference

### Overview — `/dashboard`

Use Overview to answer “what happened most recently?”

- The headline distinguishes no session, no cycle, failed cycle, proposals created, a completed HOLD-only cycle, and a running cycle.
- **Run paper cycle** queues the full paper pipeline.
- **Sync data** queues broker/account/market refresh work.
- **Evaluate now** queues a paper cycle with a diagnostic trigger. It is still a recorded cycle, not an untracked preview.
- Paper return can be `Not measured`, `Incomplete`, `Stale`, or a measured percentage.
- System health summarizes process heartbeats and pending engine jobs.
- Evidence trail lists recent cycle/activity events; asset contribution links to the attribution view.

Completion check: the latest cycle reaches `completed`, all expected steps are complete or intentionally skipped, and the explanation matches the Activity trail.

### Strategies — `/strategies`

Use Strategies to answer “why did this asset resolve to this action?” Select an asset and logical bar to inspect a specific canonical decision.

Read the inspector in three layers:

1. **Immediate answer:** action, explanation, resolution, measurement state, family/version, eligibility, actionability, logical bar, and whether an order was dispatched.
2. **Mechanics:** cross-sectional rank, canonical score, calibrated probability, gross edge, estimated cost, net edge, regime, rule checklist, grouped blockers, and the counterfactual describing what would have changed the result.
3. **Audit:** factor contributions, exact thresholds, portfolio state, risk/policy/execution/accounting context, and shortened decision/evidence lineage hashes.

Interpretation rules:

- `HOLD` plus completed pipeline steps is a valid outcome.
- “Eligible” means the evaluation could be considered. “Actionable” means all relevant gates allowed a proposal. Neither means filled.
- Positive gross edge with non-positive net edge is a cost rejection, not a calculation failure.
- A counterfactual explains the nearest recorded condition that could change the decision; it is not permission to tune the threshold after seeing the result.
- Compare hashes when reconciling a decision with a research candidate or paper session. A mismatch is a lineage problem.

The lower panels list immutable versions, recent strategy runs, and historical backtests. Backtest Sharpe/drawdown is research evidence, not current paper performance.

### Assets — `/assets`

Use Assets to understand contribution across 7-, 30-, or 90-day windows.

| Field | Meaning |
|---|---|
| P&L contribution | Net strategy/accounting result attributed to the asset for the selected eligible period. |
| Held-period linked return | Return linked only across completed attributed holdings. |
| Buy-and-hold price return | Coinbase close-to-close price context for the window. Costs are excluded. |
| Latest evaluation | The most recent strategy explanation for that asset. |

Do not compare a cost-inclusive strategy result with buy-and-hold price return as though they have identical accounting. A missing benchmark boundary is unavailable evidence, not a zero return.

### Activity — `/activity`

Use Activity as the append-only bridge from evaluation to outcome. It shows data, evaluation, HOLD/proposal, approval, execution, risk, reconciliation, and accounting events in time order.

To reconcile a cycle:

1. Find the evaluation event and logical bar.
2. Confirm its resolution and proposal outcome.
3. If approved, find the order events and fill or terminal status.
4. Confirm reconciliation completed.
5. Confirm the ledger/position changes and final snapshot on Paper.

An evaluation without a fill can be correct. A fill without reconciliation is incomplete and requires attention.

### Paper — `/paper`

Use Paper to fund and operate an isolated paper portfolio.

When no session is active:

- **Virtual capital** starts with an independent USD balance.
- **Mirror Coinbase equity** copies the latest fresh positive USD account equity as opening paper cash. It does not mirror positions or trade live.
- **Collect promotion evidence** appears with selectors only when a holdout-passing frozen finalist is available.

When a session is active:

- Header: session, funding mode, opening/current equity, fee scenario, evidence status, cash, and drawdown.
- Open positions: quantity, market value, and unrealized P&L.
- Pending proposals: explicit approve/reject actions.
- Ledger: opening cash, reservations/releases, fills, fees, and other cash entries.
- **End session** succeeds only after open positions are liquidated, pending/reconciliation-required orders are resolved, and reservations are released.

“No proposals” is not the same as “nothing happened.” Open Activity or Strategies to read the HOLD reason.

Pending proposals appear only when paper human approval is enabled. With `PAPER_HUMAN_APPROVAL_REQUIRED=false`, an otherwise actionable paper decision does not pause for the console review step.

### Operations — `/operations`

Use Operations to diagnose and control local workers.

- **Sync market data** queues a data synchronization.
- **Run full cycle** is available only with an account and active paper session.
- Process rows show desired/observed state, heartbeat, and pause/resume/restart controls.
- Queue work state shows pending and failed Laravel jobs.
- Python seam shows engine jobs pending or leased. Python receives database access but no broker credentials.
- Recent pipeline cycles show trigger, mode, current step, status, and explanation/error.

If every worker is offline, the UI controls cannot recover them because the control agent is absent. Start `php artisan trading:runtime` in a terminal.

### Research — `/research`

Use Research to compare preregistered champion/challenger experiments and verify point-in-time data health.

Read **evidence level before performance**. A high return shown with missing, incomplete, or stale evidence is not comparable to a fully measured candidate.

For each candidate, inspect:

1. role (`champion` only after a final version is linked; otherwise `challenger`), family, run status, and measurement state;
2. normal and stressed linked out-of-sample equity and drawdown;
3. aggregate return, Sharpe, profit factor, drawdown, turnover, and included costs;
4. linked outer folds and whether at least four are complete for finalist selection;
5. robustness neighborhood, avoiding an isolated lucky parameter point;
6. asset, regime, holding-period, and exit attribution;
7. benchmarks and costs; and
8. exact gate rejection reasons.

The holdout lifecycle means:

| State | Meaning |
|---|---|
| `locked` | Values remain sealed; development work may continue. |
| `authorized` | One exact finalist/manifest authorization exists; the worker has not yet recorded access. |
| `running` | The authorized worker recorded first access and is evaluating the interval. |
| `passed` | Immutable terminal pass for the exact lineage. |
| `failed` | Immutable terminal failure for the exact lineage. Do not tune against it. |
| `inconclusive` | Immutable terminal result without enough valid evidence. It is not a pass. |

The page intentionally does not expose holdout controls or locked values. Candle coverage and data-quality incidents appear below the lab. Kraken spread classifications are shadow feasibility only; execution remains disabled.

## Daily operating workflow

1. Pull/review the intended code revision and confirm `.env` safety values.
2. Start the app as usual, then start `php artisan trading:runtime` in a dedicated terminal.
3. Open `/operations` and wait for current heartbeats from scheduler, queue, engine, and collector.
4. Confirm failed queue jobs are zero and engine jobs are either progressing or absent.
5. Click **Sync market data** or run the synchronous command if you need terminal feedback.
6. Open `/research` and check final one-hour candle freshness and quality incidents.
7. Open `/paper`; start or inspect the active session.
8. Run a cycle from Overview, Paper, or Operations.
9. Follow Overview → Strategies → Activity → Paper to interpret and reconcile the outcome.

Done means the console is current, data is healthy, the latest cycle is terminal, reconciliation is complete, and the paper snapshot agrees with the ledger and positions.

## Market-data workflow

### Routine synchronization

Use the UI's **Sync data/market data** action for normal queued work, or run:

```bash
php artisan broker:sync --broker=coinbase --sync
php artisan broker:health-check --broker=coinbase
```

### Historical bootstrap

```bash
php artisan research:backfill-candles --years=8
php artisan research:maintain-market-data
```

Then inspect `/research` for canonical final one-hour coverage, lag, missing bars, and open incidents.

### Revision-preserving repair

Always plan first:

```bash
php artisan research:repair-market-evidence
```

Optionally bound the plan:

```bash
php artisan research:repair-market-evidence \
  --asset=BTC --asset=ETH \
  --start=2026-01-01T00:00:00Z \
  --end=2026-02-01T00:00:00Z
```

Review the displayed assets and batch counts. Apply that bounded plan only when correct:

```bash
php artisan research:repair-market-evidence \
  --asset=BTC --asset=ETH \
  --start=2026-01-01T00:00:00Z \
  --end=2026-02-01T00:00:00Z \
  --execute
```

After a repair, do not rewrite an existing research result. Freeze a new manifest and create new evidence where the inputs changed.

## Operational paper workflow

### Start a session

1. Sync Coinbase so `/paper` has a fresh account snapshot.
2. Open `/paper` and choose **Virtual capital** for independent cash or **Mirror Coinbase equity** to copy current USD equity.
3. For normal operational testing, leave **Collect promotion evidence** unchecked.
4. Enter positive virtual capital if applicable and click **Start paper session**.
5. Confirm an `opening_cash` ledger entry and active session header.

Only one active session is allowed per broker account.

### Run and interpret a cycle

1. Click **Run paper cycle** from Overview/Paper, or **Run full cycle** from Operations.
2. Watch the cycle steps on Operations until terminal.
3. Read the immediate result on Overview.
4. Open Strategies and inspect the exact asset/logical bar.
5. If the result is HOLD, verify the blocker/counterfactual and completed reconciliation/snapshot steps.
6. If a proposal exists, review it on Paper.
7. Confirm Activity contains the same lineage and ordered events.
8. Confirm Paper cash, reservations, positions, ledger, and snapshot after processing.

### Approve or reject a proposal

Before approval, verify asset, side/action, requested notional, expiry, decision explanation, net edge after costs, available cash/position, and risk/policy blockers.

- Click **Approve paper order** to accept the paper proposal.
- Click **Reject** to preserve a terminal operator rejection (`Rejected in local console`).

After approval, do not assume a fill. Follow Activity for submission/fill/expiry/rejection and verify reconciliation. A stale quote, price move, expired signal, risk rule, or accounting condition may still stop execution.

### End a session

1. Exit all open positions through supported strategy/paper actions.
2. Reconcile or cancel any draft, submitted, partially filled, or reconciliation-required orders.
3. Confirm no reserved cash or quantity remains.
4. Click **End session** and confirm.

Session history and evidence remain preserved.

## Decision review workflow

Use this when a result looks surprising:

1. On Strategies, select the asset and exact logical bar.
2. Confirm `Measured` versus `Not measured`/`Incomplete`/`Stale`.
3. Read resolution and eligibility before looking at scores.
4. Compare gross edge, cost estimate, and net edge.
5. Review every rule's observed/required values.
6. Read blocker groups: evidence, strategy, risk, policy, or operations.
7. Read the counterfactual without changing thresholds in response to this single outcome.
8. Expand thresholds/portfolio state and risk/policy/execution/accounting context.
9. Copy the decision/evidence hash prefixes and match them to Activity and the pinned session/research lineage.
10. If the pipeline failed, fix operations. If it completed with HOLD, preserve it as a valid strategy observation.

## Research workflow

### Preregister and monitor one experiment

Preconditions: PostgreSQL/canonical engine, healthy data, active or research universe with at least three Coinbase assets, frozen point-in-time evidence, clean test gates, and a locked holdout.

```bash
php artisan research:backtest \
  --name=development-YYYYMMDD \
  --start=2019-01-01 \
  --end=2026-01-01 \
  --capital=100000 \
  --seed=7
```

`--end` is the end of the complete window: the command reserves the final 12 months as the locked holdout and ends development at the holdout boundary. It atomically creates the experiment and four bounded candidates: `trend_rotation`, `pullback_in_trend`, `protected_momentum`, and `defensive_cash`.

Keep `trading:runtime` running. Monitor worker leases on Operations and candidate evidence on Research. A fresh leased job is active; do not enqueue a duplicate. The same preregistration content is idempotent.

### Compare candidates

For each candidate, require all of the following before considering it:

- fully measured development evidence and at least four complete linked outer folds;
- sufficient trades, assets, and regime evidence;
- positive normal and stressed expectancy;
- required Sharpe/profit-factor floors and drawdown ceiling;
- controlled asset concentration;
- a stable robustness neighborhood;
- reconciled fills, costs, equity, attribution, and benchmarks; and
- no unresolved gate rejection reason.

Reject the experiment or candidate when evidence is incomplete, inconclusive, or fails a gate. Do not choose the highest return first and rationalize missing evidence afterward.

### Freeze exactly one finalist

The repository currently has no supported UI or Artisan action that creates the final deployable `strategy_version` from a passing candidate. The holdout command deliberately refuses anything that is not already linked as `version_role=final`, `is_deployable=true`, development-only, and content-addressed.

Therefore, selection is a deliberate development step, not a console click:

1. Select no more than one candidate using development evidence only.
2. Add or use a reviewed application service/migration path that creates a new immutable final `StrategyVersion` linked to that candidate and its development manifest. Do not update an old row.
3. Freeze the resolved definition, parameter hash, engine version, calibration boundary, universe, execution-policy hash, parent version, and content hash.
4. Add PostgreSQL/feature tests proving one finalist, immutability, lineage, and a development-only calibration boundary.
5. Re-run all development gates.
6. Verify Research shows that candidate as `champion` and note the full finalist and development-manifest SHA-256 values from the immutable records.

If no candidate passes, record the rejection and preregister a new experiment. Do not open the holdout.

## Holdout workflow

### Authorize once

Before running the command, complete the **safe to open holdout** checklist below. Then run:

```bash
php artisan research:holdout FINALIST_SHA256 DEVELOPMENT_MANIFEST_SHA256 \
  --actor=local-operator \
  --purpose="single final holdout evaluation"
```

The authorization binds the exact finalist, universe, development manifest, engine/code hashes, execution policy, and interval. The worker records first access before it reads the interval. An overlapping revealed interval cannot be reused globally.

### Interpret the result

- `authorized`: wait for the engine worker; do not issue another authorization.
- `running`: the single use has begun. Do not modify the finalist or inspect intermediate holdout values.
- `passed`: the exact lineage may proceed to a new pinned forward-paper evidence session.
- `failed`: stop this lineage. Do not tune it using holdout observations.
- `inconclusive`: stop this lineage; it is not a pass. Fix general pipeline/data methodology without consulting sealed values, then preregister a new development experiment with a new non-overlapping holdout.

Terminal results are immutable.

## Forward-paper evidence workflow

### Start a pinned session

1. Confirm the holdout state is `passed` for the exact frozen finalist.
2. Confirm the canonical `database` or Python engine is configured.
3. End any active operational paper session cleanly.
4. Open `/paper`.
5. Check **Collect promotion evidence**.
6. Select the exact frozen finalist and its matching universe. Automatic defaulting is valid only when exactly one passing finalist exists.
7. Choose virtual or mirrored funding and start the session.
8. Confirm the session is evidence-eligible and its strategy, universe, and execution-policy pins are correct before the first cycle.

Those pins become immutable. A mismatch in any later cycle/evaluation fails the session.

### Monitor the gate

The gate is satisfied only when all checks pass:

| Check | Requirement |
|---|---|
| Duration | At least 90 elapsed days |
| Closed round trips | At least 15 |
| Assets traded | At least 3 |
| Regime coverage | At least 2 regimes with at least 20 common bars each |
| Accounting | Reconciliation passes |
| Manifest | Manifest reconciliation passes |
| Drawdown | Maximum paper drawdown is **15% or less**, inclusive |
| Lineage | Exact finalist, universe, execution policy, and canonical engine remain pinned |

`collecting_evidence` means no hard failure exists but one or more requirements are unfinished. `satisfied` means all requirements passed. Neither enables live trading; `live_eligible` remains false.

Drawdown above 15%, a manifest failure, or reconciliation failure changes the evidence status to `failed`, suppresses new entries immediately, and still allows exits. A pinned-version mismatch also fails the session. Do not repair a failed evidence session in place or reinterpret it under new rules; start a new eligible session for a newly valid lineage.

## Troubleshooting

| Symptom | Likely meaning | Response |
|---|---|---|
| Console says stale | Refresh/API failure; last good data is displayed. | Check Laravel/database, then Operations heartbeats and retry. |
| Runtime offline | The local control agent is not running. | Start `php artisan trading:runtime` in a terminal. |
| One process paused/offline | Desired state or child process failure. | Read its heartbeat/error; resume or restart from Operations. |
| Engine job pending | Worker absent, backlog, or lease not yet claimed. | Confirm Python engine heartbeat and database connection. |
| Engine job leased with fresh heartbeat | Work is active. | Wait; do not duplicate it. |
| Lease expired | Worker died or stopped heartbeating. | Restart engine; reclamation uses the original idempotency key. |
| Latest cycle failed | An operational step failed. | Read current step/last error on Operations and corresponding Activity events. |
| No proposal after a cycle | Often a valid HOLD. | Inspect Strategies rules, blockers, costs, and counterfactual. |
| `Not measured` | No eligible result. | Generate the relevant paper/research evidence. |
| `Incomplete` | Partial interval/folds/accounting. | Finish coverage before comparison. |
| Mirrored session rejected | Account equity is stale, non-positive, or not USD. | Sync Coinbase and retry within the configured freshness window. |
| Session will not end | Open position, unresolved order, or reservation remains. | Liquidate, reconcile/cancel, and release reservations first. |
| Proposal approval does not fill | Quote/signal expiry, price move, risk/policy, or execution outcome. | Follow Activity and order events; do not manually patch accounting. |
| Reconciliation failed | Orders/reservations/ledger/positions disagree. | Stop new evidence, inspect events and source records, fix code/data, create new evidence. |
| Research candidate rejected | A recorded development gate failed. | Preserve the rejection; change the hypothesis in a new experiment. |
| Research inconclusive | Insufficient valid evidence. | Do not call it a pass; correct methodology/data generally and preregister again. |
| Holdout authorization refused | Finalist/manifest/interval/lineage precondition failed or interval was used. | Do not bypass the guard; correct development lineage or use a new experiment. |
| Paper pins mismatch | A cycle/evaluation used different strategy or universe IDs. | Treat the evidence session as failed and investigate version resolution. |
| Evidence drawdown over 15% | Hard forward-paper failure. | New entries remain suppressed; allow/complete exits and close out safely. |

Useful terminal checks:

```bash
php artisan broker:health-check --broker=coinbase
php artisan queue:failed
php artisan trading:scorecard --broker=coinbase --days=56 --json
php artisan research:repair-market-evidence
```

## Changing a strategy

### Classify the change first

| Change type | Examples | Evidence consequence |
|---|---|---|
| Explanation/UI-only | Copy, layout, projection formatting with unchanged semantics | No new strategy evidence if contracts and values truly do not change; run PHP/JS/build tests. |
| Behavior-preserving refactor | Internal decomposition or performance work | Prove parity and causality. Keep lineage only if serialized behavior, timing, policies, and engine identity are genuinely unchanged. |
| Strategy behavior | Family logic, thresholds, parameters, state transitions, sizing | New strategy version/hash, new development experiment, new holdout lineage, and new paper session. |
| Feature/data semantics | Indicator formula, availability time, missing-data policy, canonical inputs | New code/definition hash and manifest; rerun development evidence and later stages. |
| Universe | Symbols, membership rules, effective intervals | New universe version/hash, experiment, holdout lineage, and pinned paper session. |
| Cost/execution policy | Fees, slippage, fill timing, venue/routing policy | New execution-policy hash and experiment; old results are not transferable. |
| Evidence gate | Fold/trade/drawdown/coverage rules | Reviewed policy/design change plus tests/docs. Do not retroactively relabel old evidence; collect under a new preregistration. |

When uncertain, treat the change as behavior-changing.

### Primary edit locations

- Research families/default bounds/gates: [`config/research.php`](../config/research.php)
- Operational thresholds/sizing: [`config/trading.php`](../config/trading.php)
- Strategy schema and resolution: [`backtest/trading_engine/strategy_definition.py`](../backtest/trading_engine/strategy_definition.py)
- Family evaluation: [`backtest/trading_engine/strategy_families.py`](../backtest/trading_engine/strategy_families.py)
- Feature computation: [`backtest/trading_engine/features.py`](../backtest/trading_engine/features.py)
- Portfolio construction: [`backtest/trading_engine/portfolio.py`](../backtest/trading_engine/portfolio.py) and [`app/Services/Portfolio/PortfolioConstructionService.php`](../app/Services/Portfolio/PortfolioConstructionService.php)
- State transitions: [`backtest/trading_engine/strategy_state.py`](../backtest/trading_engine/strategy_state.py)
- Execution policy: [`backtest/trading_engine/execution_policy.py`](../backtest/trading_engine/execution_policy.py)
- Evaluation path: [`backtest/trading_engine/evaluator.py`](../backtest/trading_engine/evaluator.py) and [`app/Data/Research/DecisionTrace.php`](../app/Data/Research/DecisionTrace.php)
- Research simulation/orchestration: [`backtest/trading_engine/backtest_runner.py`](../backtest/trading_engine/backtest_runner.py) and [`app/Services/Research/ExperimentService.php`](../app/Services/Research/ExperimentService.php)
- Cross-language contracts: [`contracts/`](../contracts/)
- PHP feature tests: [`tests/Feature/Trading/`](../tests/Feature/Trading/)
- Python tests: [`backtest/tests/`](../backtest/tests/)

### Edit-to-evidence procedure

1. Start a focused Git branch and write down the hypothesis and change category.
2. Identify every affected definition, feature, family, state, portfolio, execution, contract, and projection path.
3. Change the smallest coherent surface. Update contracts/fixtures when serialized meaning changes.
4. Add focused unit/feature tests for the new behavior and failure cases.
5. Add or update parity tests across Laravel/Python boundaries and append-future causality tests for point-in-time behavior.
6. Run formatting, Laravel tests, PostgreSQL gates, Python tests, JavaScript tests, and the production build.
7. Assign new semantic versions/content hashes wherever behavior, input semantics, universe, or policy changed.
8. Commit code/contracts/tests together at a reviewable boundary. Commit documentation/policy updates separately when useful.
9. Backfill/repair inputs if required, then freeze a new manifest. Never mutate the old manifest.
10. Preregister a new development experiment. Compare only development evidence.
11. Freeze one new finalist only if every development gate passes.
12. Authorize one fresh holdout for that exact lineage. Never reuse or tune against an opened interval.
13. On a pass, start a new pinned evidence-eligible paper session and collect the full forward gate.
14. Preserve all rejected, failed, and inconclusive records. Do not turn any gate into automatic live activation.

Recommended commit boundaries:

1. behavior and focused tests;
2. contracts/fixtures and cross-language parity;
3. UI/projection copy if needed;
4. policy/runbook documentation;
5. generated migration only when an immutable schema change requires it.

## Command reference

### Application and tests

```bash
php artisan migrate
vendor/bin/pint --test
php artisan test
RUN_POSTGRES_TESTS=1 php artisan test \
  tests/Feature/Trading/PostgresResearchPipelineTest.php \
  tests/Feature/Trading/StrategyExperimentWorkflowTest.php
cd backtest && venv/bin/python -m pytest -q && cd ..
npm test
npm run build
```

### Broker, runtime, and paper operations

```bash
php artisan broker:test-coinbase
php artisan broker:health-check --broker=coinbase
php artisan broker:sync --broker=coinbase --sync
php artisan broker:sync-market-data --broker=coinbase --timeframe=1d --sync
php artisan broker:reconcile-orders --sync
php artisan strategy:evaluate --mode=paper --broker=coinbase --sync
php artisan trading:runtime
php artisan trading:scorecard --broker=coinbase --days=56 --json
```

The lower-level approval command exists for a known decision ID:

```bash
php artisan trading:approve-decision DECISION_ID --actor=local-operator
```

Prefer the Paper UI for normal proposal review because it shows the surrounding context.

### Research

```bash
php artisan research:backfill-candles --years=8
php artisan research:maintain-market-data
php artisan research:repair-market-evidence
php artisan research:repair-market-evidence --execute
php artisan research:backtest \
  --name=development-YYYYMMDD \
  --start=2019-01-01 \
  --end=2026-01-01 \
  --capital=100000 \
  --seed=7
php artisan research:holdout FINALIST_SHA256 DEVELOPMENT_MANIFEST_SHA256 \
  --actor=local-operator \
  --purpose="single final holdout evaluation"
```

The unbounded repair `--execute` form is listed for completeness; prefer a reviewed, asset/time-bounded plan when the incident permits it.

## Readiness checklists

### Safe to run development

- [ ] PostgreSQL is active and migrations are current.
- [ ] Paper mode is on; live trading is off; human approval is required.
- [ ] Canonical database/Python engine schema/version is configured.
- [ ] Coinbase assets and point-in-time universe membership are present.
- [ ] Final one-hour candles, availability times, and quality incidents are valid.
- [ ] The development manifest excludes the locked holdout.
- [ ] PHP, PostgreSQL, Python, JavaScript, formatting, and build gates pass.
- [ ] Experiment objective, constraints, families, budgets, policies, seeds, and intervals are preregistered.

### Safe to open holdout

- [ ] Development work is complete; no further parameter decisions are planned.
- [ ] At least four complete linked outer folds and all configured development gates pass.
- [ ] Normal/stressed results, robustness, attribution, costs, and benchmarks are reconciled.
- [ ] Exactly one candidate was selected using development evidence only.
- [ ] One immutable deployable final strategy version links to that candidate.
- [ ] Strategy, universe, development manifest, engine/code, execution policy, and calibration hashes/boundaries match.
- [ ] The interval is still `locked` and has not been revealed or overlapped by another revealed holdout.
- [ ] Full finalist and development-manifest SHA-256 values are verified before command entry.
- [ ] You accept that authorization consumes the interval even if the result fails or is inconclusive.

### Safe to start forward paper evidence

- [ ] Holdout terminal state is `passed` for the exact finalist.
- [ ] Canonical engine and safe broker flags remain configured.
- [ ] No other paper session is active for the account.
- [ ] The UI offers the exact passing finalist and matching universe.
- [ ] Evidence collection is checked and pins are verified before the first cycle.
- [ ] You will keep the strategy, universe, execution policy, and engine unchanged for the session.
- [ ] You will monitor 90 days, 15 round trips, three assets, two qualifying regimes, reconciliation, manifest, and maximum 15% drawdown.
- [ ] You understand that `satisfied` remains paper evidence and `live_eligible=false`.

## Source map

- Console routes: [`routes/web.php`](../routes/web.php), [`routes/api.php`](../routes/api.php)
- UI page rendering/actions: [`resources/js/operations-console.js`](../resources/js/operations-console.js)
- Decision inspector: [`resources/js/console/strategy-inspector.js`](../resources/js/console/strategy-inspector.js)
- Research lab: [`resources/js/console/research-lab.js`](../resources/js/console/research-lab.js)
- Console projections: [`app/Services/Operations/OperationsConsoleQueryService.php`](../app/Services/Operations/OperationsConsoleQueryService.php), [`app/Services/Operations/StrategyTransparencyQueryService.php`](../app/Services/Operations/StrategyTransparencyQueryService.php), [`app/Services/Operations/ResearchLabQueryService.php`](../app/Services/Operations/ResearchLabQueryService.php)
- UI action controller: [`app/Http/Controllers/OperationsActionController.php`](../app/Http/Controllers/OperationsActionController.php)
- Pipeline steps: [`app/Services/Operations/PipelineCycleService.php`](../app/Services/Operations/PipelineCycleService.php)
- Runtime controls: [`app/Services/Operations/RuntimeControlService.php`](../app/Services/Operations/RuntimeControlService.php), [`config/operations.php`](../config/operations.php)
- Paper sessions/evidence: [`app/Services/PaperTrading/PaperSessionService.php`](../app/Services/PaperTrading/PaperSessionService.php), [`app/Services/Research/PaperEvidenceGateService.php`](../app/Services/Research/PaperEvidenceGateService.php)
- Experiments/holdout: [`app/Services/Research/ExperimentService.php`](../app/Services/Research/ExperimentService.php), [`app/Services/Research/HoldoutGuard.php`](../app/Services/Research/HoldoutGuard.php)
- Research protocol: [strategy research runbook](./strategy-research-runbook.md)
- Implementation design: [strategy iteration transparency design](./superpowers/specs/2026-07-19-strategy-iteration-transparency-design.md)
- Documentation design: [UI guide design](./superpowers/specs/2026-07-19-trading-operations-ui-guide-design.md)
