# Strategy Iteration and Transparency Design

Date: 2026-07-19  
Project: `laravel-crypto-trading`  
Status: Approved in conversation; pending written-spec review

## Purpose

Create a disciplined champion/challenger research loop that improves absolute out-of-sample return without tuning to known outcomes, and make every research result and live paper decision understandable from the UI.

The selected mandate is:

- maximize compounded out-of-sample return;
- reject any candidate whose maximum drawdown exceeds 15%;
- keep the most recent 12 months locked until a candidate is frozen;
- remain compatible with Coinbase spot through long/cash rotation;
- use adaptive swing holdings, normally 2–21 days;
- show strategy behavior through a layered, decision-first interface.

## Current-State Findings

The existing UI and research pipeline can create a misleading impression of strategy performance:

- The 90-day values currently shown on the Assets screen are Coinbase buy-and-hold benchmarks, not strategy returns. At inspection time they included BTC at approximately -15.1% and ETH at approximately -19.5%.
- Paper strategy contribution was 0%, the active virtual paper session had no fills, and no completed `backtest_runs` existed.
- The Performance UI therefore had no measured 90-day strategy result to compare with the benchmark.
- The virtual paper session held $10,000 cash, but decision sizing and risk checks used the live Coinbase account's equity, buying power, positions, exposure, and drawdown. This prevented valid paper proposals from using virtual capital.
- Risk failures and HOLD outcomes are currently persisted with the generic `blocked_by_policy` status, obscuring the actual reason.
- The Python backtest strategy and live PHP strategy do not implement the same decision rules.
- The current backtest validation window is constructed but not used for parameter selection.
- The locked holdout is excluded from folds but is never evaluated as a distinct final stage.
- The current backtest's six-bar future return is a 24-hour target, which does not match the intended multi-day swing horizon.
- Historical replay does not yet model the same cross-sectional ranks, thesis state, hysteresis, or paper portfolio context used by the intended live strategy.
- Current result storage is rich enough to prove a run occurred but not yet sufficient to explain parameter selection, rejected alternatives, counterfactual decisions, or holdout access.

These are evidence and parity problems. Improving displayed numbers by adjusting thresholds before fixing them would create a high risk of overfitting and false confidence.

## Goals

1. Produce several interpretable long/cash strategy candidates rather than repeatedly mutating one strategy.
2. Select candidates using nested walk-forward evidence without access to the locked 12-month holdout.
3. Optimize compounded net out-of-sample return subject to a 15% maximum-drawdown ceiling and robustness gates.
4. Use one canonical strategy definition and evaluator for historical replay and live paper evaluations.
5. Make paper sizing and risk depend on the active paper session.
6. Persist a reconstructable decision trace for ENTER, HOLD, EXIT, and blocked outcomes.
7. Separate strategy, benchmark, and relative performance clearly in the UI.
8. Provide a repeatable iteration loop with immutable candidates, manifests, costs, and promotion evidence.

## Non-Goals

- Enabling live trading or relaxing live safety controls.
- Adding short positions, leverage, derivatives, or margin.
- Optimizing directly against the known recent benchmark decline.
- Promoting a black-box model that cannot explain individual decisions.
- Maximizing trade count or forcing capital deployment when cash has better expected value.
- Treating paper results from an unpinned strategy or universe as promotion evidence.

## Strategy Research Architecture

### Immutable Champion/Challenger Candidates

Each candidate is an immutable `StrategyVersion` whose `definition_json` fully specifies:

- strategy family and semantic version;
- feature definitions and horizons;
- factor weights or deterministic combination rules;
- regime and breadth rules;
- entry, continuation, exit, and cooldown rules;
- rank-persistence and hysteresis rules;
- portfolio limits and allocation rules;
- expected-edge and execution-cost hurdles;
- parameter search provenance;
- engine schema and implementation version.

Changing any value creates a new content hash and a new candidate version. Historical and paper results remain pinned to the exact version that produced them.

### Candidate Families

The first research round contains four understandable behaviors:

1. **Trend rotation** — own only the strongest assets while market trend and breadth remain constructive.
2. **Pullback in trend** — enter controlled retracements inside established uptrends and reject extended entries.
3. **Protected momentum** — follow persistent momentum while using volatility-shock, regime, and deterioration exits.
4. **Defensive cash baseline** — hold no risky asset when no candidate clears the net edge and risk hurdle.

The first three are challengers. The defensive cash behavior is both a valid portfolio state and a baseline. Candidate families may share the same feature library, cost model, state machine, and portfolio allocator; their entry thesis must remain distinct and visible.

### Canonical Evaluator

The Python engine becomes the canonical evaluator for both historical replay and live paper jobs. Laravel remains responsible for orchestration, evidence eligibility, risk and policy enforcement, accounting, execution, audit, and presentation.

The canonical evaluator accepts:

- immutable strategy definition;
- immutable universe version;
- logical bar and evidence cutoff;
- canonical point-in-time market evidence;
- current strategy portfolio state;
- cost and execution-quality context.

It returns an evaluation for every asset plus a portfolio recommendation and decision trace. The legacy PHP adapter may remain available for diagnostics, but its output cannot be used as strategy-promotion evidence.

This boundary removes the need to maintain subtly different Python replay and PHP live signal logic. Golden fixtures still verify that the database job contract, decoded strategy definition, and stored output are stable across language boundaries.

## Trading Behavior

### Long/Cash Portfolio

The strategy holds zero to three assets and may hold 100% cash. It does not need to remain invested.

Portfolio construction rules:

- rank all eligible assets at each final common 4-hour bar;
- require an asset-specific entry thesis and a portfolio-level capacity check;
- favor stronger and less-correlated candidates;
- cap gross exposure at 100%;
- cap a single asset at a configurable level no greater than 40%;
- prevent pyramiding unless a later strategy version explicitly defines a separately tested scale-in state;
- preserve cash when no asset clears the expected-edge hurdle;
- size and evaluate the portfolio using the current paper or live context selected by mode.

### Entry Requirements

An entry requires all of the following:

- constructive market regime and acceptable breadth;
- positive multi-horizon trend and momentum;
- qualifying cross-sectional rank and rank persistence;
- acceptable volatility and execution quality;
- adequate point-in-time history;
- either a controlled pullback or a confirmed, non-extended breakout;
- expected net return above estimated fees, spread, slippage, and a configurable uncertainty buffer;
- available portfolio capacity after correlation and drawdown constraints.

The evaluator must record failed conditions, not only passed conditions. A high rank alone cannot authorize an entry.

### Anti-Chasing Rules

Each candidate defines an acceptable entry zone using volatility-normalized distance from trend, recent high, or breakout level. An otherwise attractive candidate is rejected when it is too extended.

The decision trace states the rejection concretely, for example:

> Ranked first with constructive momentum, but price was 5.8% above the preferred entry zone. The strategy refused to chase.

The stored counterfactual may state the nearest realistic condition that would change the decision, but it must not promise that a future trade will occur.

### State Machine and Adaptive Holding

Each asset follows an explicit state machine:

- `flat`;
- `entry_confirming`;
- `open`;
- `exit_confirming`;
- `cooldown`.

Entering requires stronger evidence than continuing to hold. A small rank change cannot create immediate churn. Confirmation and hysteresis durations are parameters selected inside the development process.

Typical holdings should last 2–21 days. Earlier exit is allowed for protective conditions. A position is not retained merely to satisfy a minimum holding period.

### Exit Requirements

An exit may occur when:

- the market regime becomes risk-off;
- the original trend, momentum, pullback, or breakout thesis is invalidated;
- relative strength or rank deteriorates beyond its hysteresis boundary;
- a volatility-adjusted protective stop is reached;
- expected edge becomes negative after remaining execution costs;
- a trailing-profit rule protects a meaningful gain;
- a maximum thesis duration is reached without expected progress.

Every exit stores its primary and contributing reasons and compares them with the original entry thesis.

## Paper and Live Portfolio Context

Sizing and risk services must consume a mode-specific `PortfolioContext` rather than reading `BrokerAccount` state directly.

### Paper Context

Derived from the active pinned `PaperSession`:

- ledger cash minus reserved cash;
- marked-to-market paper equity;
- paper positions and exposure;
- paper drawdown and realized losses;
- paper correlation and concentration;
- strategy and universe version IDs.

### Live Context

Derived from fresh broker account state:

- live buying power and equity;
- live positions and exposure;
- broker snapshot freshness;
- live loss and drawdown controls.

`OrderSizingService` and `RiskEngine` operate on the interface. They may apply additional live-only restrictions, but paper mode must never be blocked by unrelated live holdings or live buying power.

Decision statuses distinguish at least:

- `hold`;
- `blocked_by_evidence`;
- `blocked_by_strategy`;
- `blocked_by_portfolio_risk`;
- `blocked_by_policy`;
- `awaiting_human_approval`;
- `approved`;
- `submitted` / execution terminal states.

## Evaluation Protocol

### Data Segmentation

The most recent 12 months are a locked final holdout. Candidate code, parameters, and search results cannot read this window during development.

Earlier history is evaluated using nested anchored walk-forward folds:

1. An inner training window fits any calibration and evaluates the preregistered parameter search space.
2. An inner validation window selects parameters and resolves ties.
3. An embargo separates training, validation, and test boundaries.
4. An outer test window measures untouched out-of-sample behavior.
5. The window advances without allowing future observations to modify earlier decisions.

Outer test windows must be non-overlapping when their returns are linked into the primary compounded out-of-sample result.

### Optimization Objective

Primary objective:

- maximize geometrically linked, net out-of-sample return across outer test windows.

Hard constraints:

- maximum out-of-sample drawdown must be no greater than 15%;
- stressed-cost return must be positive;
- the candidate must trade at least three assets;
- no single asset may supply more than 50% of positive P&L;
- minimum evidence counts must be satisfied without encouraging unnecessary churn;
- all folds must complete with identical strategy and manifest semantics;
- point-in-time, next-bar, and version-parity checks must pass.

Tie breakers, in order:

1. higher stressed-cost return;
2. lower maximum drawdown;
3. greater parameter-neighborhood stability;
4. lower turnover and cost drag.

The parameter space, objective, constraints, random seed, and maximum search budget are frozen before a run. The initial search should remain deliberately bounded rather than exhaustively mining combinations.

### Robustness Tests

A candidate is disqualified when:

- small neighboring parameter changes cause performance collapse;
- performance depends on one fold or one regime;
- one asset dominates profits beyond the concentration gate;
- results disappear under realistic cost stress;
- closed trades are too few to support the conclusion;
- append-future tests change historical signals;
- forced fold-end valuation materially contradicts closed-trade metrics;
- the live evaluation fixture differs from replay at the same logical bar and portfolio state.

### Holdout Rules

The holdout may be opened only for a frozen finalist. Opening it writes an immutable audit event containing candidate hash, manifest hash, code version, operator, and timestamp.

Once opened:

- its result may decide promotion or rejection;
- it cannot be used to alter that candidate;
- a failed candidate is archived;
- future iterations use development evidence and newly arriving forward data, not parameter tuning against the revealed holdout.

### Forward Paper Gate

A holdout-passing candidate enters a pinned paper session. Paper evidence is valid only when:

- strategy and universe versions are non-null and immutable;
- the Python canonical evaluator is used;
- paper sizing and risk use the active paper context;
- evidence, evaluation, decision, order, fill, ledger, and attribution hashes reconcile;
- sufficient elapsed time and trade evidence exist across more than one regime.

Live trading remains outside this design's automatic promotion path.

## Backtest and Simulator Corrections

The research implementation must correct the following before candidate comparison:

- use validation windows for parameter selection;
- evaluate the locked holdout as an explicit, audited stage;
- make prediction and holding horizons consistent with adaptive swing behavior;
- apply cross-sectional ranking and portfolio allocation, not independent asset thresholds;
- emit signals on state transitions instead of generating repeated ENTER requests each bar;
- mark every open position to current prices throughout replay;
- value and report open positions at each fold end without pretending they are closed trades;
- record both signal count and executed trade count;
- calculate normal and stressed costs consistently;
- keep fold-level capital independent for fold statistics while linking non-overlapping OOS returns for the primary objective;
- use canonical source, interval, finality, and quality rules when loading candles;
- prevent malformed archived revisions from re-entering the replay dataset;
- persist benchmark curves and relative performance instead of returning an empty benchmark payload.

