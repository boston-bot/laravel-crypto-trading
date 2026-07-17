# Market Evidence and Logical-Bar Cycle Reliability Design

Date: 2026-07-16
Project: `laravel-crypto-trading`
Status: Approved for implementation planning

## Purpose

Make the paper-trading pipeline produce complete, trustworthy strategy evidence before any strategy thresholds or trade-frequency settings are changed.

The current scheduler runs, but the pipeline can finish without asset evaluations. Existing candle data also mixes mislabeled intervals, non-UTC buckets, and duplicate provider identities. These defects make the absence of trades uninterpretable: the system cannot distinguish a valid HOLD from missing or discarded evidence.

Phase 1 fixes evidence correctness and cycle lifecycle only. It remains paper-only and does not claim strategy performance.

## Current Failure Modes

The design addresses these observed failures:

1. A requested Coinbase `1h` interval falls through to `ONE_DAY`, then is stored as `1h`.
2. Python backfill writes timezone-aware values into PostgreSQL `timestamp without time zone` columns while the session uses `America/Chicago`, shifting UTC bucket boundaries.
3. Multiple Coinbase acquisition paths are represented as competing sources, allowing duplicate or inconsistent evidence.
4. Cycle eligibility selects the latest valid four-hour candle globally rather than the latest close shared by the exact eligible universe.
5. Engine-result validity is anchored to the historical logical-bar close. Late consumption marks the result expired and discards every proposal before creating `AssetEvaluation` records.
6. Minute scheduler heartbeats create duplicate logical-bar attempts. A duplicate engine job can leave `evaluation` running while the overall cycle is marked completed.
7. Cycle completion does not assert that all child steps are terminal.

## Scope

Phase 1 includes:

- explicit Coinbase hourly acquisition;
- UTC normalization in Laravel and Python PostgreSQL sessions;
- deterministic complete-bucket derivation of four-hour and daily candles from canonical hourly candles;
- canonical provider selection and common-universe bar eligibility;
- one trading evaluation per logical-bar identity;
- evidence-first engine-result consumption;
- separate evidence eligibility and order actionability;
- terminal cycle-state enforcement and recovery;
- an operator-run, dry-run-by-default market-evidence repair command;
- regression tests and operational coverage metrics.

## Non-goals

Phase 1 does not:

- change entry, exit, ranking, probability, or risk thresholds;
- increase trade frequency as a goal;
- enable live trading or remove human approval controls;
- redesign paper portfolio accounting or order sizing;
- establish PHP/Python strategy parity;
- repair backtest look-ahead or promotion gates;
- convert existing timestamp columns to `timestamptz`;
- delete historical candle rows;
- count existing paper results toward promotion.

Those items require later, separately validated phases after evidence coverage is stable.

## Domain Terms

### Canonical Market Evidence

The point-in-time candle set permitted to influence a trading evaluation. It uses the canonical provider identity, UTC-aligned intervals, final and acceptable quality states, and `available_at <= evidence_cutoff`.

### Logical Bar

The shared close time of the complete four-hour candle evaluated by a strategy run. For four-hour trading, valid closes occur at `00:00`, `04:00`, `08:00`, `12:00`, `16:00`, and `20:00` UTC.

### Common Eligible Bar

The newest logical bar for which every asset in the pinned universe has canonical, final, valid, point-in-time-available evidence.

### Evidence Eligibility

Whether an evaluation was supported by complete and valid point-in-time evidence. Eligibility does not expire after the fact.

### Actionability

Whether an otherwise eligible non-HOLD evaluation may create a trade decision at consumption time. Actionability can be suppressed by result age, logical-bar age, incomplete engine output, diagnostic mode, or safety policy.

### Evidence Cutoff

The UTC time at which the scheduler observed the market-evidence state used for a cycle. It limits `available_at` and `first_seen_at` independently of the Logical Bar, which limits candle close times.

## Architecture

### Canonical Market Evidence Module

This is a deep module that owns:

- provider identity and acquisition metadata;
- timeframe-to-provider granularity mapping;
- UTC parsing and storage invariants;
- complete hourly-bucket aggregation;
- finality and quality rules;
- point-in-time availability;
- common-universe coverage;
- latest logical-bar selection;
- repair detection and post-repair validation.

Its interface exposes two caller-level capabilities:

1. Refresh the configured universe and return a coverage result.
2. Resolve the newest common eligible bar for an exact asset set and `evidence_cutoff`.

