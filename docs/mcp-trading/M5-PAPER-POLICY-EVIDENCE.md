# M5 applied paper policy and opening cash ledger

Later October 18: [offline simulation evidence](M5-OFFLINE-SIMULATION-EVIDENCE.md) adds synthetic orders/fills and accounting on this separate ledger. The opening-cash checkpoint below is historical; automatic proposal execution and deployment remain unfinished.

Historical checkpoint below. The later [decision-runner increment](M5-DECISION-RUNNER-EVIDENCE.md) connects applied rules/cadence to disabled proposals and request auditing; simulated fills and runtime deployment remain unfinished.

October 18, 2026. The owner explicitly waived the seven-session M4 acceptance period and directed continued work. M4 is accepted with that period **unverified (0/7 completed)**. This M5 increment preserves existing control-center work and historical M4 evidence. It does not change the installed runtime, run AI requests or enable trading.

## Delivered behavior

- The Control Center can explicitly apply the latest **complete saved draft** to a separate virtual portfolio. The server requires the authenticated operator, CSRF, current account scope, expected draft/applied revisions, a unique request ID and paper-only confirmation. Unsaved editor/proposal changes are not applied.
- All capital, allocation, risk, cadence and budget fields must pass the existing exact-decimal validation. AI guidance is optional. No actual starting capital or risk limits were selected for the owner; all numeric examples in tests/previews are synthetic.
- First application atomically creates one portfolio, one immutable opening-cash entry and one immutable applied-policy snapshot. The snapshot is encrypted, identifies the original draft, actor, revision and time, fixes mode to `paper`, and explicitly marks execution eligibility unverified.
- A retry returns the original application without duplicating cash. Connection-row locking serializes concurrent initializations; stale/conflicting requests, foreign drafts and changed account bindings are refused.
- Later applications change the selected rules/revision only. Existing cash, reservations, ledger version and cash history remain unchanged. Changing initial capital through a policy is refused; applying settings is not a deposit, reset or rebalance. Saving/restoring drafts does not silently replace applied rules.
- The browser separately shows applied rules, virtual starting/current/reserved/available cash, ledger version and recent cash entries. Available cash uses exact decimal subtraction. PostgreSQL rejects negative available cash and invalid ledger/revision bounds.
- Monitoring policies, broker-confirmed orders and legacy paper/crypto ledgers are untouched. The UI states that **the paper scheduler, model decision runner and order/fill engine are not connected yet**. Selected risk/allocation/cadence settings are not presented as currently enforced by a completed trading engine.

## Migration and isolation

`2026_10_18_000011_create_mcp_paper_policy_and_cash_ledger` adds three tables: `mcp_paper_portfolios`, `mcp_paper_policy_applications`, and `mcp_paper_cash_entries`. They use foreign keys, unique scoped/revision/request constraints and `numeric(38,18)` amounts. This depends on the preceding, still-unreleased paper-mandate migration. No existing table/backfill is modified.

Before PostgreSQL verification, a read-only transaction with `SET LOCAL search_path TO "mcp_trading"` confirmed containing database `mcp_trading_test`, login `joe`, schema `mcp_trading` and schema owner `joe`. Tests use the existing database guard/readiness checks; no migration/reset command targeted development or production business databases. The new tables were verified in the test schema afterward.

**Not deployed:** both M5 migrations need a reviewed owner-authorized deployment, restricted-runtime permission checks and backup/restore verification for the additional tables. Port 8080, services, configuration, credentials and effective policies were not changed. No Git commit was created.

## Verification

| Check | Result |
|---|---|
| `composer validate --no-check-publish` | Passed (sandbox cache unavailable; proceeded without it) |
| `vendor/bin/phpunit` | **208 passed / 1477 assertions; 16 PostgreSQL-only skips** (224 discovered) |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | **224 passed / 1812 assertions** |
| `npm run build` | Passed |
| `vendor/bin/pint --dirty`, `git diff --check` | Passed |
| Visual review | Synthetic authenticated in-memory SQLite page in isolated headless Chrome, 1440/500 px, reduced-motion preference; applied rules and a different pending draft displayed together. No runtime data/profile used. |

`PaperPolicyTest` covers authorization/CSRF/paper confirmation, incomplete drafts, exact/idempotent opening cash, encrypted snapshots, preserving prior cash/reservations, refusing changed initial capital, explicit application after restore, stale policy/draft/binding, foreign drafts and server-derived identity/mode. Injected failure rolls back the entire initialization. A PostgreSQL-specific test rejects an invalid reservation at the database boundary. Queue/HTTP assertions prove no external dispatch.

`PaperPolicyConcurrencyTest` runs two PostgreSQL processes applying revision zero: one applies, one conflicts, leaving exactly one opening entry/portfolio/application. Existing control-center, research, operations and native FPM regressions pass in the full suite.

## Remaining M5 work

- Finish instrument eligibility, investment/sizing discretion and order assumptions. The owner must enter actual virtual capital/limits before applying a real paper plan; fixture values are not defaults or recommendations.
- Implement the structured AI decision runner with applied-policy/ledger references, bounded source context and request evidence; connect cadence/budget controls to actual dispatch. M3 research retains its existing reviewed prompt/configuration.
- Select and verify a model/application credential, reviewed pricing and market/news coverage within the confirmed budget. No real model/provider compatibility or achievable five-minute throughput is established.
- Add paper positions, reservations and realistic order/fill lifecycle with conservation and restart tests. Current accounting covers opening cash and policy revisions only; no simulated return/trade performance exists.
- Keep M6 brokerage writes disabled. The M4 waiver is neither live activation nor strategy-performance evidence.
