# M5 durable strategy experiments

October 20, 2026 Central. Source implementation of an application-owned historical experiment runner. All acquisition, simulation, comparisons, accounting checks and reporting execute in maintained PHP classes. No LLM session or paid LLM API is involved in processing. This increment is not deployed or enabled in the runtime. M5 remains partial; M6 is unstarted.

## Callable components

| Class | Contract |
|---|---|
| `Experiments\ExperimentDefinition` | Validate a bounded JSON specification; freeze parameters, owner policy/cost settings, source hashes and provenance; canonical fingerprints preserve list order and decimal types |
| `Experiments\StrategyExperiments` | `start(connection, actor, requestUuid, spec)`, `work(internalId)`, `report(connection, actor, experimentUuid)`, `resume(connection, actor, experimentUuid)` |
| `RunStrategyExperiment` | Durable database-queue job; performs one bounded step and atomically queues its continuation |
| Existing `IntradayReplayInputs` / `StrategyReplay` | Acquire immutable historical inputs; replay the existing deterministic rules chronologically with carried cash/positions |
| `Experiments\ReplayAccountingVerifier` | Independently reconstruct cash, shares, closed episodes and every sampled equity mark from fills; check chronology, fees, prices, liquidity, reservations, coverage, return and drawdown |
| `Experiments\ExperimentComparisons` | Aggregate performance and breakout/pullback outcomes, compare declared variants, and compare rebuilt rules against a frozen parent baseline |

A prompt can create a specification and invoke the CLI. The application returns an experiment UUID, then the database worker continues after the prompt finishes. A later prompt invokes `report` and interprets stored values. Suggestions become a new versioned specification or tested code change; no model output changes an applied trading policy.

## Specification and supported scope

The [example](examples/strategy-experiment.json) declares a hypothesis, fixed universe, explicit ascending session dates, baseline and variants before processing. Bounds: one or two symbols, two to twenty consecutive **full** sessions, one to eight variants, all sessions covered by the reviewed cost schedule (currently Q4 2026). The calendar verifies consecutive sessions; partial acquisitions and skipped dates cannot generate performance.

Starting cash and decimal parameters are quoted strings. Permitted research overrides are `initial_stop_atr` (1–5), `trailing_stop_atr` (1–8), `max_chase_atr` (0.1–2), `time_exit_sessions` (integer 1–60), and `cooldown_sessions` (integer 1–20). Unknown fields, execution permissions, risk/allocation overrides, numeric/float money and duplicate configurations are rejected. These variants do not create or apply production parameter versions. Each pins its full resolved parameter values and fingerprint; all variants share the frozen owner settings, fees and acquired dataset.

`StrategyReplay::runQuoteWindow` adds the bounded `intraday_experiment_v1` research window (up to twenty sessions) through the same session step as existing replay. The existing two-to-five-session mode retains its version, bounds and output. The window never independently restarts each day flat.

All results are exploratory. A named hypothesis is retained as text, never executed as instructions. There is no automated optimization, winner promotion, holdout certification, strategy deployment, signal activation or brokerage mutation.

## Durable state and recovery

Additive migration `2026_10_20_000027` adds two tables to the configured schema (`postgres` / `mcp_trading` in the operator runtime):

- `mcp_strategy_experiments`: frozen encrypted definition plus mutable queued/running/paused/failed/complete state, source/definition hash, current step, attempt budget, lease and encrypted final report. Completed records and definitions cannot be edited through their models.
- `mcp_strategy_experiment_steps`: immutable encrypted acquisition/result/failure evidence. Unique keys prevent duplicate attempts or successful checkpoints; fingerprints verify payload integrity. PostgreSQL checks constrain states, completion evidence and lease consistency.

Each session acquisition, each verified variant and the final comparison are separate checkpoints. Completed sessions are reused on restart. A crash during acquisition can repeat that session's read-only requests; there is no per-quote checkpoint. Variants always replay the complete frozen window chronologically. The worker does not hold a database lock across network calls or calculations.

Definitions and initial queue insertion commit together. A successful checkpoint, status update and next database job also commit together on the authoritative connection. The queue is `experiments`, on `mcp_experiments`, separate from monitoring and the disabled AI research queue. Normal starts are duplicate-safe by account/scope/request UUID; changed input under the same request conflicts. Duplicate jobs are harmless under the row lock and lease. A stale worker cannot commit or clear a replacement lease.

Timing: job timeout 300 seconds, lease 360 seconds, database reservation retry 420 seconds. Read failures classified as rate limiting/transport/HTTP failure get at most three attempts per step, with delayed retries. Access denials, rejected inputs, unsupported actions and verification failures stop with retained failure evidence and no performance from that failed step. Earlier verified variants remain inspectable as partial progress; there is no final comparison until all steps pass.