Callers do not select candle sources, infer close times, build completeness queries, or choose a bar independently. These details remain inside the module to provide locality and leverage.

The initial canonical provider identity is `coinbase`. Authenticated and public Coinbase endpoints are acquisition paths recorded in metadata, not distinct providers eligible to compete for a logical bucket. Kraken remains comparison-only.

### Logical-Bar Cycle Module

This module owns:

- logical cycle identity;
- cycle creation and idempotency;
- valid step transitions;
- engine wait/resume behavior;
- terminal-state assertions;
- lease recovery;
- final cycle summaries.

A trading-cycle identity contains:

- broker account;
- mode;
- evaluation kind;
- strategy version;
- universe version;
- logical-bar close.

The database enforces uniqueness for this identity. The idempotency check occurs before a `StrategyRun` is created.

Diagnostic evaluations use a distinct evaluation kind and therefore cannot collide with or satisfy a trading-cycle identity.

## Timestamp Policy

UTC is a storage and computation invariant.

- Laravel normalizes every parsed provider timestamp to UTC before persistence.
- Laravel PostgreSQL connections explicitly set the session time zone to UTC.
- Python PostgreSQL connections explicitly execute `SET TIME ZONE 'UTC'` before reading or writing temporal evidence.
- Logical buckets use epoch-aligned UTC boundaries.
- Existing `timestamp without time zone` columns remain in Phase 1 to avoid a broad conversion migration. The application treats their values as UTC by contract.
- Tests run with a non-UTC host/application timezone to prove that stored values do not shift.

`market_candles.first_seen_at` already exists. It means the first time this installation durably observed the current candle identity:

- live ingestion sets it to the UTC ingestion time on insert and never changes it on update;
- historical backfill sets it to the actual UTC backfill observation time, not the historical candle close;
- a repaired row keeps its trustworthy existing first-seen value when the identity is corrected, while a newly fetched identity receives the repair observation time;
- a legacy row with null `first_seen_at` is ineligible until the repair command copies its existing row into revisions and sets `first_seen_at` to the earliest trustworthy non-null value among `ingested_at` and `created_at`;
- if neither trustworthy value exists, the row remains invalid and the repair reports it for refetch.

The repair never invents a historical first-seen time from `candle_open_time`, `candle_close_time`, or `available_at`.

## Candle Acquisition and Aggregation

### Hourly acquisition

Coinbase timeframe mapping is explicit. `1h` maps to a one-hour provider granularity and cannot fall through to a default. Unknown timeframes fail closed with a validation error.

Each stored hourly candle must satisfy:

- `candle_close_time - candle_open_time = 1 hour`;
- open time aligned to an exact UTC hour;
- positive OHLC values with coherent high/low bounds;
- explicit source/provider identity;
- explicit acquisition path in metadata;
- finality derived from provider status and close time;
- availability no earlier than the candle close for live ingestion.

### Derived candles

Four-hour and daily candles are derived from canonical hourly rows rather than trusted from a separate provider interval.

A four-hour candle is valid only when it contains exactly four distinct consecutive valid hourly rows. A daily candle requires exactly 24. Derived values are:

- open: first child open;
- high: maximum child high;
- low: minimum child low;
- close: last child close;
- volume: sum of child volume;
- `available_at`: maximum child availability;
- final: true only when every child is final;
- quality: valid only when count, continuity, alignment, and child quality all pass.

Derived metadata records the child range and a deterministic child-content hash. Rebuilding the same bucket is idempotent. If content changes, the previous current row is copied to `market_candle_revisions` before update.

## Common-Universe Eligibility

The universe is resolved once and pinned for the cycle. Eligibility is calculated for that exact set.

The newest eligible logical bar is the maximum four-hour close for which every pinned asset has one canonical candle satisfying:

- final;
- quality `valid` or `verified`;
- UTC alignment;
- `available_at <= evidence_cutoff`;
- `first_seen_at <= evidence_cutoff` for live/paper cycle evidence;
- matching logical close.

If any asset is missing, no partial trading evaluation starts. The refresh records a data-quality incident and exposes the missing assets/ranges. A later heartbeat may retry after the evidence arrives.

The two temporal cutoffs have distinct purposes:

- `logical_bar_close` prevents features from seeing a later market interval;
- `evidence_cutoff` prevents features from seeing evidence that the running system had not observed when the cycle was requested.

