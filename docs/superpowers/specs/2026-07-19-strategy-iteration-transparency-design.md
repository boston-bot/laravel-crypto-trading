# Strategy Iteration and Transparency Design

Date: 2026-07-19  
Project: `laravel-crypto-trading`  
Status: Approved design; independent specification review passed

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

An experiment candidate begins as an immutable `CandidateSpecification`. It defines the family, feature set, deterministic calibration procedure, bounded parameter search space, and every rule used to select parameters. A concrete `StrategyVersion` always contains one fully resolved parameter set; it never means “whatever parameters the optimizer chooses later.” Its `definition_json` fully specifies:

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

During nested walk-forward evaluation, each outer fold creates an immutable child `StrategyVersion` using only that fold's inner training and validation data. The linked outer OOS result therefore supports the candidate specification and its calibration procedure, not any one fold's parameter values. After development, that same preregistered calibration procedure runs once over the permitted development history to create a single frozen deployable `StrategyVersion`. Its exact hash is recorded before holdout access. The locked holdout evaluates that exact version without recalibration. Forward paper also uses that exact version.

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

It returns an evaluation for every asset plus a versioned `PortfolioTarget`, decision trace, and proposed order intents. The `PortfolioTarget` is the sole strategy-sizing authority. A versioned execution-policy manifest deterministically converts targets into executable order intents using the supplied portfolio and market context. Laravel may validate or veto an intent through risk and policy controls, but it may not silently resize or reinterpret it. A veto and its reason are persisted. Any additional live-only veto does not invalidate research parity, but evidence produced under a materially different allocation or execution policy is not comparable promotion evidence.

The order-intent contract contains strategy, universe, portfolio-context, evidence, and execution-policy hashes; side; target and delta weights; unrounded and normalized base quantity; reference price; Coinbase product precision and minimums; earliest execution time; time in force; expected fees, spread, and slippage; and idempotency key. Replay and paper use the same allocation, precision, minimum-notional, cost, and fill-state rules. An intent becomes fill-eligible only on the first executable market observation strictly after the decision cutoff. Replay uses the first canonical one-hour observation after that cutoff; paper uses the first fresh executable quote after submission. Both record the observation and apply the same versioned spread, slippage, fee, partial-fill, rejection, and mark-to-market rules.

The legacy PHP adapter may remain available for diagnostics, but its output cannot be used as strategy-promotion evidence.

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

Ordinary holdings last at least 2 days and no more than 21 days. Protective stops, regime failure, data invalidation, delisting, or thesis invalidation may exit earlier. No minimum holding period can override a protective exit. Every surviving position exits or renews through a separately versioned and tested thesis no later than day 21; the initial candidate families do not permit renewal.

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

`OrderSizingService` validates the canonical `PortfolioTarget` and normalized order intent against the selected `PortfolioContext`; it does not independently resize either one. `RiskEngine` may veto an intent and may apply additional live-only restrictions, but paper mode must never be blocked by unrelated live holdings or live buying power.

All paper accounting is scoped by `paper_session_id`. Intent reservation, order creation, simulated fills, ledger posting, cash release, and position mutation use one idempotency lineage and database transactions with row-level locking or an equivalent serializable guarantee. Reservation keys are unique per session and intent. Cash and position updates are atomic with ledger entries, retries are idempotent, and concurrent cycles cannot reserve or spend the same cash twice. Paper tables and services cannot reference a live broker-account ID as an accounting source; live snapshots may be retained only as explicitly labeled market or diagnostic evidence.

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

The experiment preregistration anchors the most recent 12 complete months as an immutable half-open `[holdout_start, holdout_end)` interval. Candidate code, parameters, search results, general query APIs, exports, and UI projections cannot read rows in that interval during development. Authorization is granted only to the holdout evaluator after a single frozen candidate hash is recorded.

Earlier history is evaluated using nested anchored walk-forward folds:

1. An inner training window fits any calibration and evaluates the preregistered parameter search space.
2. An inner validation window selects parameters and resolves ties.
3. An embargo separates training, validation, and test boundaries.
4. An outer test window measures untouched out-of-sample behavior.
5. The window advances without allowing future observations to modify earlier decisions.

