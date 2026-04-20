## Large-Cap Crypto Swing Strategy Design

Date: 2026-04-13
Project: `laravel-crypto-trading`
Scope: `v1` expert-grade swing trading system for Robinhood-supported large-cap crypto assets

### Purpose

Design a production-grade `v1` trading intelligence layer for this codebase that targets multi-day to week-over-week swing trades, prioritizes risk-adjusted returns, and remains fully auditable.

This design assumes the existing broker connectivity, policy workflow, paper/live execution paths, and API visibility endpoints remain in place. The main work is replacing the placeholder signal layer with a real research, feature, ranking, and portfolio construction system.

Primary persistence requirement:
- PostgreSQL is the system of record for application state, historical market data, feature snapshots, paper trading state, and research outputs.

### Current Codebase Assessment

The current repository is a strong execution scaffold but not yet an intelligent trading system.

Observed strengths:
- Robinhood connectivity is working.
- Account, position, order, and asset sync paths exist.
- Trade decisions, policy checks, and audit records are persisted.
- Paper mode and approval-gated live mode are implemented.
- Safety controls exist for allowlists, kill switch, cooldowns, and drawdown checks.

Observed gaps:
- `MarketRankService` and `TaSignalService` generate synthetic outputs, not market-derived features.
- `SignalAggregator` turns those placeholders into buy/sell decisions.
- `PaperBrokerAdapter` uses invented reference prices instead of historical or live market snapshots.
- `OrderSizingService` uses simple fixed-fraction logic instead of volatility-aware portfolio sizing.
- There is no historical candle store, feature store, regime classifier, portfolio allocator, or walk-forward research loop.
- the current local default database posture is not aligned with the storage and query patterns required for historical trading data
- Existing tests validate plumbing and safety, not alpha quality.

Conclusion:
`v1` should not tune the current signal logic. It should replace the signal, ranking, and sizing layers while preserving the existing broker and risk shell.

### Strategic Objective

Build a regime-aware, deterministic, probabilistically calibrated swing trading system for Robinhood-supported large-cap crypto assets.

Primary optimization target:
- best risk-adjusted returns

Operational constraints:
- multi-day to week-over-week holding periods
- no intraday dependency
- no black-box model-led order placement
- drawdown tolerance centered around 10% to 12%
- portfolio remains inspectable and explainable at every decision point
- all durable trading and research data must live in PostgreSQL

### Trading Universe

Initial universe:
- BTC
- ETH
- SOL
- AVAX
- LINK
- LTC
- DOGE
- other Robinhood-supported large-cap names if they pass liquidity and history filters

Universe inclusion rules:
- must be tradable on Robinhood
- must have sufficient price history for all required features
- must meet minimum liquidity and order-size viability requirements
- must not show unstable tradability or execution-quality problems

Universe exclusion rules:
- assets with insufficient data history
- assets with poor execution economics for the account size
- assets with unstable support status or frequent broker-side constraints

### High-Level Architecture

#### 1. Market Data Layer

Responsibilities:
- ingest daily and 4-hour OHLCV history
- store best bid/ask and execution-quality snapshots when available
- persist benchmark context for BTC and ETH
- provide reproducible, timestamped market inputs for both research and live runs

Core outputs:
- candle history by asset and timeframe
- quote snapshots
- realized volatility series
- volume and turnover regime metrics

Persistence requirement:
- market data must be stored in PostgreSQL in a form optimized for time-series queries, replayability, and feature generation

#### 2. Feature Layer

Responsibilities:
- compute deterministic factors from stored market data
- persist point-in-time feature snapshots for replayability
- ensure every decision can be reconstructed from a historical feature state

Core feature groups:
- trend persistence
- multi-horizon momentum
- relative strength
- pullback quality
- volatility quality
- participation and liquidity quality
- execution-quality penalty

#### 3. Regime Layer

Responsibilities:
- classify the overall market into `risk_on`, `neutral`, or `risk_off`
- gate whether new long exposure is allowed, throttled, or blocked
- drive portfolio gross exposure and position size scaling

Primary regime drivers:
- BTC long-term trend state
- BTC medium-term momentum state
- ETH confirmation
- market breadth across the eligible universe
- volatility shock detection

#### 4. Scoring Layer

Responsibilities:
- rank all eligible assets on each evaluation cycle
- convert deterministic factor states into a composite score
- map score tiers into calibrated win-probability / expectancy buckets

Core outputs:
- composite score
- score tier
- probability tier
- factor attribution snapshot
- entry eligibility flag
- exit deterioration flag