The engine payload carries both. Its existing `as_of` remains the logical-bar close; candle selection additionally applies `available_at <= evidence_cutoff` and, for live/paper cycle replay, `first_seen_at <= evidence_cutoff`. Historical research may use a manifest-defined observation policy, but it cannot silently substitute the current wall clock.

Backfilled or repaired rows retain source-theoretical `available_at` based on bar completion and record actual local observation in `first_seen_at`. An old repaired bar may be evaluated for evidence diagnostics, but the configured logical-bar age makes it non-actionable for trading.

## Scheduler and Cycle Data Flow

1. The minute heartbeat locates the active account and paper session.
2. It refreshes canonical hourly evidence and derived buckets.
3. It resolves the pinned strategy version, universe version, and newest common eligible bar.
4. It asks the Logical-Bar Cycle module to create or return the cycle for that exact identity.
5. If the identity already has a terminal cycle, the heartbeat records a duplicate suppression metric and performs no evaluation work. It never creates another `StrategyRun`.
6. A newly created cycle submits one engine job and transitions to `waiting_engine` when asynchronous work is pending.
7. Result consumption persists evaluations before considering decisions.
8. Reconciliation and the paper snapshot run only after result consumption reaches a terminal state.
9. The cycle completes only after every step is `completed`, `skipped`, or `failed` according to the state rules.

No active paper session means no trading cycle is created. The heartbeat may still refresh market evidence and report readiness.

When the logical identity already has a nonterminal cycle, the module locks and returns that row:

- a nonexpired cycle lease means another worker owns progress, so the heartbeat does not dispatch work;
- an expired cycle lease is atomically claimed for recovery and the same cycle ID is dispatched;
- terminal steps remain untouched and the runner resumes from the first nonterminal step;
- a `waiting_engine` step keeps its original engine job ID;
- a succeeded engine job resumes result consumption;
- a pending engine job remains waiting for engine lease recovery;
- a failed or expired engine job fails the evaluation step unless its result was already durably persisted;
- recovery never submits a second engine job or creates a second `StrategyRun` for the identity.

Automatic heartbeats do not reopen a terminal failed cycle. A later manual recovery design may provide an explicit operator action, but it is outside Phase 1.

## Cycle State Machine

Each logical cycle contains this ordered step inventory:

1. `data_refresh`;
2. `eligibility`;
3. `evaluation`;
4. `result_consumption`;
5. `reconciliation`;
6. `snapshot`.

Because the heartbeat must know the logical identity before creating the cycle, it performs refresh and common-bar resolution first. A newly created cycle records `data_refresh` and `eligibility` immediately as completed with the immutable refresh coverage context used to create it. The remaining steps execute asynchronously. This preserves the Operations view vocabulary without allowing a minute-based pre-eligibility attempt to masquerade as a logical-bar cycle.

Allowed nonterminal transitions are:

- `queued -> running`;
- `running -> waiting_engine`;
- `waiting_engine -> running` when a result is ready.

Any active step may transition to `completed`, `skipped`, or `failed` when its documented condition occurs. Terminal steps cannot return to an active state. `reconciliation` and `snapshot` may be skipped for diagnostic evaluations or after a non-actionable evidence-only outcome; their reason is required.

Top-level outcome is deterministic:

- `completed` requires every step to be `completed` or `skipped` and no step to be `failed`;
- `failed` is required when any step is `failed`;
- stale or expired actionability alone is not a failure because evaluation evidence was successfully persisted;
- incomplete or mismatched engine output fails `result_consumption`, skips downstream steps with reasons, and fails the cycle;
- a completion attempt with any `queued`, `running`, or `waiting_engine` step is rejected; in the same transaction, every offending active step transitions to `failed` with reason code `parent_terminal_state_violation`, an explanatory error, and `completed_at`, then the cycle transitions to `failed`.

Finalization and the terminal-state assertion execute in one transaction, preventing a contradictory top-level/child state from becoming visible.

Lease recovery may resume an idempotent operation or fail an abandoned step. It does not manufacture success. Result consumption and finalization remain safe to retry.

## Evidence-First Result Consumption

The engine job retains `as_of = logical_bar_close` for point-in-time feature computation.

Before creating decisions, the consumer validates:

- schema and engine-result identity;
- strategy version;
- universe version;
- logical-bar close;
- evaluation kind;
- exact expected asset set.

The consumer persists one `AssetEvaluation` for every expected asset. An engine proposal that is absent for an expected asset becomes an explicit ineligible HOLD with reason code `missing_engine_proposal`.