## Persistence Model

Existing immutable models remain the foundation.

### `strategy_versions`

`definition_json` gains a versioned schema containing candidate family, parameters, rules, portfolio construction, costs, objective, and provenance. Research versions are immutable. Promotion creates append-only status/audit records rather than mutating definitions.

### `strategy_experiments`

A new experiment record groups one preregistered research round:

- objective and hard constraints;
- candidate families and bounded search spaces;
- development and holdout boundaries;
- fold specification and embargo;
- cost scenarios and random seeds;
- status, selected finalist, and failure reason;
- hashes for code, inputs, and experiment definition.

### `backtest_runs` and metrics

Backtest runs link to an experiment and declare an evaluation stage:

- `development`;
- `outer_oos`;
- `holdout`;
- `stress`.

Fold, regime, asset, holding-period, cost, and parameter-neighborhood metrics are stored in queryable metric groups. Large equity, trade, fill, and diagnostic payloads remain attached through immutable result/manifests.

### Holdout Access Audit

An append-only holdout access record stores the exact experiment, strategy version, manifest, engine version, code hash, actor, and open time. Database constraints prevent update or deletion.

### Decision Trace

Every `AssetEvaluation` stores or links to:

- strategy family and version;
- portfolio state and rank;
- rule checklist;
- factor values, weights, and contributions;
- expected gross edge, expected costs, and net edge;
- primary explanation and contributing reason codes;
- counterfactual conditions;
- evidence and parameter hashes;
- actionability and suppression reason.

HOLD evaluations receive the same trace quality as ENTER and EXIT evaluations.

### Position Thesis

Each paper position links to its entry evaluation and stores the original thesis, invalidation rules, initial stop context, expected holding range, and strategy version. Subsequent evaluations can compare current conditions with this stored thesis.

## UI and API Design

### Strategies: Decision-First Inspector

The default Strategies screen opens with the latest decision inspector selected during visual review.

#### Layer 1: Immediate Answer

- asset, logical bar, and action;
- plain-language explanation;
- evidence eligibility and actionability as separate states;
- strategy version and candidate family;
- current position/cash impact;
- explicit `Not measured` when strategy performance evidence does not exist.

#### Layer 2: Decision Mechanics

- entry/exit checklist with passed and failed rules;
- rank, score, calibrated probability, expected edge, and estimated cost;
- regime and portfolio capacity;
- evidence, strategy, risk, and policy blockers shown separately;
- nearest realistic counterfactual.

#### Layer 3: Full Audit

- factors and contributions;
- immutable thresholds and parameters;
- candle, quote, and availability timestamps;
- original entry thesis versus current evidence;
- sizing, risk, policy, execution, and accounting trail;
- links to cycle, engine job, manifest, strategy version, decision, and order.

### Research Lab

The deeper Research Lab uses the approved research cockpit concept:

- champion/challenger comparison;
- outer fold returns and drawdowns;
- normal and stressed equity curves;
- parameter-neighborhood stability;
- per-asset and per-regime attribution;
- trade and exit-reason distributions;
- holding-period distribution;
- gross return, fees, spread, slippage, and net return;
- equal-weight universe, BTC, and cash benchmarks;
- holdout state (`locked`, `opened`, `passed`, `failed`);
- archived candidates and explicit rejection reasons.

### Assets Performance Labels

The Assets screen shows three distinct columns:

1. Strategy return.
2. Buy-and-hold benchmark return.
3. Relative performance.

When no strategy replay exists, strategy and relative return show `Not measured`; they never show zero. Benchmark values are labeled as market outcomes and cannot be styled or described as strategy losses.

### APIs

Read APIs return stable transparency projections rather than asking the browser to infer meaning from raw JSON:

- latest decision inspector by asset and cycle;
- candidate and experiment summaries;
- fold and robustness metrics;
- benchmark and relative performance;
- holdout audit state;
- paper portfolio context and blockers;
- entry thesis/current thesis comparison.