#### 5. Portfolio Construction Layer

Responsibilities:
- convert ranked candidates into a small, coherent portfolio
- avoid concentration in highly correlated bets
- throttle exposure when regime or drawdown worsens

Portfolio principles:
- target 2 to 4 concurrent positions
- no assumption that correlated assets are independent
- top-ranked candidates only
- lower size in neutral regimes
- automatic size reduction during drawdowns

#### 6. Execution And Risk Layer

Responsibilities:
- reuse current broker/policy workflow
- strengthen risk sizing and exit logic
- ensure every submitted order still passes deterministic policy and approval controls

#### 7. Paper Trading And Attribution Layer

Responsibilities:
- simulate the full strategy against current as-of market conditions without sending live broker orders
- preserve point-in-time realism so paper results can be trusted over a short proving period
- model realistic fills, holding PnL, exits, and attribution
- provide a promotion gate before any live-capital expansion

Design principles:
- paper trading must use current market data and broker/account state as of the decision timestamp
- no look-ahead data may be used anywhere in decisioning, fills, or portfolio valuation
- paper mode should mirror live logic as closely as possible except for broker submission
- paper trading is not a unit test substitute; it is a production-like simulation environment

Persistence requirement:
- all paper orders, paper positions, valuation snapshots, and attribution records must be written to PostgreSQL

### PostgreSQL Architecture

PostgreSQL should be adopted now, before the historical data model expands.

#### Why PostgreSQL

PostgreSQL is the correct database for this project because it supports:
- relational integrity for broker, order, decision, and policy data
- efficient indexing for time-series market data
- JSONB for flexible signal and attribution payloads
- windowing and aggregation patterns needed for research and reporting
- a clean migration path to managed Postgres later without redesigning the schema

SQLite is acceptable for lightweight tests, but it should not be the primary runtime datastore for this system.

#### Database Role

PostgreSQL should hold:
- application state
- broker sync state
- market candles and quote snapshots
- feature and regime snapshots
- paper trading state
- research and backtest outputs
- reporting and attribution records

#### Schema Design Direction

Recommended logical separation:
- `public` for core Laravel app tables if simplicity is preferred
- or separate schemas such as `app`, `market_data`, and `research` if stronger isolation is desired

Even if a single schema is used initially, tables should be designed as if market-data and research workloads will grow.

#### Historical Data Requirements

Historical data tables must support:
- point-in-time replay
- efficient range scans by asset and timeframe
- idempotent ingestion
- late-arriving updates or corrections
- feature generation without future leakage

Core indexing strategy should be based on:
- `(asset_id, timeframe, candle_open_time)`
- `(asset_id, snapshot_time)`
- `(strategy_name, run_time)`
- `(broker_account_id, snapshot_time)`

#### Migration Requirements

Migrations should be designed for PostgreSQL-native behavior:
- use `jsonb` where flexible payloads are required
- use precise numeric columns for price, size, and PnL values
- add unique constraints for idempotent ingestion
- add timestamp indexes for time-series retrieval
- avoid schema choices that depend on SQLite quirks

#### Local Development Requirement

The local development environment should point Laravel at the running local PostgreSQL instance as soon as implementation starts.

That includes:
- creating the application database
- moving runtime migrations to PostgreSQL
- keeping tests lightweight unless or until a dedicated PostgreSQL test setup is needed

### `v1` Signal Model

#### Regime Model

State definitions:
- `risk_on`: BTC above long-term trend filter, momentum constructive, breadth supportive
- `neutral`: mixed evidence, selective exposure only
- `risk_off`: trend failure or volatility shock, no new longs and reduce weak holdings

Regime impact:
- `risk_on`: allow full-size candidates
- `neutral`: allow only highest-conviction names with reduced sizing
- `risk_off`: no new entries, manage exits and de-risk

#### Factor Set

Trend:
- price vs 50-day, 100-day, and 200-day moving averages
- moving average slope confirmation

Momentum:
- 20-day return
- 60-day return
- momentum persistence and acceleration/deceleration

Relative strength:
- asset performance vs BTC
- asset performance vs universe median

Pullback quality:
- controlled retracement in an uptrend
- avoid vertical extensions and low-quality chase entries

Volatility quality:
- ATR relative to price
- realized volatility regime
- volatility compression or expansion state

Participation:
- volume confirmation
- turnover consistency

Execution quality:
- spread/slippage viability
- order-size feasibility for the current account

#### Composite Score

Initial weighting recommendation:
- 30% trend
- 25% relative strength
- 20% momentum
- 15% volatility quality
- 10% pullback quality