If the output asset set is incomplete or contains unexpected assets:

- all expected evaluations are still persisted;
- unexpected proposals are recorded in the cycle error context but cannot create evaluations for assets outside the pinned universe;
- every evaluation from that result is non-actionable;
- no trade decisions are created;
- the strategy run and cycle finish with an explicit engine-output validation failure.

Engine-result expiry never prevents evidence persistence.

Retry safety is enforced by two exact uniqueness rules:

- `(engine_result_id, asset_id)` is unique when `engine_result_id` is present;
- trading evaluations are unique on `(coalesce(strategy_version_id, 0), coalesce(universe_version_id, 0), broker_account_id, asset_id, logical_bar_close, mode)` when `evaluation_kind = 'trading'`.

The consumer uses these keys for idempotent upsert/readback. A retry reports the already-persisted evaluation and never emits a second activity event or decision.

## Evidence Eligibility and Actionability

`asset_evaluations` gains explicit actionability fields:

- `actionable` boolean, default false;
- `action_suppressed_at` nullable timestamp;
- `action_suppression_reason` nullable string.

`eligible` continues to describe evidence quality. An evaluation may therefore be eligible but non-actionable.

For a complete result, a non-HOLD trading evaluation is actionable only when all are true:

- evidence is eligible;
- evaluation kind is `trading`;
- result processing TTL has not elapsed since result production;
- configured maximum logical-bar age has not elapsed;
- no existing decision is linked to the evaluation;
- downstream policy and risk checks permit a decision.

The action deadline is the earlier of:

- result production time plus the configured result-processing TTL;
- logical-bar close plus the configured maximum signal age.

The first implementation retains the existing 30-minute result-processing TTL and adds a configurable four-hour maximum signal age. These are safety limits, not strategy tuning, and both are surfaced in evaluation evidence.

HOLD, diagnostic, expired, stale, incomplete, and policy-suppressed evaluations remain visible with explanations and reason codes.

## Database Changes

A forward migration will:

- add `logical_bar_close`, `strategy_version_id`, `universe_version_id`, and `evaluation_kind` to `pipeline_cycles` where not already represented directly;
- add the explicit actionability fields to `asset_evaluations`;
- add a unique logical-cycle index across account, mode, evaluation kind, coalesced strategy/universe version identities, and logical-bar close;
- add indexes supporting common-bar coverage and action-suppression reporting;
- preserve existing rows with nullable/backward-compatible defaults.

The common-bar index includes canonical provider, timeframe, quality/finality, close time, `available_at`, and `first_seen_at` as supported by each database driver. No new first-seen column is required because `market_candles.first_seen_at` already exists; null legacy values are handled by the repair policy above and fail closed before repair.

The existing `market_candle_revisions` table remains the immutable before-image store for candle repair and ingestion changes; Phase 1 does not replace or truncate it.

Migration code must support PostgreSQL and the project’s SQLite test posture. PostgreSQL-specific partial or expression indexes use explicit driver branches.

No existing migration file is rewritten for deployed databases; Phase 1 uses a new migration.

## Repair Command

The operator interface is:

```text
php artisan research:repair-market-evidence
    {--asset=*}
    {--start=}
    {--end=}
    {--execute}
```

Without `--execute`, the command is read-only and exits after printing a deterministic repair plan. The plan reports:

- asset and provider;
- affected timeframes and date ranges;
- daily-as-hourly interval violations;
- UTC bucket misalignment;
- incomplete aggregate buckets;
- canonical-source collisions;
- missing hourly ranges;
- row counts to revise, invalidate, insert, and rebuild.

With `--execute`, work is processed in bounded per-asset date batches:

1. Revalidate the target range so a stale dry-run assumption cannot broaden the mutation.
2. Refetch and validate the Coinbase hourly range before opening the database mutation transaction.
3. In one batch transaction, copy every row that will change into `market_candle_revisions`.
4. In the same transaction, mark corrupt legacy current rows `invalid` and attach repair metadata.
5. In the same transaction, ingest correct hourly rows under canonical provider identity `coinbase`.
6. In the same transaction, rebuild complete four-hour and daily buckets.
7. In the same transaction, run the coverage validator and create or resolve the successful repair incident details.

The command does not delete candles. A failed batch rolls back all database mutations from that batch, leaving its pre-run evidence unchanged. After rollback, the command writes a failure incident in a separate transaction containing the bounded asset/range and error; that incident is audit context only and cannot make evidence eligible. The command then returns a nonzero exit status. Rerunning the command is idempotent.

