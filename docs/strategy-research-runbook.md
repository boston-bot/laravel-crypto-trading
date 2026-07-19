# Strategy research runbook

This runbook operates the preregistered champion/challenger workflow. It does not enable live trading. Coinbase remains the only execution venue, `BROKER_MODE=paper`, `TRADING_ENABLED=false`, and human approval remain independent safety boundaries.

## 1. Prepare and verify the research runtime

Use PostgreSQL and the canonical database/Python engine handoff:

```dotenv
DB_CONNECTION=pgsql
BROKER_MODE=paper
TRADING_ENABLED=false
HUMAN_APPROVAL_REQUIRED=true
STRATEGY_ENGINE_DRIVER=database
STRATEGY_ENGINE_SCHEMA_VERSION=2.0
STRATEGY_ENGINE_VERSION=0.2.0
```

Apply migrations, build the console, and run the gates before creating an experiment:

```bash
php artisan migrate
vendor/bin/pint --test
php artisan test
cd backtest && venv/bin/python -m pytest -q
npm test
npm run build
```

The `legacy` engine driver remains available only for diagnostics. Do not use a legacy evaluation as promotion evidence.

## 2. Establish point-in-time data integrity

```bash
php artisan broker:sync --broker=coinbase --sync
php artisan research:backfill-candles --years=8
php artisan research:repair-market-evidence
```

Review `/research` before continuing. Every universe asset needs canonical final one-hour bars, known availability times, resolved quality incidents, point-in-time membership coverage, and a frozen development manifest. A zero is not a substitute for missing evidence.

## 3. Preregister one development experiment

```bash
php artisan research:backtest \
  --name=development-YYYYMMDD \
  --start=2019-01-01 \
  --end=2026-01-01 \
  --capital=100000 \
  --seed=7
```

This atomically records the objective, constraints, cost/benchmark/attribution policies, universe, development interval, locked 12-month holdout, four approved families, bounded parameter spaces, seeds, and search budgets. Candidate specifications and experiment identity are immutable.

Start the scheduler, queue, Python worker, and public collector:

```bash
php artisan trading:runtime
```

Monitor `/operations` for leases, retries, worker heartbeats, and result consumption. Monitor `/research` for candidate measurement state, normal/stressed linked OOS curves, drawdown, folds, robustness neighborhoods, costs, attribution, benchmarks, and exact rejection reasons.

## 4. Recover development failures

- A leased job with a fresh heartbeat is running; do not enqueue a duplicate.
- An expired lease is reclaimed by the worker using the original idempotency key.
- A lineage, manifest, schema, or reconciliation mismatch is an evidence failure. Repair the source and create a new preregistered experiment; never rewrite the failed result.
- Resolve candle gaps or revisions with the market-evidence repair command, then freeze a new manifest.
- Keep the holdout locked during all repairs and parameter decisions.

## 5. Select and freeze exactly one finalist

Selection is development-only. Require at least four complete linked outer folds and all configured gates: trade/asset/regime evidence, positive normal and stressed expectancy, Sharpe and profit-factor floors, drawdown ceiling, concentration control, stable neighboring parameters, reconciled fills/equity/costs, and benchmark comparison.

Freeze one candidate as a `final` deployable `strategy_version` linked to its experiment candidate. Its definition, parameter hash, engine version, development manifest, universe, execution policy, calibration boundary, and content hash must remain unchanged. If no candidate passes, record the rejection and start a new experiment; do not open the holdout.

## 6. Authorize the globally single-use holdout

This is intentionally command-only and requires an explicit actor and purpose:

```bash
php artisan research:holdout FINALIST_SHA256 DEVELOPMENT_MANIFEST_SHA256 \
  --actor=local-operator \
  --purpose="single final holdout evaluation"
```

Authorization binds the exact finalist, universe, manifest, engine/code hashes, and interval. The worker records the first access before reading the interval. Revealed overlapping intervals can never be reused. Terminal `passed`, `failed`, or `inconclusive` results are immutable.

Do not interpret `inconclusive` as a pass. Do not reveal or tune against locked values through HTTP, logs, or ad hoc queries.

## 7. Start pinned forward paper evidence

Only a holdout-passing finalist appears in the research paper selector. On `/paper`, enable “Collect promotion evidence” and select its exact frozen strategy and universe. Automatic selection is allowed only when exactly one passing finalist exists.

The paper gate requires all of the following:

- at least 90 elapsed days;
- at least 15 closed round trips;
- at least three traded assets;
- at least two regimes with 20 common bars each;
- manifest and accounting reconciliation;
- maximum paper drawdown of 15% or less, inclusive;
- the unchanged strategy, universe, execution policy, and canonical engine.

Above 15% drawdown or on manifest/reconciliation failure, new entries are suppressed immediately while exits remain possible. Version mismatches fail the evidence session. A `satisfied` paper gate is evidence only: it never changes `TRADING_ENABLED`, broker mode, approval requirements, or live eligibility.

## 8. Interpret the console

- `/strategies` starts with the latest decision. Read the immediate answer, rule mechanics/counterfactual, then immutable audit lineage. HOLD is a complete strategy outcome, not an operational failure.
- `/research` puts candidate role and evidence level before performance. “Not measured,” “Incomplete,” and “Stale” must be resolved before comparing numbers.
- `/activity` links evaluation, policy, execution, reconciliation, and accounting events.
- `/paper` shows the pinned forward-evidence gate. It must continue to report `live_eligible=false`.

Any live-capital proposal requires a separate reviewed design and authorization process outside this workflow.