An explicit `resume` preserves checkpoints and all recorded attempts, and grants three further attempts for unfinished work. It refuses an active lease. Authorization/binding changes, disabled gates, altered definitions/checkpoints or changed source hashes pause work. Source files are checked against both the saved definition and the worker process's original signature, preventing an old, long-lived worker from accepting a new code definition merely because files changed on disk. Restart workers after deployment. An experiment cannot be silently resumed under new rules; retain the matching release or start a new experiment. Schema migration/deployment does not enable either research flag.

No application endpoints, cloud callbacks, scheduled LLM calls or notifications are added. Reading reports is operator/account scoped. This first version also pins the original authorization generation; reconnection with a new generation does not silently resume or expose that old experiment through the command.

## Rebuild and compare

For another experiment on exactly the same observations, copy the specification, add `"dataset_experiment": "<completed-experiment-uuid>"`, and declare the new variants/hypothesis. The parent must be complete and belong to the same authorized account, with identical symbols, dates and data contract. Its acquisition checkpoints are copied through verified reads, not refetched from the provider. The canonical dataset hash must remain identical.

The final report calculates differences versus the new experiment's baseline and a separate reference comparison versus the parent's frozen baseline. Source changes are disclosed. Cross-experiment performance differences are withheld when starting capital or owner cost/risk/allocation settings differ; those rows explain why they are incomparable. A larger virtual balance is a capital scenario, never a funding recommendation.

Reports include return, observed drawdown, exposure, hit rate/profit factor where defined, order/fill/closed-trade counts, exact closed-trade net P&L, fees, open-episode marked P&L, quote coverage, blocked-entry reasons, separate breakout/pullback summaries and verification counts. Full engine results and dated profiles remain in encrypted checkpoints. A price-only equal-weight universe reference uses the first sampled ask and terminal bid only when all required quotes are usable; otherwise it is unknown. It excludes fees/dividends and is not a total-return benchmark. SPY quote performance is explicitly unavailable because this acquisition only obtains SPY's prior daily profile history.

Zero closed trades yields an inconclusive assessment. Missing held marks produce unknown equity/return. Existing fixed-universe, earnings/eligibility assumptions, strict sampled-opening baseline, corporate-action exclusion, event-time latency and sampled-liquidity limitations remain. The runner makes the research reproducible and unattended; it does not cure those evidence gaps.

## Verification

Final full PostgreSQL suite: **598 passed, 4,165 assertions, one existing PHP-FPM environment skip** (599 total). SQLite: **564 passed, 3,837 assertions, 35 skips**, including the new PostgreSQL-only race test. Tests use fake sources and refuse unexpected HTTP.

Coverage includes actual database queue serialization/pop/continuations; encrypted definitions/checkpoints; complete comparisons; idempotency; bounded 429 retries; resume with retained failures; cached-data/reference comparisons; missed/expired/replaced leases; authorization/gate changes during HTTP; queue-insert rollback; source mismatch; CLI JSON submission/reporting; decimal and specification restrictions; accounting tampering and unknown marks. Three PostgreSQL start processes produced one experiment/job; three competing workers committed one acquired session. Database constraints reject missing successful checkpoint identity. A seven-session synthetic replay verifies the wider window preserves earlier cash/trades and leaves the original five-session API bounded.

The maintained verifier also checked both frozen real CRM/MSFT October 19–20 results: 158 frames each, zero/one fills, exact cash/mark reconciliation passed. This was offline verification of existing evidence, not a new queued real-data trial or profitability result. The real queued end-to-end deployment, LaunchAgent execution and twenty-session provider endurance remain untested.

Composer validation, changed-file Pint, shell syntax, experiment plist validation and diff checks passed. Initial fixture timestamp/zero-fee assumptions and nullable-message assertions were corrected. The PostgreSQL constraint test exposed SQL CHECK's null behavior; the successful-checkpoint branch now explicitly requires non-null identity and the final race/constraint suite passed. No dependencies or frontend assets changed.

No runtime migration, backfill, deployment, persistent private configuration change, policy application, forward paper-ledger write, live provider call, broker mutation or LLM API call occurred in this increment. Existing private environments and source work were preserved.

Next: deploy reviewed source/migration, enable the two historical research gates and install the dedicated worker, then submit a predefined experiment and inspect it by UUID. Broader historical evidence, dated universe/earnings, adequate opening data, regular-session cadence and controlled forward-paper acceptance remain M5 work.