The command is never invoked automatically by scheduler cycles.

## Error Handling

- Unknown timeframe: reject before making the provider request.
- Provider failure: leave existing evidence unchanged, record refresh failure, and do not start a new logical-bar cycle.
- Incomplete hourly bucket: retain or create a non-valid derived row only for diagnostics; never return it as eligible evidence.
- Missing universe asset: record the gap and wait for a later heartbeat.
- Engine result mismatch: persist suppressed evaluations and fail the evaluation outcome without decisions.
- Expired or stale result: persist evaluations, mark action suppression, continue terminal cycle finalization.
- Cycle terminal-state violation: fail the cycle and expose the offending steps.
- Repair batch failure: rollback every evidence mutation in the batch, write failure audit context separately, and stop with a nonzero exit.

## Observability

Research and Operations data will expose:

- latest common eligible logical bar;
- expected versus actual evaluations by logical bar;
- missing assets and candle ranges;
- canonical, invalid, comparison-only, and misaligned candle counts;
- duplicate logical-bar suppression count;
- engine results awaiting consumption;
- evidence persisted but action suppressed, grouped by reason;
- completed-cycle terminal-state violations;
- refresh and repair incidents.

An empty set is not considered healthy merely because no risk rejection occurred.

## Test Strategy

### Laravel unit and feature tests

- each supported Coinbase timeframe maps explicitly; `1h` produces a 3600-second interval;
- unknown timeframes fail closed;
- ingestion normalizes offsets to UTC;
- live ingestion preserves immutable `first_seen_at` values on revision;
- null legacy `first_seen_at` values fail eligibility closed until repaired;
- four-hour aggregation requires four complete consecutive hours and aligns to UTC epoch boundaries;
- derived availability and finality are inherited correctly;
- canonical-source selection ignores comparison-only rows;
- common-bar selection requires every pinned asset;
- a repeated heartbeat does not create a duplicate cycle or strategy run;
- completion fails when any cycle step is nonterminal;
- expired and stale results create evaluations but no decisions;
- missing or unexpected proposals suppress the complete result;
- every expected asset receives exactly one evaluation;
- repair dry run performs no writes;
- repair execution preserves revisions, invalidates corrupt rows, rebuilds evidence, reports failures, and is idempotent.

### PostgreSQL integration tests

- Laravel and Python round-trip timestamps without timezone shifts while the host/application timezone is non-UTC;
- logical-cycle and evaluation uniqueness constraints hold under concurrent attempts;
- repair batches and candle revisions are transactional;
- common-bar coverage queries use canonical indexes and return the expected asset cardinality.

### Python tests

- every opened connection sets UTC;
- backfill stores UTC-aligned hourly, four-hour, and daily values;
- complete aggregation behavior matches the Laravel fixtures;
- shared fixtures produce identical bucket boundaries and OHLCV values.

### Existing suites

Run the complete PHP, PostgreSQL-specific PHP, Python, and JavaScript suites after focused tests pass.

## Acceptance Criteria

For the initial five-asset universe:

- six eligible four-hour closes produce 30 expected evaluations per full UTC day;
- evaluation coverage is at least 99% after the stabilization window;
- no engine result is consumed without its expected evaluations being persisted;
- no completed cycle has a queued, running, or waiting step;
- no duplicate `StrategyRun` exists for one logical-cycle identity;
- no decision is created from stale, incomplete, invalid, or noncanonical evidence;
- the repair command dry run is mutation-free;
- repair execution is revision-preserving and repeatable;
- live trading remains disabled.

The first target is trustworthy evaluations, not a higher transaction count.

## Rollout

1. Add the backward-compatible migration and module implementation.
2. Deploy with paper mode and existing live safety controls unchanged.
3. Run focused and complete automated tests.
4. Run the repair command without `--execute` and review its exact plan.
5. Obtain separate operator confirmation before `--execute`.
6. Execute repair and verify common-universe coverage and incident resolution.
7. Observe at least one complete UTC day of logical bars with the acceptance metrics.
8. Start a new paper session pinned to an exact strategy and universe version.
9. Exclude all prior paper statistics from promotion evidence.

After Phase 1 stabilizes, Phase 2 may address paper portfolio context and PHP/Python strategy parity. Backtest validity and strategy performance iteration remain later phases.
