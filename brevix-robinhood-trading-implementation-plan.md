# Brevix AI — Robinhood Trading Automation Implementation Plan

> **Purpose:** Build a Laravel-based trading application, deployed on the existing Brevix AI infrastructure, that can connect to a Robinhood account, place trades under strict guardrails, and evolve from a small-capital prototype into a more robust automated trading system.

> **Starting capital:** $100

> **Important constraint:** No strategy can guarantee positive returns. The goal of this plan is to maximize discipline, risk control, observability, and process quality so the system has a realistic chance to produce positive risk-adjusted results over time.

---

## 1. Executive Summary

This project should be built in **phases**, with the first release focused on **safe connectivity, order execution, logging, simulation, and rule enforcement** rather than trying to optimize returns immediately.

The safest near-term path is to target **Robinhood Crypto Trading API support first**, because Robinhood has an official public API for crypto trading, account access, and market data. Robinhood does **not** currently provide a comparable documented public retail API for automated stock trading, so designing Phase 1 around ordinary U.S. stock automation on Robinhood would introduce unnecessary fragility and policy risk. Robinhood’s official docs state the Crypto Trading API supports authenticated trading and market/account access, and Robinhood’s newsroom describes it as enabling automated crypto strategies. citeturn0search0turn0search24

The application should therefore begin with:

1. **Robinhood authentication and connectivity**
2. **Read-only portfolio/account synchronization**
3. **Paper-trading / simulation mode**
4. **Live-trading mode with very small size and strict controls**
5. **Strategy evaluation using the installed skills stack**
6. **Governance rules engine**
7. **Full audit trail and review workflow**

The system should be designed so that a future Brevix AI interface can control, review, and explain automated trading decisions.

---

## 2. What Robinhood Supports vs. What We Should Build

### 2.1 Supported integration path

Robinhood’s official **Crypto Trading API** supports programmatic access for crypto customers, including account data, market data, and order placement. Authentication uses API credentials and signed requests. Robinhood’s docs also note endpoint-level rate limiting. citeturn0search0turn1search0

### 2.2 What not to build first

Do **not** build Phase 1 around unofficial, reverse-engineered Robinhood stock-trading endpoints. Even if technically possible, they are not the official public interface and increase operational and account-risk exposure.

### 2.3 Correcting the “3 trades per week” assumption

Robinhood’s **Pattern Day Trader (PDT)** rule is **not** simply “3 trades per week.” Robinhood states that an account may be flagged for PDT if it executes **4 or more day trades within 5 trading days**, where those day trades are more than 6% of total trades in the margin account over that period. FINRA describes the same standard. Robinhood also states that if your account is flagged for PDT, you generally need at least **$25,000** in portfolio value to continue day trading. citeturn0search1turn0search2turn1search8

### 2.4 Important crypto distinction

Robinhood explicitly states that **crypto isn’t subject to pattern day trading regulations**, because it is not regulated by FINRA or the SEC the same way stocks and options are. Crypto trading on Robinhood is available 24/7, though funding and bank-related timing can still affect operations. citeturn0search12turn1search12

### 2.5 Order-execution behavior to account for

Robinhood states that certain crypto market orders are effectively buffered into limit orders to help handle dramatic price moves, with up to **1% buffer for buy orders** and **5% buffer for sell orders**. Robinhood also discloses spread-based execution economics in its crypto order-routing material. This means the app must treat quoted prices, expected prices, and actual fill prices as separate values and must account for slippage and spread explicitly. citeturn1search2turn1search7

---

## 3. Core Product Goal

Build a Laravel service that can:

- Connect securely to Robinhood
- Read balances, positions, orders, and tradable assets
- Evaluate trade opportunities using installed skills and local strategy logic
- Enforce platform, broker, and internal guardrails before any order is sent
- Place crypto trades programmatically when approved
- Store all decisions, rationale, market context, and order outcomes
- Support paper mode, human approval mode, and fully automated mode
- Later expose this through a Brevix AI operational console