Outer test windows must be non-overlapping when their returns are linked into the primary compounded out-of-sample result.

### Point-in-Time Universe

Universe membership is time-versioned with `effective_from` and `effective_to`, Coinbase product ID, quote currency, spot-trading status, listing time, delisting time, precision, minimums, and the evidence timestamp that established each fact. Replay may include an asset at a bar only if the product was known, listed, eligible, and tradable at that time. Current market-cap rankings, current listings, or a later universe version cannot rewrite earlier membership.

Selection criteria use only information available at the logical bar. Newly listed assets cannot receive prelisting candles or synthetic history. A delisted or halted asset becomes ineligible for new entries immediately; an open position follows the preregistered forced-exit policy at the first executable observation. If no reliable liquidation observation exists, the fold is marked incomplete rather than valuing the asset optimistically. Universe changes create a new immutable universe version and do not mutate completed experiments.

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

Initial minimum evidence gates, frozen in the experiment manifest before execution, are:

- at least four non-overlapping outer test folds;
- at least 30 completed round trips across outer OOS windows;
- trades in at least three assets, with at least five completed round trips in each of three assets;
- at least two preregistered market-regime classes represented by at least 20 final common four-hour bars each.

These gates measure whether a conclusion is supportable; they do not force entries. A candidate that remains in cash and misses a gate is `inconclusive`, not retroactively tuned to trade more often.

### Drawdown and Equity Definition

All return and drawdown gates use net marked-to-market equity sampled at every final common four-hour bar. Equity includes cash, reserved cash, open positions valued under the versioned valuation policy, realized P&L, fees, spread, slippage, and forced-exit adjustments. Open positions remain open for trade statistics but are fully reflected in the equity path and final NAV.

Each outer fold begins at normalized equity 1.0 and has independent positions and cash. Non-overlapping outer test return paths are linked geometrically in chronological order by scaling each next fold's normalized path to the prior fold's ending equity; the reset does not erase losses. Gaps between folds are flat cash unless the manifest specifies an observable cash return. Primary development OOS maximum drawdown is calculated from the resulting linked high-water-mark path, with fold-level drawdowns reported separately.

The 15% ceiling applies independently to the linked development OOS path, the locked holdout path, and the forward paper path. Exceeding it fails the candidate at that stage; in paper it also suppresses new entries and requests orderly risk exits under the pinned policy.

Tie breakers, in order:

1. higher stressed-cost return;
2. lower maximum drawdown;
3. greater parameter-neighborhood stability;
4. lower turnover and cost drag.

The parameter space, objective, constraints, random seed, and maximum search budget are frozen before a run. The initial search should remain deliberately bounded rather than exhaustively mining combinations.

### Robustness Tests

Every robustness predicate, threshold, parameter-neighborhood definition, cost multiplier, regime classifier, and reconciliation tolerance is numeric and frozen in the experiment manifest. The initial policy defines a parameter neighbor as a candidate one adjacent search-grid step away on exactly one numeric or ordinal parameter, or one preregistered alternative value away on one categorical parameter, with all other values held constant. All valid immediate neighbors are evaluated; a parameter set with fewer than four valid neighbors is ineligible.

A candidate is disqualified when any of these initial predicates fails:

- fewer than 80% of immediate parameter neighbors have positive net OOS return and maximum drawdown no greater than 15%;
- median neighbor net OOS return is less than 50% of the selected parameter set's net OOS return;
- removing the best-returning outer fold makes linked net OOS return non-positive, or one fold supplies more than 50% of positive OOS P&L;
- one asset supplies more than 50% of positive OOS P&L;
- any preregistered regime has drawdown above 15%, worst-regime net return is below -5%, or one regime supplies more than 70% of positive OOS P&L;
- stressed-cost linked OOS return is non-positive;
- the numeric evidence-count gates are missed;
- appending future observations changes any earlier factor, rank, signal, intent, fill, or portfolio state;
- the NAV reconciliation residual after cash, realized P&L, unrealized P&L, reservations, and all costs exceeds one basis point at any fold end;
- the live evaluation fixture differs from replay at the same logical bar, strategy version, execution policy, universe, and portfolio state.