Calibration:
- use backtested historical outcomes to map composite score ranges into probability tiers
- keep the mapping interpretable and monotonic
- do not allow the probability layer to replace deterministic gating

#### Entry Rules

All must pass:
- regime is `risk_on` or strong `neutral`
- asset is in top-ranked slice of the universe
- trend filters are positive
- medium-term momentum is positive
- relative strength vs BTC is positive
- extension is not excessive
- volatility is acceptable
- execution quality is acceptable
- portfolio concentration and correlation limits remain within budget

#### Exit Rules

Exit triggers:
- global regime transition to `risk_off`
- trend breakdown
- relative strength deterioration
- ATR-based stop breach
- trailing stop after sufficient favorable move
- time-based stale trade exit
- execution or liquidity degradation severe enough to invalidate the position

### Portfolio Construction And Risk

#### Position Sizing

Replace fixed notional sizing with volatility-scaled sizing.

Sizing inputs:
- account equity
- regime state
- asset ATR / realized volatility
- probability tier
- score tier
- current drawdown state
- portfolio heat

Sizing behavior:
- highest-conviction positions get the largest size only in `risk_on`
- lower confidence or neutral-regime positions get materially reduced size
- during drawdown, all new entries are automatically smaller

#### Portfolio Risk Controls

Required additions:
- ATR-based stop distance
- trailing stop framework
- per-asset volatility cap
- correlation-aware exposure cap
- total portfolio heat cap
- regime brake on gross exposure
- consecutive-loss brake
- fill-quality degradation brake

Target portfolio behavior:
- limited number of simultaneous positions
- correlated names treated as partially overlapping risk
- no aggressive pyramiding in `v1`

### Research And Validation System

This is mandatory. No serious deployment should happen without it.

### Robust Paper Trading Requirements

Paper trading must be treated as a first-class subsystem, not as a lightweight mock adapter.

#### Purpose

The goal of paper mode is to run the exact production decision engine against current live market conditions for a brief proving window, typically 2 to 6 weeks, without placing real trades.

This subsystem should answer:
- would the current strategy have traded?
- at what simulated fill quality?
- what would the open and realized PnL look like under realistic assumptions?
- how did realized paper outcomes compare to expected signal quality?

#### Data Fidelity Requirements

Paper trading inputs must be point-in-time accurate:
- account snapshot as of decision time
- asset eligibility and broker constraints as of decision time
- latest available candles as of decision time
- latest available quote or best bid/ask context as of decision time
- no future candles, future quotes, or end-of-day hindsight for intracycle decisions

#### Order Simulation Requirements

Paper orders should support:
- the same trade decision records as live mode
- simulated broker orders with lifecycle states
- realistic reference prices from current quotes or best available market data
- spread-aware buy and sell pricing
- slippage assumptions tied to volatility and order size
- optional partial fill logic if execution quality requires it
- cancellation or stale-order handling if an order would not realistically fill

Paper fills should not be derived from invented placeholder prices.

#### Position And PnL Requirements

Paper mode should maintain:
- open paper positions
- realized PnL
- unrealized PnL
- holding period
- max favorable excursion
- max adverse excursion
- expected-vs-realized trade attribution

Valuation should be refreshed using the latest current market snapshots so a short paper run reflects actual evolving market conditions.

#### Evaluation Requirements

The paper trading proving period should report:
- cumulative return
- Sharpe and Sortino
- max drawdown
- hit rate
- expectancy
- turnover
- average hold time
- regime-specific results
- slippage and spread drag
- ranking score vs realized outcome

Promotion rule:
- no strategy should move from paper mode toward more autonomous live deployment without surviving a defined paper window and showing stable behavior under realistic costs.

#### Historical Research Stack

Required components:
- historical market data store
- feature snapshot generation
- backtest engine integration
- parameter registry
- walk-forward evaluation workflow

Backtesting requirements:
- use realistic slippage and spread assumptions
- separate in-sample from out-of-sample
- reject strategies that only perform in one narrow market phase
- track per-regime performance
- align backtest assumptions with the live paper engine so research and paper results are comparable

Metrics to track:
- Sharpe
- Sortino
- max drawdown
- CAGR
- win rate
- expectancy
- average gain/loss
- turnover
- average hold time
- execution cost drag
- performance by asset
- performance by regime

Promotion criteria:
- stable out-of-sample performance
- acceptable drawdown
- no dependence on unrealistic fills
- durable edge after costs

### Data Model Additions

