# MCP trading application: start here

Prepared October 16, 2026. This documentation describes planned work, not an application already deployed or approved to trade.

## Documents and audience

| Document | Audience | Purpose |
|---|---|---|
| [Implementation plan](IMPLEMENTATION-PLAN.md) | Owner and Codex | Architecture, scope, data contracts, migration, risks, delivery phases |
| [Development checklist](DEVELOPMENT-CHECKLIST.md) | Codex and developer | Ordered work packages, acceptance tests, ready-to-use VS Code prompts |
| [Mac setup and operations](MAC-SETUP-AND-OPERATIONS.md) | Owner/operator; Codex for deployment work | PostgreSQL schema, dependencies, Nginx, background services, backups, recovery |
| [M1 integration evidence](M1-INTEGRATION-EVIDENCE.md) | Developer and operator | Completed read-only integration, real OAuth/account evidence, coverage and limitations |
| [M2 monitoring evidence](M2-MONITORING-EVIDENCE.md) | Developer and operator | Durable snapshots, dashboard, failure tests, archive contract and remaining data limitations |
| [M3 research evidence](M3-RESEARCH-EVIDENCE.md) | Developer and operator | Research events, cache, budget ledger, validation, UI, summaries and what remains unverified |
| [M4 operations evidence](M4-OPERATIONS-EVIDENCE.md) | Developer and operator | Health checks, heartbeat, ntfy alerts, incident lifecycle, encrypted backups, restore drill, Mac deployment tooling and remaining owner steps |
| [Repository AGENTS.md](../../AGENTS.md) | Codex | Concise standing development instructions |

Open the repository root in VS Code so the repository instructions are available to Codex. Begin with milestone M0 in the checklist. Do not ask Codex to implement the entire roadmap in one unreviewed change.

## Decisions carried forward

- Keep Laravel and this repository. Use PostgreSQL as the system of record.
- First release: reliable monitoring of the Robinhood agentic account. Personal-account information can provide advisory context when explicitly configured.
- Maintain separate long-term and active strategy classifications. The exact allocation within/across accounts is still an owner decision; do not invent an 80/20 split.
- Desired eventual capability: bounded automatic trading, subject to supported brokerage access and all required approvals.
- First delivery has no brokerage writes, including watchlist edits and order reviews.
- Host on the owner's MacBook initially. PHP, Nginx, Python, and PostgreSQL were reported installed; the current PostgreSQL application schema is `mcp_trading`. Installation versions, database name, credentials, and service configuration still need local verification.
- Budget: $50–$150/month for AI and data. Planning target $100, maximum planned envelope $150; provider subscriptions and in-flight usage must be included.
- No options, borrowing, shorts, leveraged/inverse products, or crypto in the initial agentic strategy.

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
| AI project/API credential and accessible model | M3 | Monitoring works; research unavailable |
| Notification destination and independent heartbeat service | M4 | Dashboard incidents; no claim of off-device alerting |
| Market/news provider and licensed coverage | M3 | Broker data and manual research only |
| Sleeve mapping and capital allocation | M2/M6 | Unclassified holdings visible; no automatic trading |
| Approved strategy, sizing, order preferences, live limits | M6 | Shadow/paper only |

No secrets belong in these documents, Git, chat prompts, or screenshots.
