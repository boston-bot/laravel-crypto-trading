# MCP trading application: start here

Current runtime source is **`261de7e`**, verified October 22 Central. `/ready` passes. Forward-paper signals, handoff/fills and batch collection are enabled; brokerage writes and AI decisions/research remain disabled. Historical research flags and the experiment worker were activated separately; two CRM/MSFT comparisons completed with zero closed trades. [Historical activation](MAC-SETUP-AND-OPERATIONS.md#october-21-experiment-runner-deployment-and-activation), [queued outcomes](M5-COLLECTION-READINESS-EVIDENCE.md). Batch evaluation and intraday warm-up are deployed and enabled. The first full batch cycle improved quote age at evaluation; complete warm-up acceptance remains pending. M5 remains partial.

Historical quote research now has a source-only, default-off intraday mode and a completed CRM/MSFT session trial. It shares the existing PHP rules, sizes and fill-price math; it makes no forward paper orders or live submissions. [Trial, actual results and limitations](M5-INTRADAY-REPLAY-EVIDENCE.md). It now also supports consecutive full-session replay and a [strategy experiment register](STRATEGY-EXPERIMENTS.md) with explicit findings and inconclusive/superseded outcomes. [Two-session evidence](M5-MULTISESSION-REPLAY-EVIDENCE.md). Those replay modes are now deployed in `5b59abf`; the original evidence records their source-only verification.

The [durable experiment runner](M5-EXPERIMENT-RUNNER-EVIDENCE.md) now queues acquisition, deterministic replay, accounting verification and comparisons independently of an LLM conversation. It supports frozen-data reuse and rebuilt-rule comparisons by experiment UUID. Migration `000027` and its separate worker are now installed. Defaults remain off in source; only the runtime research flags were deliberately enabled. [Operator commands](MAC-SETUP-AND-OPERATIONS.md#durable-historical-experiments-source-only).

The source now also includes an authenticated **Experiments** page for guided start/resume, frozen-dataset comparisons and tabular simulated trades. This UI increment is deployed in `3f60571` and needs no additional migration. [Interface evidence](M5-EXPERIMENT-UI-EVIDENCE.md), [UI procedure](MAC-SETUP-AND-OPERATIONS.md#experiment-controls-and-trade-tables-source-only).

The collection-readiness reporting increment is now deployed in `9c597d1`: live session progress, elapsed-window coverage and quote quality measured with the same admission checks as signals. Post-deployment checks passed; the completed-session measurement is still pending. [Implementation, partial intraday evidence and remaining M5 work](M5-COLLECTION-READINESS-EVIDENCE.md).

October 22 runtime check: the running release is now **`0ee4894`**, with forward-paper signals/handoff/fills and batch collection enabled; brokerage writes and AI decisions remain disabled. The batch-evaluation and background warm-up increment is now deployed as `213411c`, with the new warm-up flag enabled. Today's 15:05 Central cadence capture completed. Post-change quote ages improved (96.6% within 20 seconds), but background warm-up failed to complete SEC classification/profile creation and requires repair. [Batch and warm-up evidence](M5-INTRADAY-COLLECTION-EVIDENCE.md), [operator procedure](MAC-SETUP-AND-OPERATIONS.md#batch-evaluation-and-midday-additions-october-22-source-increment).

A **deployed visual Paper Trading dashboard** now consolidates forward-paper cash/equity, recorded opening valuations, positions, executions, blocked evaluations and data readiness. It is read-only and deployed in `261de7e`, with no dashboard-specific migration or new flag. [Dashboard evidence](M5-PAPER-DASHBOARD-EVIDENCE.md), [operator usage](MAC-SETUP-AND-OPERATIONS.md#visual-paper-trading-dashboard-october-22-source-increment).

October 23 source follow-up: CRWD's signal reached the paper boundary but independently fetched quote/book bids differed. A bounded single quote refresh now attempts to recover a matching observation within the existing session/budget, preserving exact-match and freshness requirements. Deployed as `d5cb58b` October 23 after clean-release verification; runtime readiness and unchanged disabled live writes verified. Sustained live match-rate improvement remains unverified. [Evidence and deployment checks](M5-QUOTE-BOOK-REFRESH-EVIDENCE.md).

Crypto track, October 22: C0 containment and C1 public Coinbase collection are committed and deployed in `261de7e`; migration `000028` is applied and crypto collection remains off. Both keys now exist in development `.env`, and a later presence-only check confirms runtime now recognizes them as well. Coinbase view/trade/transfer permissions were confirmed through a read-only GET; Robinhood permissions remain unverified. Owner C2 paper inputs: any coins subject to eligibility, $400 virtual cash, 5% risk per trade, 25% position cap, 3 open positions and a 5% daily loss pause. These inputs now have source-only policy/virtual-cash and shared-engine evaluation support, requiring migration `000029`. Evaluation defaults off; no runtime crypto policy is applied. [C2 evidence](CRYPTO-C2-RULES-EVIDENCE.md). [Plan](CRYPTO-AUTOMATION-PLAN.md), [C1 evidence](CRYPTO-C1-MARKET-DATA-EVIDENCE.md).

Prepared October 16, 2026. This documentation describes planned work, not an application already deployed or approved to trade.

October 23 C2 source follow-up: a manual read-only Coinbase fee-tier report now supplies redacted review evidence. Policy imports require explicit product-specific all-in scope and timezone-qualified observations; shared crypto costs/sizing reserve the greater maker/taker fee. Account-tier output cannot establish pair applicability or auto-apply policy. No runtime deployment, fee schedule, cash or flag was changed. [C2 follow-up and remaining gate](CRYPTO-C2-RULES-EVIDENCE.md#october-23-cost-review-follow-up-source-only).

Subsequent owner-authorized read, October 23 at 06:59:57 Central: Coinbase returned HTTP 200 and account tier `Intro`, maker 0.50% / taker 0.90%. Pair applicability and tax treatment remain unverified; no product schedule or policy was applied. C2 remains open. [Live read evidence](CRYPTO-C2-RULES-EVIDENCE.md#authorized-live-read-only-fee-tier-check--october-23).

Current milestone update, October 18: M4 is accepted under the owner's explicit waiver of its seven-session proving requirement (0/7 completed; no passed endurance test claimed). M5 paper development is active. Live brokerage mutations remain unauthorized.

Direction change, October 18: the owner adopted a strategy/execution split. Every 30 minutes, ChatGPT scheduled tasks outside this app maintain a dedicated Robinhood watchlist. This app reads it every 5 minutes, and code eligibility rules, daily symbol profiles and deterministic playbooks make the final paper decision. No paid LLM API runs in the app. See [AGENTS.md](../../AGENTS.md#project-direction) and the [revised M5 design](M5-DESIGN.md). Documents below that describe in-app AI decisions record disabled components.

## Documents and audience

| Document | Audience | Purpose |
|---|---|---|
| [Implementation plan](IMPLEMENTATION-PLAN.md) | Owner and Codex | Architecture, scope, data contracts, migration, risks, delivery phases |
| [Development checklist](DEVELOPMENT-CHECKLIST.md) | Codex and developer | Ordered work packages, acceptance tests, ready-to-use VS Code prompts |
| [Mac setup and operations](MAC-SETUP-AND-OPERATIONS.md) | Owner/operator; Codex for deployment work | PostgreSQL schema, dependencies, Nginx, background services, backups, recovery |
| [M1 integration evidence](M1-INTEGRATION-EVIDENCE.md) | Developer and operator | Completed read-only integration, real OAuth/account evidence, coverage and limitations |
| [M2 monitoring evidence](M2-MONITORING-EVIDENCE.md) | Developer and operator | Durable snapshots, dashboard, failure tests, archive contract and remaining data limitations |
| [M3 research evidence](M3-RESEARCH-EVIDENCE.md) | Developer and operator | Research events, cache, budget ledger, validation, UI, summaries and what remains unverified; AI research superseded for continuous use October 18 |
| [M4 operations evidence](M4-OPERATIONS-EVIDENCE.md) | Developer and operator | Health checks, heartbeat, ntfy alerts, incident lifecycle, encrypted backups, restore drill, Mac deployment tooling and remaining owner steps |
| [M4 acceptance log](M4-ACCEPTANCE-LOG.md) | Developer and operator | Actual outage/recovery drill results, explicit calendar horizon, remaining Mac/cloud checks and seven-session proving record |
| [M5 design](M5-DESIGN.md) | Owner and developer | Watchlist-driven rule strategy: Robinhood watchlist intake, eligibility, daily profiles, playbooks, sizing, costs, starting parameters and open items; not implemented functionality |
| [M5 control-center evidence](M5-CONTROL-CENTER-EVIDENCE.md) | Owner and developer | Initial paper-draft editor, history/restore, proposal exchange and PostgreSQL research browsing; tests and remaining activation/deployment gaps |
| [M5 applied paper policy evidence](M5-PAPER-POLICY-EVIDENCE.md) | Owner and developer | Explicit policy application, separate virtual opening cash, concurrent/idempotent initialization and remaining runner/fill gaps |
| [M5 decision runner evidence](M5-DECISION-RUNNER-EVIDENCE.md) | Owner and developer | Disabled AI paper proposals, applied cadence/rules, request/result/cost audit and recovery/concurrency tests; superseded October 18 |
| [M5 offline simulation evidence](M5-OFFLINE-SIMULATION-EVIDENCE.md) | Owner and developer | Synthetic decimal orders/fills, cash/share reservations, concurrency/recovery, accounting reports and remaining real-data/activation gaps |
| [M5 execution and handoff evidence](M5-EXECUTION-HANDOFF-EVIDENCE.md) | Owner and developer | Versioned execution choices, recorded read-only input provenance, disabled proposal handoff, synthetic workflow tests and remaining owner/provider gaps |
| [M5 deterministic eligibility](M5-RULE-ELIGIBILITY-EVIDENCE.md) | Owner and developer | Versioned watchlist-rule policy, immutable assessments, exact decimal thresholds and buy gates; listing/history/liquidity remain unavailable |
| [M5 listing, history and liquidity sources](M5-MARKET-REFERENCE-EVIDENCE.md) | Owner and developer | Reviewed Alpaca listing and split-adjusted daily-bar sources, exact dollar-volume facts, eligibility integration, live read-only check and limits |
| [M5 market calendar and daily profiles](M5-CALENDAR-AND-PROFILES-EVIDENCE.md) | Owner and developer | Automatic session calendar, benchmark history, versioned parameters, exact profile/playbook calculator, immutable profiles, test-isolation fix and the open earnings-source decision |
| [M5 opening valuations and batched collection](M5-OPENING-AND-BATCH-COLLECTION-EVIDENCE.md) | Owner and developer | Immutable first-minute bid valuations, bounded full-universe collection, deployment checkpoint and remaining fractional/replay evidence |
| [M5 intraday collection and warm-up](M5-INTRADAY-COLLECTION-EVIDENCE.md) | Owner and developer | Per-batch paper evaluation, bounded midday reference/profile repair, tests and pending post-close baseline |
| [M5 durable experiment runner](M5-EXPERIMENT-RUNNER-EVIDENCE.md) | Owner and developer | Callable classes, queued/checkpointed research, verification, frozen-data comparisons and activation limits |
| [M5 visual paper dashboard](M5-PAPER-DASHBOARD-EVIDENCE.md) | Owner and developer | Read-only forward-paper summary, opening-equity chart, executions, blockers, readiness and mobile verification |
| [M5 experiment interface](M5-EXPERIMENT-UI-EVIDENCE.md) | Owner and developer | Guided research controls, scoped history, variant comparisons and actual simulated fill/position tables |
| [Strategy experiment register](STRATEGY-EXPERIMENTS.md) | Owner and developer | Hypotheses, fixed comparisons, results, rejected/superseded evidence and next validation questions |
| [M5 multi-session quote replay](M5-MULTISESSION-REPLAY-EVIDENCE.md) | Owner and developer | Position carry, daily state/limits, complete-range archival and actual two-session results |
| [M5 historical quote trial](M5-INTRADAY-REPLAY-EVIDENCE.md) | Owner and developer | Historical SIP quote sampling, exact chronological limit fills, first full-session trial and research assumptions |
| [M5 signal sizing, costs and paper handoff](M5-SIGNAL-PAPER-HANDOFF-EVIDENCE.md) | Owner and developer | Owner-applied risk/cost versions, whole-share sizing, later-observation paper fills, next-day exits, concurrent handoff/recovery and activation limits |
| [M5 deterministic strategy signals](M5-STRATEGY-SIGNALS-EVIDENCE.md) | Owner and developer | Default-off atomic five-minute paper evaluations, entry/exit rules, position provenance, immutable history and verification limits |
| [M5 earnings-date source](M5-EARNINGS-EVIDENCE.md) | Owner and developer | Reviewed Robinhood earnings calendar contract, gated read, immutable records, trading-session blackout, profile integration and limits |
| [M5 replay, fractional contract and cadence](M5-REPLAY-FRACTIONAL-CADENCE-EVIDENCE.md) | Owner and developer | Reviewed fractional order contract, corrected v2 daily replay, superseded v1 results, measured session cadence and remaining owner decisions |
| [M5 research variants](M5-RESEARCH-VARIANTS-EVIDENCE.md) | Owner and developer | Fractional market-order research mode, bounded rule switches and research risk profiles, and the pre-registered [DAILY-003](M5-DAILY-003-2026-10-21.json) and [DAILY-004](M5-DAILY-004-2026-10-21.json) comparisons |
| [M5 fractional forward-paper path](M5-FRACTIONAL-MARKET-PAPER-EVIDENCE.md) | Owner and developer | Guarded market orders, Robinhood per-symbol fractional eligibility, immediate paper fills, owner activation steps and the collection gap to measure first |
| [ChatGPT watchlist task](CHATGPT-WATCHLIST-TASK.md) | Owner | Instruction for the two scheduled ChatGPT tasks that maintain the three strategy watchlists, matched to the app's eligibility, list caps and roles |
| [ChatGPT long-term watchlist task](CHATGPT-LONG-TERM-WATCHLIST-TASK.md) | Owner | Instruction for the scheduled task that maintains the owner's long-term "Core Inventing" list; not read by this application |
| [M5 external-order detection and review](M5-EXTERNAL-ORDER-EVIDENCE.md) | Owner and developer | Immutable scoped external-order cases, owner reviews, latched entry pauses, paper-buy boundary checks and concurrent detection/review tests |
| [M5 dedicated strategy watchlist intake](M5-STRATEGY-WATCHLIST-EVIDENCE.md) | Owner and developer | Versioned dedicated-list selection, encrypted revisions, five-minute intake, cap/grace/removal controls, quiet-list incidents and remaining signal/eligibility work |
| [M5 watchlist and fee evidence](M5-WATCHLIST-EVIDENCE.md) | Owner and developer | Existing-gateway watchlist reads, actual On Watch retrieval, decimal percentage fees and validation |
| [M5 owner mandate intake](M5-OWNER-MANDATE.md) | Owner and developer | Actual paper choices, delegated allocation/fee decisions, verified watchlist membership, application status and which choices still apply after October 18 |
| [M5 broker read efficiency](M5-BROKER-READ-EFFICIENCY-EVIDENCE.md) | Owner and developer | Shared MCP sessions, catalog writes, batched quotes, rate-limit backoff, lock-free token refresh and read throttles; tests and unverified live limits |
| [M5 market collection deployment](M5-COLLECTION-DEPLOYMENT-EVIDENCE.md) | Owner and operator | Bounded read-only allowlist collector, deployment and actual stored-data verification |
| [M5 classification and action coverage](M5-CLASSIFICATION-AND-ACTION-COVERAGE-EVIDENCE.md) | Owner and developer | SEC N-CEN leveraged/inverse and sole-ticker stock evidence, derived eligibility policy, unexposed corporate-action reconciliation, tests and the pending live SEC read |
| [M5 paper corporate-action accounting](M5-CORPORATE-ACTION-ACCOUNTING-EVIDENCE.md) | Owner and developer | Disabled split/dividend ledger reconciliation, late-event review blocks, bounded observed-data policy and remaining coverage gaps |
| [M5 Alpaca corporate-action data](M5-ALPACA-DATA-EVIDENCE.md) | Owner and developer | Owner-selected read-only data adapter, exact decimals, encrypted coverage, successful real access and remaining event-accounting work |
| [M5 Robinhood reference coverage](M5-REFERENCE-COVERAGE-EVIDENCE.md) | Owner and developer | Observed stock/ETF types, dividend subset/date warnings, real gateway checks and missing complete action coverage |
| [M5 real-data paper fill path](M5-REAL-DATA-PAPER-FILLS-EVIDENCE.md) | Owner and developer | Default-off broker-observation fills, simulated provenance, shared liquidity/recovery tests and remaining actual coverage blockers |
| [M5 verified execution-data controls](M5-VERIFIED-DATA-EVIDENCE.md) | Owner and developer | Pinned share-unit semantics, fresh quote/book matching, per-symbol readiness and remaining provider requirements |
| [M5 broker execution-input evidence](M5-MARKET-INPUT-EVIDENCE.md) | Owner and developer | Gated account-tradability and price-book reads, real contract checks and precise remaining fill blockers |
| [M5 deployment evidence](M5-DEPLOYMENT-EVIDENCE.md) | Owner and operator | Deployed partial release/migrations, runtime safety and restore checks, prepared settings and blocked authenticated application |
| [Crypto C2 rules](CRYPTO-C2-RULES-EVIDENCE.md) | Owner and developer | Source-only crypto virtual sleeve, shared rules/sizing, explicit product fees, immutable evidence, tests and deployment boundaries |
| [Crypto C1 market data](CRYPTO-C1-MARKET-DATA-EVIDENCE.md) | Owner and developer | Read-only Coinbase public products/quotes/candles, provenance, health check, live contract check, tests and deployment steps |
| [Crypto automation plan](CRYPTO-AUTOMATION-PLAN.md) | Owner and developer | Adopted October 22; C0 done and C1 (Coinbase data) done in source. Coinbase/Robinhood Crypto automation through the shared engine and M6 boundary: legacy-path containment, milestones C0–C7, effort and owner decisions |
| [Repository AGENTS.md](../../AGENTS.md) | Codex | Concise standing development instructions |

Open the repository root in VS Code so the repository instructions are available to Codex. Begin with milestone M0 in the checklist. Do not ask Codex to implement the entire roadmap in one unreviewed change.

## Decisions carried forward

- Keep Laravel and this repository. Use PostgreSQL as the system of record.
- First release: reliable monitoring of the Robinhood agentic account. Personal-account information can provide advisory context when explicitly configured.
- Maintain separate long-term and active strategy classifications. The exact allocation within/across accounts is still an owner decision; do not invent an 80/20 split.
- Desired eventual capability: bounded automatic trading, subject to supported brokerage access and all required approvals.
- First delivery has no brokerage writes, including watchlist edits and order reviews.
- Host on the owner's MacBook initially. PHP, Nginx, Python, and PostgreSQL were reported installed; the current PostgreSQL application schema is `mcp_trading`. Installation versions, database name, credentials, and service configuration still need local verification.
- Budget: $50–$150/month for AI and data. Planning target $100, maximum planned envelope $150; provider subscriptions and in-flight usage must be included. Since October 18 no paid LLM API runs in the app, so the envelope covers data only.
- Strategy research runs in ChatGPT under the owner's subscription, which maintains a dedicated Robinhood watchlist every 30 minutes. This app reads it every 5 minutes, sets each symbol's strategy from its own data, and makes the final deterministic decision (October 18).
- No options, borrowing, shorts, leveraged/inverse products, or crypto in the initial agentic strategy.
- Crypto (Coinbase, Robinhood Crypto) is a separate track adopted October 22, built on the same engine and execution boundary. Its legacy live order path is retired and venue writes are gated by their own default-off flags. [Crypto plan](CRYPTO-AUTOMATION-PLAN.md).

## What we inspected

The repository has Laravel 12, crypto broker clients, queued synchronization, risk/strategy services, paper trading components, and audit models. It currently exposes a welcome page rather than a monitoring dashboard. Its PostgreSQL search path is hardcoded to `public`; its test configuration uses SQLite.

The two existing ChatGPT tasks, “Investing Monitor — Dual Strategy” and “Investing Monitor — Top of Hour,” were observed paused. Their common protocol delegates research, spreadsheet locking, risk calculations, order intent tracking, and performance accounting to an LLM. A recent task report described `undefined` journal IDs and a retained lock. These are observed historical task facts, not a current broker or spreadsheet reconciliation. Exact custom schedules were not recovered.

## First useful outcome

An authenticated local dashboard can show a complete broker snapshot, its age, positions and orders, exposure, thesis status, and operational incidents. The application runs unattended, survives a worker restart, and alerts when it stops receiving reliable information. No investment action depends on a chat remembering earlier messages.

## Owner inputs that cannot be inferred

Fill these in during the relevant milestone, not all before starting development:

| Input | Needed by | Safe behavior until supplied |
|---|---|---|
| Database containing `mcp_trading`; DB login | M0 | No migrations |
| Actual Mac paths and background-service account | M0/M4 | Foreground testing only |
| Broker OAuth connection and allowed account | M1 | Fake data with conspicuous labels |
| AI project/API credential and accessible model | M3 | Monitoring works; research unavailable. No longer needed after October 18. |
| Notification destination and independent heartbeat service | M4 | Dashboard incidents; no claim of off-device alerting |
| Market/news provider and licensed coverage | M3 | Broker data and manual research only |
| Name of the dedicated Robinhood watchlist; ChatGPT tasks set up not to trade | M5 | No watchlist intake; orders the app didn't create pause new entries |
| Daily price-history and earnings-date sources | M5 | No symbol profiles or new entries |
| Owner confirmation of starting strategy parameters after replay | M5 | Paper only, with versioned starting values |
| Sleeve mapping and capital allocation | M2/M6 | Unclassified holdings visible; no automatic trading |
| Approved strategy, sizing, order preferences, live limits | M6 | Shadow/paper only |
| Paper capital/holdings, sleeve permissions, allowed symbols and fee/slippage assumptions | M5 | Unspecified execution drafts; handoff disabled |

No secrets belong in these documents, Git, chat prompts, or screenshots.