---

## 4. Recommended Phase Plan

## Phase 0 — Governance, Feasibility, and Guardrails

### Deliverables

- Confirm Robinhood API scope and credential flow
- Confirm supported crypto assets, state/account availability, and funding limitations
- Define non-negotiable risk controls
- Define what “success” means for a $100 prototype
- Decide whether the app is **crypto-only** in Phase 1

### Success criteria

- Written rule register completed
- Live trading disabled by default
- All trade paths pass through policy checks
- Human approval mode available

### Recommendation

For the initial prototype, **use crypto only** and **avoid intraday scalping**. A $100 account is highly sensitive to spread, slippage, fees, and noise. The first strategy should be **low-frequency**, **position-limited**, and **risk-first**.

---

## Phase 1 — Robinhood Connectivity and Read-Only Sync

### Objectives

- Authenticate to Robinhood Crypto API from Laravel
- Store credential metadata securely
- Sync account balances, buying power, positions, order history, and supported assets
- Build a health dashboard and connectivity checks

### Laravel components

- `RobinhoodClient` service
- `RobinhoodSigner` service for request signing
- `SyncRobinhoodAccountJob`
- `SyncRobinhoodOrdersJob`
- `SyncRobinhoodPositionsJob`
- `SyncRobinhoodAssetsJob`
- `BrokerHealthCheckCommand`

### Data to persist

- Broker account snapshot
- Cash / buying power
- Position quantities and cost basis
- Open / closed orders
- Supported assets and trading status
- API request / response metadata
- Rate-limit headers if exposed

### Success criteria

- Credentials stored outside source control
- App can pull balances and positions on schedule
- All sync activity logged and replayable
- Failed syncs alert and retry safely

---

## Phase 2 — Paper Trading Engine

### Objectives

- Build a simulated execution layer before live orders
- Use real market data and simulated fills
- Track strategy performance under realistic assumptions

### Why this matters

The biggest early mistake is skipping simulation and going straight to live capital. A paper engine lets you validate signal quality, turnover, and drawdown behavior without risking the account.

### Laravel components

- `PaperBrokerAdapter`
- `TradeDecisionEngine`
- `RiskEngine`
- `CreatePaperOrderJob`
- `ReconcilePaperFillJob`

### Simulation assumptions

- Include slippage buffer
- Include spread assumptions
- Include partial-fill possibility if relevant
- Include minimum order sizing rules
- Include cash constraints

### Success criteria

- Strategy can run daily for at least 2–4 weeks in paper mode
- Performance and drawdowns are measurable
- Logs clearly show why each trade was or was not placed

---

## Phase 3 — Live Trading with Hard Guardrails

### Objectives

- Enable very small live positions
- Limit max capital at risk
- Allow only preapproved assets
- Require either human approval or very low-risk autonomy

### Initial live-trading constraints for $100

- **Max position size:** 10%–20% of account equity per signal
- **Max simultaneous positions:** 1–2
- **Max daily notional traded:** 20%–30% of account equity
- **No leverage**
- **No averaging down**
- **No martingale logic**
- **No same-day churn by default**
- **Stop trading after daily or weekly loss threshold**

### Success criteria

- One small live order can be placed, tracked, and reconciled correctly
- Dashboard shows order state transitions
- Emergency kill switch works immediately

---

## Phase 4 — Strategy Iteration and Capital Governance

### Objectives

- Evaluate whether the system has earned the right to keep trading
- Adapt rules as the account changes
- Introduce strategy review cadence

### Governance checkpoint examples

- After 20 paper trades
- After first 10 live trades
- After 30 days of live trading
- After equity crosses $500
- After equity crosses $1,000
- After equity crosses $25,000

### At higher account values