Recommended new persistence objects:
- `market_candles`
- `market_quotes`
- `asset_feature_snapshots`
- `market_regime_snapshots`
- `strategy_parameters`
- `backtest_runs`
- `backtest_run_metrics`
- `trade_attributions`
- `paper_positions`
- `paper_order_events`
- `paper_portfolio_snapshots`
- `execution_quality_snapshots`

PostgreSQL notes:
- these tables should be introduced with production-style indexes and uniqueness rules from day one
- market and research tables should be designed for large append-heavy workloads

Purpose:
- support reproducible research
- make live decisions inspectable
- connect realized performance to the exact signal state that produced it

### Service Layer Additions

Recommended new service modules:
- `Services/MarketData/CandleIngestionService`
- `Services/MarketData/QuoteSnapshotService`
- `Services/Strategy/FeatureEngine`
- `Services/Strategy/RegimeService`
- `Services/Strategy/CompositeScoringService`
- `Services/Strategy/UniverseSelectionService`
- `Services/Portfolio/PortfolioConstructionService`
- `Services/Portfolio/CorrelationService`
- `Services/Research/BacktestOrchestrator`
- `Services/Research/CalibrationService`
- `Services/PaperTrading/PaperExecutionEngine`
- `Services/PaperTrading/PaperPortfolioValuationService`
- `Services/PaperTrading/PaperAttributionService`

Refactors required:
- replace placeholder logic in `MarketRankService`
- replace placeholder logic in `TaSignalService`
- refactor `SignalAggregator` into orchestration over real feature services
- refactor `OrderSizingService` into volatility-aware portfolio sizing
- extend `RiskEngine` with portfolio and execution-quality controls
- replace `PaperBrokerAdapter` with a richer paper execution subsystem that uses current point-in-time market references

### Operational Workflow

Daily or scheduled cycle:
1. sync broker data
2. update market data
3. compute latest feature snapshots
4. classify regime
5. rank eligible assets
6. construct proposed portfolio changes
7. run risk and policy checks
8. create trade decisions with factor attribution
9. if paper mode, simulate fills against current as-of market references and update paper portfolio state
10. if live mode, require approval until confidence thresholds and run-history justify more autonomy
11. reconcile live orders or paper orders and update attribution records

### Implementation Phases

#### Phase 1: Data And Feature Foundation
- move runtime persistence to PostgreSQL
- create the local PostgreSQL database for the app
- create candle and quote tables
- build ingestion jobs
- build feature snapshot generation
- wire feature APIs for inspection

Definition of done:
- the app runs on PostgreSQL and can persist and inspect real market history and point-in-time features

#### Phase 2: Real Regime And Ranking Engine
- implement regime classifier
- implement deterministic factor computation
- implement composite scoring and ranking
- replace synthetic `MarketRankService` and `TaSignalService`

Definition of done:
- each evaluation cycle produces real factor-based rankings and explainable candidate scores

#### Phase 3: Portfolio Construction And Risk Upgrade
- add volatility-scaled sizing
- add portfolio heat and correlation constraints
- add ATR stop logic and drawdown brakes

Definition of done:
- trade decisions reflect portfolio-aware sizing and regime-aware exposure

#### Phase 4: Research And Calibration
- integrate the backtesting skill workflow into the repo
- run walk-forward tests
- calibrate score tiers to historical expectancy/probability

Definition of done:
- `v1` has defensible out-of-sample evidence and parameter discipline

#### Phase 5: Paper Trading With Attribution
- build a dedicated paper execution engine
- use current as-of quotes and candles for realistic fills and valuation
- track signal-to-fill attribution
- compare expected vs realized outcomes
- expose paper portfolio and paper performance through API endpoints

Definition of done:
- paper trading becomes decision-grade rather than plumbing-grade

#### Phase 6: Controlled Live Deployment
- keep human approval
- allow only the highest-conviction trades
- monitor fill quality, drawdown, and regime behavior closely

Definition of done:
- live execution is small, explainable, and constrained by the full upgraded risk framework

### Explicit Non-Goals For `v1`

- intraday trading
- ML-led autonomous order generation
- high-frequency execution logic
- broad altcoin speculation
- maximizing raw CAGR at the expense of stability

### Recommendation

Proceed with `v1` as a deterministic, regime-aware, probability-calibrated swing trading system over a liquid Robinhood large-cap universe.

Do not add black-box ML first.
Do not optimize the current placeholder factor services.
Do not treat research as optional.

The existing repository is suitable as the broker, audit, and control shell. The next build stage should focus on data, features, regime detection, scoring, portfolio construction, and walk-forward validation.