Changing one of these defaults requires a new experiment manifest before any run begins; it cannot amend a running experiment.

### Holdout Rules

The anchored holdout interval may be opened only for one frozen finalist hash. The database enforces one authorized opening for the tuple of holdout interval, experiment, manifest, and candidate hash. Retries are allowed only when they are idempotent continuations with identical hashes; a different candidate, manifest, code version, or experiment is denied. Once any row in an interval is revealed, that exact interval and every overlapping interval are permanently ineligible as a locked holdout for later experiments.

Opening writes an immutable audit event containing interval, candidate hash, manifest hash, code version, operator, authorization, purpose, and timestamp. Storage access is mediated by a holdout-aware repository or database role; ordinary research APIs, exports, jobs, logs, and UI endpoints fail closed for unrevealed rows. A direct-access attempt records an audit incident.

The holdout starts with normalized NAV 1.0, 100% cash, no positions, no reservations, and every asset state set to `flat`. It does not carry trades, cooldowns, or pending confirmations across `holdout_start`. The evaluator may warm deterministic features with canonical observations strictly before `holdout_start`, limited to the maximum lookback and confirmation bars declared in the frozen strategy definition. Warmup observations cannot contribute return, create orders, change frozen parameters, or expose a holdout row. Insufficient warmup fails the run as incomplete. The first actionable decision is at or after `holdout_start`, and the final NAV is marked at the last final common bar strictly before `holdout_end`.

Holdout pass gates are preregistered and cannot change after opening. A pass requires:

- a complete, reconciled run with no evidence, parity, valuation, or manifest failure;
- positive net marked-to-market return;
- positive return under the preregistered stressed-cost scenario;
- maximum drawdown no greater than 15% in both normal and stressed paths;
- at least 10 completed round trips across at least two assets;
- every preregistered concentration, execution, and data-quality constraint to pass.

A missing evidence-count gate is `inconclusive` and is treated as non-promotable. Any other failed gate rejects and archives the candidate. Neither result authorizes tuning against the revealed interval.

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

The initial paper evidence minimum is 90 elapsed calendar days, 15 completed round trips across at least three assets, and at least 20 final common four-hour bars in each of two preregistered regime classes. Until all thresholds are met, the candidate remains `collecting_evidence`; it is not failed merely because the market has not produced the required regimes. A paper drawdown above 15%, manifest mismatch, or reconciliation failure fails the paper gate immediately and suppresses new entries; exactly 15.00% remains within the ceiling.

Live trading remains outside this design's automatic promotion path.

## Backtest and Simulator Corrections

The research implementation must correct the following before candidate comparison:

- use validation windows for parameter selection;
- evaluate the locked holdout as an explicit, audited stage;
- make prediction and holding horizons consistent with adaptive swing behavior;
- apply cross-sectional ranking and portfolio allocation, not independent asset thresholds;
- replay point-in-time universe membership, listing, halting, and delisting state;
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

Each experiment has immutable candidate-specification records. Fold child `StrategyVersion` rows link to the candidate specification, fold, calibration input boundary, and selected-parameter evidence. A final deployable version links to the same specification and records the development-only calibration boundary used to freeze it.

### `backtest_runs` and metrics

Backtest runs link to an experiment and declare an evaluation stage:

- `development`;
- `outer_oos`;
- `holdout`;
- `stress`.

Fold, regime, asset, holding-period, cost, and parameter-neighborhood metrics are stored in queryable metric groups. Large equity, trade, fill, and diagnostic payloads remain attached through immutable result/manifests.

Holdout evaluation has a durable lifecycle of `locked`, `authorized`, `running`, and exactly one terminal outcome: `passed`, `failed`, or `inconclusive`. `Inconclusive` is non-promotable and immutable for that revealed interval; it is not an invitation to change the candidate or rerun with different gates.

### Holdout Access Audit

An append-only holdout access record stores the exact interval, experiment, strategy version, manifest, engine version, code hash, actor, authorization, purpose, and open time. Exclusion and uniqueness constraints prevent overlapping holdout reuse, multiple candidate openings, update, or deletion while permitting idempotent retries for the exact authorized evaluation.

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
- holdout state (`locked`, `authorized`, `running`, `passed`, `failed`, `inconclusive`);
- archived candidates and explicit rejection reasons.