If the account eventually exceeds **$25,000**, revisit the strategy and regulatory handling around day trading for securities. However, if this project remains Robinhood-crypto-only, the PDT issue is materially less relevant because Robinhood states crypto is not subject to PDT regulations. citeturn0search12turn1search8

---

## 5. Architecture Recommendation

## 5.1 Deployment model

Use the existing server-side environment rather than Amplify for this worker-oriented workflow.

### Recommended stack

- **EC2**: Laravel application runtime
- **Supervisor**: queue worker management
- **Cron**: Laravel scheduler
- **PostgreSQL / RDS or existing DB**: persistent state, logs, analytics
- **Redis**: queues, locks, rate limits, idempotency helpers
- **AWS Secrets Manager or SSM Parameter Store**: Robinhood credentials
- **CloudWatch**: logs and alarms
- **S3**: exports, reports, audit artifacts

## 5.2 High-level services

- **Broker Integration Layer**
  - Robinhood API adapter
  - signing/authentication
  - retries and rate limiting

- **Strategy Layer**
  - signals
  - ranking
  - regime filters
  - scoring

- **Risk Layer**
  - account-level controls
  - symbol-level limits
  - max drawdown rules
  - kill switch
  - cooldowns

- **Execution Layer**
  - order creation
  - order status reconciliation
  - cancel/replace rules if supported

- **Audit Layer**
  - immutable trade decision journal
  - market context snapshot
  - model/skill output capture
  - human override records

- **Operations Layer**
  - alerts
  - approvals
  - dashboards
  - health checks

---

## 6. Proposed Laravel Module Structure

```text
app/
  Console/
    Commands/
      BrokerHealthCheckCommand.php
      EvaluateStrategiesCommand.php
      ReconcileBrokerOrdersCommand.php
      FreezeTradingCommand.php
  Enums/
    BrokerType.php
    OrderSide.php
    OrderStatus.php
    StrategyMode.php
    TradingDecisionStatus.php
  Jobs/
    SyncRobinhoodAccountJob.php
    SyncRobinhoodPositionsJob.php
    SyncRobinhoodOrdersJob.php
    EvaluateSignalsJob.php
    CreateTradeDecisionJob.php
    SubmitBrokerOrderJob.php
    ReconcileBrokerFillJob.php
    SendRiskAlertJob.php
  Models/
    BrokerAccount.php
    BrokerCredential.php
    Asset.php
    Position.php
    BrokerOrder.php
    TradeDecision.php
    StrategyRun.php
    RiskEvent.php
    PolicyCheck.php
    DailyPortfolioSnapshot.php
  Services/
    Broker/
      Robinhood/
        RobinhoodClient.php
        RobinhoodSigner.php
        RobinhoodMapper.php
    Strategy/
      SignalAggregator.php
      TaSignalService.php
      MarketRankService.php
      EntryRuleService.php
      ExitRuleService.php
    Risk/
      RiskEngine.php
      ExposureService.php
      DrawdownService.php
      PolicyEngine.php
    Execution/
      TradeExecutionService.php
      OrderSizingService.php
      SlippageModel.php
    Audit/
      DecisionJournalService.php
      TradeReportService.php
  Policies/
    TradingPolicy.php
config/
  broker.php
  trading.php
  risk.php
database/
  migrations/
  seeders/
routes/
  api.php
```

---

## 7. Core Database Design

## 7.1 Essential tables

### `broker_credentials`
Stores encrypted references and metadata for Robinhood API credentials.

Fields:
- `id`
- `broker`
- `label`
- `api_key_ref`
- `secret_ref`
- `status`
- `last_verified_at`
- `created_at`
- `updated_at`

### `broker_accounts`
Stores account-level snapshots.

Fields:
- `id`
- `broker`
- `external_account_id`
- `account_type`
- `currency`
- `buying_power`
- `cash_balance`
- `equity`
- `status`
- `snapshot_at`
- timestamps

### `assets`
Tradable instruments supported in the system.