No UI endpoint recalculates historical strategy logic.

## Error Handling and Fail-Closed Behavior

- Missing strategy, universe, manifest, or portfolio-context versions suppress action.
- Partial universe output persists evaluations but suppresses every trade decision.
- A failed fold makes the candidate result incomplete and non-promotable.
- A holdout access without a frozen finalist fails and records an audit incident.
- Missing next-bar prices reject fills without fallback prices.
- Cost-model or engine-version mismatch blocks comparison and promotion.
- Paper context absence blocks paper action with a specific remediation message.
- Unsupported decision-trace schema is displayed as unavailable, not guessed by the UI.
- Runtime and research errors remain visible independently from strategy underperformance.

## Testing Strategy

### Causality and Evidence

- appending future candles cannot change past factors, ranks, signals, or portfolio states;
- only canonical, complete, point-in-time evidence is replayed;
- malformed archived revisions cannot become eligible evidence;
- embargo and holdout boundaries are enforced.

### Strategy and Portfolio

- live and replay evaluation fixtures match exactly for the same version, bar, and portfolio state;
- state transitions, confirmation, hysteresis, cooldown, and anti-chasing rules are deterministic;
- long/cash allocation respects position, gross, asset, and correlation limits;
- adaptive exits use the stored entry thesis;
- fold-end valuation includes open positions correctly;
- next-hour fills and costs are deterministic under a fixed seed.

### Optimization

- validation, outer test, and holdout roles cannot be interchanged;
- parameter search cannot read outer or holdout results;
- objective, constraints, tie breakers, and budget are immutable during an experiment;
- parameter-neighborhood and cost-stress disqualifications are tested;
- holdout opening is single-use and append-only.

### Paper Context

- a funded virtual session can size and risk-check trades with zero live buying power;
- live positions do not consume virtual paper position capacity;
- paper positions, cash, drawdown, and correlation do constrain paper trades;
- live mode continues to use fresh live broker context.

### Transparency UI

- benchmark losses cannot be labeled as strategy losses;
- unavailable strategy returns render `Not measured`;
- every action and blocker has a decision trace;
- HOLDs show failed rules and counterfactuals;
- Research Lab metrics reconcile with immutable backtest results;
- accessibility and responsive layout tests cover all three transparency layers.

## Delivery Sequence

### Phase 1: Truth and Parity

- correct UI performance labels;
- introduce mode-specific portfolio context;
- distinguish decision statuses and blockers;
- establish canonical Python live/replay definition contract;
- correct simulator valuation, ranking, state transitions, costs, and benchmarks.

### Phase 2: Experiment System

- add immutable experiment and holdout audit persistence;
- implement candidate families and bounded nested walk-forward selection;
- add robustness, stress, attribution, and promotion gates;
- run development experiments without opening the holdout.

### Phase 3: Transparency UI

- build the decision-first Strategies inspector;
- build the Research Lab candidate cockpit;
- add thesis comparison, counterfactuals, fold charts, and benchmark comparisons.

### Phase 4: Final Evidence

- freeze the finalist;
- open and evaluate the locked 12-month holdout once;
- archive failures without tuning against the holdout;
- start a strictly pinned paper session for any passing finalist.

## Acceptance Criteria

The design is complete when:

1. The UI no longer presents buy-and-hold losses as strategy performance.
2. A virtual paper session can execute valid trades using its own capital and risk state.
3. Backtest and live paper evaluations share the same immutable Python strategy definition and evaluator.
4. At least the three challenger families and defensive cash baseline run through nested walk-forward development without holdout access.
5. Candidate selection maximizes net compounded OOS return while enforcing the 15% drawdown ceiling and robustness gates.
6. Holdout access is technically restricted, single-use, and audited.
7. Every evaluation has a layered decision trace suitable for the selected UI.
8. Research and UI metrics reconcile with immutable versions, manifests, fills, and costs.
9. All causality, parity, portfolio, paper-context, holdout, and UI-labeling tests pass.
10. No step enables live trading automatically.