### Performance Semantics and Assets Labels

Every performance projection declares `period_start`, `as_of`, bar interval, completeness, freshness, strategy/universe versions, valuation policy, cost policy, and benchmark definition. The default 90-day view ends at the latest final common four-hour bar and begins 90 calendar days earlier. An incomplete interval displays `Incomplete` and does not silently annualize or substitute zero.

Portfolio strategy return is the net time-weighted change in marked-to-market NAV over the selected period, including realized and unrealized P&L and all modeled costs. Portfolio benchmarks are independently investable series over the same timestamps: equal-weight point-in-time eligible universe, BTC buy-and-hold, and cash. Relative performance is the arithmetic percentage-point difference `strategy_return - selected_benchmark_return`; the API also exposes the two source returns so the UI never reconstructs it ambiguously.

A versioned benchmark policy defines construction. The initial equal-weight universe benchmark starts at normalized NAV 1.0, rebalances at the first final common four-hour bar of each UTC calendar month, and assigns equal target weight to every point-in-time eligible asset with a valid executable price. New listings enter at the next scheduled rebalance. Halts and delistings follow the same forced-exit and incomplete-data policy as strategy replay; proceeds remain cash until the next rebalance. It is long-only, unlevered, and reports both gross price return and net return under the same precision, spread, slippage, and fee policy used by the strategy. BTC buy-and-hold makes one costed purchase at period start and remains invested; the cash benchmark returns 0% unless the manifest pins an observable cash-yield series.

The Assets table does not label a rotating portfolio result as an asset-level “strategy return.” It shows:

1. Net strategy P&L contribution for the asset, divided by portfolio NAV at period start.
2. Strategy held-period linked return for that asset, labeled as applying only while held.
3. Full-period asset buy-and-hold return.

Asset attribution follows a versioned policy. Net contribution is the sum of that asset's realized P&L, unrealized P&L change, and allocated fees, spread, and slippage divided by portfolio NAV at period start. When an asset has multiple holding episodes, its held-period linked return geometrically links the net return of each episode in chronological order and excludes intervening cash periods; the UI labels the episode count and never compares this metric directly with a full-period benchmark. Full-period asset buy-and-hold is the raw close-to-close market price return over the declared period and is explicitly labeled as excluding trading costs.

Portfolio strategy and relative returns appear in the portfolio summary, not repeated per asset. When no eligible strategy replay or paper ledger covers the interval, strategy fields show `Not measured`; they never show zero. Benchmark values are labeled as market outcomes and cannot be styled or described as strategy losses.

### APIs

Read APIs return stable transparency projections rather than asking the browser to infer meaning from raw JSON:

- latest decision inspector by asset and cycle;
- candidate and experiment summaries;
- fold and robustness metrics;
- benchmark and relative performance;
- point-in-time universe membership and eligibility state;
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
- order-intent normalization, minimums, precision, fills, rejections, and valuation match between replay and paper fixtures;
- point-in-time membership prevents prelisting history and current-universe survivorship;

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
- concurrent paper decisions cannot double-reserve cash, duplicate fills, or cross session/accounting boundaries;

### Transparency UI

- benchmark losses cannot be labeled as strategy losses;
- unavailable strategy returns render `Not measured`;
- portfolio return, asset contribution, held-period linked return, benchmark return, and relative return reconcile to their documented period and formula;
- repeated asset holding episodes and point-in-time benchmark membership reproduce identically from the pinned attribution and benchmark policies;
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
6. Holdout access is technically restricted to one frozen hash, single-use across overlapping intervals, idempotent only for exact retries, and audited.
7. Every evaluation has a layered decision trace suitable for the selected UI.
8. Research and UI metrics reconcile with immutable versions, manifests, fills, and costs.
9. All causality, parity, portfolio, paper-context, holdout, and UI-labeling tests pass.
10. No step enables live trading automatically.
11. Drawdown, evidence-count, duration, order-intent, execution, universe-membership, and performance-reporting semantics are versioned and mechanically testable.