Fields:
- `id`
- `broker`
- `symbol`
- `asset_type`
- `is_tradable`
- `is_enabled`
- `min_order_notional`
- `price_precision`
- `quantity_precision`
- timestamps

### `positions`
Current and historical positions.

Fields:
- `id`
- `broker_account_id`
- `asset_id`
- `quantity`
- `avg_cost`
- `market_value`
- `unrealized_pnl`
- `snapshot_at`
- timestamps

### `broker_orders`
Broker order ledger.

Fields:
- `id`
- `broker_account_id`
- `asset_id`
- `external_order_id`
- `client_order_id`
- `side`
- `order_type`
- `time_in_force`
- `requested_quantity`
- `requested_notional`
- `requested_price`
- `status`
- `filled_quantity`
- `filled_notional`
- `avg_fill_price`
- `submitted_at`
- `filled_at`
- `raw_request_json`
- `raw_response_json`
- timestamps

### `trade_decisions`
Canonical pre-trade decision record.

Fields:
- `id`
- `strategy_run_id`
- `asset_id`
- `decision`
- `score`
- `confidence`
- `market_context_json`
- `signal_context_json`
- `risk_context_json`
- `policy_result_json`
- `requires_human_approval`
- `approved_by`
- `approved_at`
- `status`
- timestamps

### `strategy_runs`
One record per evaluation cycle.

Fields:
- `id`
- `strategy_name`
- `mode`
- `started_at`
- `completed_at`
- `account_equity`
- `summary_json`
- `status`
- timestamps

### `risk_events`
Risk violations and automated actions.

Fields:
- `id`
- `severity`
- `event_type`
- `asset_id`
- `broker_order_id`
- `message`
- `context_json`
- `triggered_at`
- timestamps

### `daily_portfolio_snapshots`
For performance measurement.

Fields:
- `id`
- `broker_account_id`
- `equity`
- `cash`
- `invested_value`
- `realized_pnl`
- `unrealized_pnl`
- `drawdown_pct`
- `snapshot_date`
- timestamps

---

## 8. Strategy Framework Recommendation

## 8.1 Start with one strategy only

Do not launch multiple live strategies at once. Start with one clear, testable approach.

### Best initial candidate for $100

A **low-frequency crypto swing strategy** with trend confirmation and volatility/risk filters.

Why:
- Less sensitive to constant noise than scalping
- Fewer trades means less spread drag
- Easier to audit and improve
- Better fit for a very small account

## 8.2 Example initial strategy

### Strategy name
`BTC_ETH_Momentum_Filtered_v1`

### Universe
- BTC
- ETH
- optionally 1–3 additional highly liquid Robinhood-supported crypto assets later

### Entry conditions
- Price above medium-term moving average
- Short-term momentum positive
- RSI not overextended beyond configured ceiling
- Volatility below configured threshold
- No open position in same asset
- Account drawdown within allowed range

### Exit conditions
- Trend breakdown
- Max adverse move hit
- Profit target hit
- Time-based exit after N days
- Global risk-off trigger

### Sizing
- Fixed fractional sizing from total equity
- Example: 10% notional per trade, capped by cash and risk budget

### Why this is a good first candidate
- Simple enough to explain
- Simple enough to backtest
- Less likely to overtrade
- Easier to compare paper vs live behavior

---

## 9. How to Use the Installed Skills

Your stated goal is to use all available project/global skills where appropriate. The key is to make skills **assistive**, not authoritative. The final order decision should still flow through your own deterministic policy and risk engine.

## 9.1 `crypto-market-rank`
**Use for:** market breadth and asset ranking

Suggested role:
- Rank eligible crypto assets by trend, liquidity proxy, relative strength, or multi-factor score
- Feed shortlist to the strategy engine
- Never place trades solely because of ranking

Output to store:
- rank snapshot
- factor breakdown
- timestamped score inputs

## 9.2 `crypto-ta-analyzer`
**Use for:** technical signal generation

Suggested role:
- Compute TA indicators and signal summaries
- Generate entry/exit flags and score components
- Help classify trend, momentum, overbought/oversold, and volatility state

Output to store:
- indicators used
- signal direction
- confidence and caveats

## 9.3 `binance/binance-skills-hub`
**Use for:** broader crypto market intelligence, not execution

Suggested role:
- Market data enrichment
- Cross-venue context
- Additional validation against broader crypto conditions

Important caution:
- Do not let Binance-oriented execution logic directly drive Robinhood order placement without normalization, because market structure, asset naming, supported order types, and execution conditions can differ.

## 9.4 Best practice for skills orchestration

Each strategy cycle should produce:

1. Market ranking output
2. TA output
3. Strategy score output
4. Risk checks output
5. Final deterministic order decision

The final decision should be explainable as:

> “Signals were favorable, ranking was acceptable, account risk limits were clear, policy checks passed, and the order met all broker constraints.”

Not:

> “The AI thought it looked good.”

---

## 10. Rule Register: External and Internal Rules to Enforce

## 10.1 External / broker / platform rules

These must be validated and periodically refreshed.

### Robinhood / market constraints
- Supported asset must be tradable through Robinhood
- Account must be in a supported jurisdiction/state for crypto features
- Available buying power must be sufficient
- Asset/order must satisfy Robinhood minimums and precision requirements
- Respect API authentication and signing requirements
- Respect endpoint rate limits and retry windows
- Treat market order behavior carefully because Robinhood may buffer crypto market orders into limit orders with buy/sell protection bands citeturn1search0turn1search2

### Funding / settlement constraints
- Crypto is non-marginable on Robinhood support materials
- Funding availability and instant buying power may be constrained by account type and deposit status
- Bank holidays and cash-transfer timing can affect usable cash, even though crypto itself trades 24/7 citeturn1search6turn1search9turn1search12

### PDT / trading-frequency constraints
- For stocks/options in margin accounts, 4 or more day trades in 5 trading days can trigger PDT status
- Crypto is not subject to PDT according to Robinhood support, but this should still be periodically re-verified in case broker rules change citeturn0search1turn0search12turn0search2

## 10.2 Internal controls

These are required even if Robinhood itself would allow the order.

### Capital preservation rules
- Max single-trade risk budget
- Max daily loss
- Max weekly loss
- Max drawdown from equity high-water mark
- Max open positions
- Max exposure per asset
- No trading during degraded broker connectivity
- No trading during stale market-data condition

### Strategy hygiene rules
- No live trading until paper-trading threshold met
- No strategy change without version bump
- No live deployment without rollback plan
- No trade if signal explanation is incomplete
- No trade if model output conflicts with deterministic policy

### Operational rules
- Require idempotency key for order submission
- Store raw broker response for every order event
- Reconcile broker order state on every cycle
- Lock account during uncertain order state
- Alert on duplicate orders or mismatched positions

---

## 11. Order Workflow

## 11.1 Decision lifecycle

1. Scheduler starts strategy run
2. Fetch latest account, cash, positions, market context, and skill outputs
3. Compute candidate trades
4. Pass each candidate through risk engine
5. Pass surviving candidates through policy engine
6. Create `trade_decisions` record
7. If paper mode: simulate order
8. If live mode and approval required: wait for approval
9. If live mode and approved: submit order to Robinhood
10. Reconcile order status until terminal state
11. Update position and performance snapshots
12. Emit alerts and reports

## 11.2 Order states

- `draft`
- `blocked_by_policy`
- `awaiting_human_approval`
- `approved`
- `submitted`
- `partially_filled`
- `filled`
- `cancelled`
- `rejected`
- `reconciliation_required`

---

## 12. Risk Management Plan

## 12.1 Non-negotiable controls for the first live version

- Kill switch in config + database
- Daily loss stop
- Weekly loss stop
- Max notional per trade
- Max position count
- Asset allowlist only
- Cooldown after each live trade
- Human approval for first 10 live trades
- Human approval required after any losing streak threshold

## 12.2 Suggested starting thresholds for $100

These are conservative by design.

- Max asset universe: BTC, ETH only
- Max position notional: $10–$20
- Max daily realized loss: 2% of account equity
- Max weekly realized loss: 5% of account equity
- Max drawdown from peak: 8%–10%
- Max trades per day: 1
- Max new entries per week: 3

## 12.3 Why the account size matters

With $100, the system is fighting:
- spread drag
- slippage
- noise
- minimum order sizing constraints
- reduced diversification
- higher percentage impact from small mistakes

That means the first version must optimize for **survival and learning**, not aggressive returns.

---

## 13. Monitoring, Alerts, and Review Cadence

## 13.1 Alerts

Send alerts for:
- failed broker auth
- failed sync
- rejected order
- duplicate order attempt
- drawdown threshold breach
- daily loss stop hit
- stale data
- strategy disabled automatically

## 13.2 Daily review

Review:
- account equity
- open positions
- realized / unrealized PnL
- rule violations
- skipped opportunities
- execution quality vs expected fill

## 13.3 Weekly review

Review:
- win rate
- average gain / loss
- expectancy
- turnover
- spread/slippage cost
- strategy drift
- whether live trading should continue unchanged

## 13.4 Monthly review

Decide whether to:
- keep current strategy
- adjust thresholds
- expand asset universe
- increase position sizing
- disable live trading and return to paper mode

---

## 14. Security Plan

## 14.1 Credential handling

- Do not store Robinhood secrets in `.env` on disk if avoidable
- Prefer AWS Secrets Manager or SSM Parameter Store
- Encrypt any locally cached secrets
- Limit IAM access by role
- Rotate credentials when possible

## 14.2 Application security

- Signed outbound requests only
- Rate-limit all internal order endpoints
- Require operator authentication for any admin action
- Log all approval and override actions
- Use queue isolation for execution-related jobs
- Prevent accidental duplicate submissions through idempotency keys and distributed locks

## 14.3 Operational safety

- Separate paper mode and live mode credentials/config
- Separate staging and production databases if possible
- Add an explicit `TRADING_ENABLED=false` default
- Add `BROKER_MODE=paper|live`
- Add `HUMAN_APPROVAL_REQUIRED=true`

---

## 15. Suggested Milestones for Claude Code / Codex / Gemini

## Milestone 1 — Broker foundation

Build:
- Laravel service classes for Robinhood auth/signing
- account sync jobs
- positions sync
- orders sync
- broker health command
- encrypted credential storage

Definition of done:
- application connects successfully
- all read-only broker data is stored and viewable

## Milestone 2 — Trade domain model

Build:
- migrations for core tables
- enums and model layer
- decision journal structure
- policy engine skeleton
- risk engine skeleton

Definition of done:
- a full trade decision can be represented without placing an order

## Milestone 3 — Paper engine

Build:
- simulated broker adapter
- fill model
- performance snapshots
- strategy-run orchestration

Definition of done:
- one strategy can run end-to-end in paper mode daily

## Milestone 4 — Skill orchestration

Build:
- adapters for `crypto-market-rank`
- adapters for `crypto-ta-analyzer`
- optional enrichment through Binance skills
- normalized scoring pipeline

Definition of done:
- one daily strategy run stores all skill outputs and produces a deterministic decision

## Milestone 5 — Live execution with approvals

Build:
- broker order submit job
- reconciliation worker
- approval workflow
- kill switch
- notifications

Definition of done:
- one approved live order can be placed and reconciled safely

## Milestone 6 — Operational dashboard

Build:
- strategy runs dashboard
- positions page
- orders page
- risk events page
- manual approval page
- system status page

Definition of done:
- operator can review and intervene without direct database access

---

## 16. Acceptance Criteria for Phase 1 MVP

### Broker connectivity
- Given valid Robinhood API credentials, when the sync job runs, then account balances, positions, and recent orders are persisted successfully.
- Given invalid credentials, when the sync job runs, then the job fails safely, records the failure, and issues an alert without placing any order.

### Policy enforcement
- Given a trade candidate that breaches a configured limit, when the policy engine evaluates it, then the candidate is rejected and logged with the exact rule violation.

### Paper mode
- Given paper mode is enabled, when the strategy produces a buy signal, then a simulated order is created and no live broker order is submitted.

### Live mode safety
- Given live mode is enabled but human approval is required, when a valid trade candidate is produced, then no broker order is submitted until approval is recorded.

### Auditability
- Given any strategy run, when it completes, then the system stores the inputs, signal outputs, policy outputs, and resulting action in a traceable decision journal.

---

## 17. Recommended Initial Config

```env
BROKER=robinhood
BROKER_MODE=paper
TRADING_ENABLED=false
HUMAN_APPROVAL_REQUIRED=true
TRADING_ALLOWED_ASSETS=BTC,ETH
MAX_OPEN_POSITIONS=1
MAX_POSITION_NOTIONAL_USD=15
MAX_DAILY_LOSS_PCT=2
MAX_WEEKLY_LOSS_PCT=5
MAX_DRAWDOWN_PCT=8
MAX_NEW_ENTRIES_PER_DAY=1
MAX_NEW_ENTRIES_PER_WEEK=3
STRATEGY_NAME=BTC_ETH_Momentum_Filtered_v1
```

---

## 18. What “Success” Should Mean Early

For the first 60–90 days, success should **not** mean “high returns.” It should mean:

- no uncontrolled live orders
- no broken reconciliations
- no rule violations slipping through
- high-quality logs and decision traces
- stable paper-trading behavior
- small, understandable live trades
- evidence the strategy has a real edge after accounting for spread and slippage

Only after that should position sizing or strategy complexity increase.

---

## 19. Key Risks

### Product risks
- Unrealistic return expectations on a $100 base
- Overfitting strategy to recent price action
- Confusing signal quality with execution quality

### Technical risks
- Broker API changes
- auth/signing mistakes
- duplicate or orphaned orders
- incomplete reconciliation logic

### Trading risks
- spread and slippage overwhelm edge
- too much turnover
- concentration in a tiny account
- false confidence after a short winning streak

### Governance risks
- changing rules ad hoc after losses
- enabling live mode too early
- adding too many assets or strategies too soon

---

## 20. Final Recommendation

Build this as a **Robinhood crypto trading system first**, not a general Robinhood stock-trading bot.

Start with:
- one official broker integration
- one small strategy
- one paper-trading engine
- one risk engine
- one approval workflow
- one dashboard

Do not optimize for scale or UI first. Optimize for:
- correctness
- safety
- explainability
- auditability
- disciplined strategy iteration

If the system proves stable and shows an edge after costs, then Phase 2/3 can introduce:
- Brevix AI conversational controls
- strategy comparison
- broader asset coverage
- smarter portfolio logic
- adaptive capital governance as equity grows

---

## 21. Source Notes Used for This Plan

- Robinhood official Crypto Trading API documentation and authentication/rate-limit references. citeturn0search0turn1search0
- Robinhood newsroom announcement for the official Crypto Trading API. citeturn0search24
- Robinhood support on pattern day trading and PDT protection. citeturn0search1turn0search4turn1search8
- FINRA’s explanation of the PDT rule. citeturn0search2
- Robinhood support noting crypto is not subject to PDT rules. citeturn0search12
- Robinhood support on crypto order behavior, funding, and 24/7 trading context. citeturn1search2turn1search6turn1search7turn1search12
