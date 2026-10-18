# M5 control-center foundation evidence

October 18 follow-up: [paper-policy/virtual-cash implementation](M5-PAPER-POLICY-EVIDENCE.md) adds explicit application of complete drafts to a separate virtual portfolio. The original October 17 draft-only evidence below is historical; automatic scheduler/model consumption and production deployment remain unfinished. The owner waived M4's seven-session requirement October 18, with no completed sessions claimed.

Implemented in the development checkout October 17, 2026. M5 remains partial; M4's seven-session gate stays open. Existing design-document edits were preserved. No production deployment, production migration, account change, broker request, AI request or service activation occurred.

## Delivered behavior

- **Control Center navigation** at `/mcp/dashboard/control` in the existing console, using its layout, authentication, operator authorization, no-store response policy and CSRF protection. An unbound operator can inspect current instructions/settings but cannot save account-scoped drafts.
- **Paper mandate draft editor:** virtual starting cash; long-term/active/cash allocations; maximum position and daily paper-loss pause percentages; local scan, AI-decision and news intervals; monthly AI/data envelope; free-text AI guidance; reason for change. Blank financial fields remain unspecified. Default frequency/budget suggestions are visibly proposals, not saved configuration. No capital/allocation/risk amount was selected for the owner.
- **Exact validation:** financial values are quoted, plain decimal strings; percentages cannot exceed 100; specified allocations must total exactly 100 with decimal arithmetic; positive capital/risk values and a maximum $150 monthly envelope; bounded integer intervals; bounded text. No browser/model input can set mode, credentials, research enablement, account/actor identity or brokerage-write flags through this editor.
- **Encrypted append-only versions:** use the existing immutable-record and account-scope patterns, in a separate paper-planning table. Payload and change reason are encrypted using the application's key. Actor comes from the authenticated session. This does not append to or replace the current monitoring policy table.
- **Safe edits/retries:** a connection-row lock and unique version/request constraints serialize writes. A stale editor gets HTTP 409; the same request/payload is idempotent; reuse of a request ID for different content is refused. Forms carry the opaque account scope and base version; an old browser tab cannot save into a newly bound account even when both scopes have revision zero. The scope is also checked again under the lock.
- **History and restore:** browse paginated versions; inspect saved fields and reasons; copy an earlier version into a new latest draft without altering history. Foreign-account or old-binding versions cannot be restored. Restoring a draft does not reverse trades or update runtime policy.
- **ChatGPT proposal exchange:** copy planning-only JSON, discuss it externally, and paste a returned partial JSON object. Unknown/protected fields, numeric JSON money, invalid syntax/values and stale bases are refused. Preview loads allowed fields into the editor, preserves other saved fields and explicitly does not persist or activate anything. Saving is a separate operator action. No automatic ChatGPT synchronization or MCP policy-write tool was added.
- **Database visibility:** paginated, current-account research runs with status, errors, model/prompt versions, hashes, recorded usage/cost basis and stored results; paginated source evidence with source/retrieval times. Stored model/external text is escaped. Existing Research pages remain available for budget, events, watchlist, theses and cited findings. Missing rows remain empty/unknown.
- **Rules visibility:** show the current research adapter's code-defined prompt and configured model, research availability, cache behavior and conditional dispatch cadence. Clearly distinguish current runtime configuration from draft cadence/guidance. Historical full prompts are not retained, so the preview does not claim to reproduce an older request.

## Verification

Installed versions inspected: PHP 8.5.11, locked Laravel 12.70.0, PHPUnit 11.5.55 and Vite 7.3.7. Dependencies and lockfiles were not changed.

| Check | Result |
|---|---|
| `composer validate --no-check-publish` | Passed |
| `vendor/bin/phpunit` | **200 passed / 1405 assertions; 14 PostgreSQL-only skips** (214 discovered) |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | **214 passed / 1728 assertions** |
| `npm run build` | Passed |
| `vendor/bin/pint --dirty`, `git diff --check` | Passed |
| Visual review | Headless Chrome, synthetic authenticated page from an in-memory SQLite fixture, 1440 px and 500 px; narrow capture used reduced-motion preference to avoid headless animation timing. No runtime data/profile used. |

`ControlCenterTest` covers authentication/operator/CSRF enforcement on new mutations, unbound behavior, encrypted append-only saves, idempotency, conflicting/stale edits, exact decimal/percentage/cadence/budget validation, protected fields, restore/rebinding isolation, proposal preview without persistence, scoped pagination and escaped evidence/results. Queue/HTTP fakes verify saves/previews dispatch nothing. `ConsoleInterfaceTest` checks the new page's forms and explicit action allowlist. `PaperMandateConcurrencyTest` runs two independent PostgreSQL processes editing revision zero: exactly one saves and one conflicts, with one persisted version.

Initial verification found test-fixture issues: a many-case validation test exhausted the real route throttle, and a manually seeded research run omitted its guarded UUID. The fixtures were corrected (time advancement and explicit synthetic UUID assignment); final full suites above passed. The throttle remains enabled in application routes.

## Migration and deployment impact

One additive migration, `2026_10_17_000010_create_mcp_paper_mandate_versions`, adds `mcp_paper_mandate_versions` with connection/actor references, account scope, unique revision/request identifiers, encrypted payload/reason, restore provenance and save time. Existing tables, policies and data are unchanged; no backfill. It was exercised only by tests against `mcp_trading_test` / `mcp_trading`, protected by the existing database guard and readiness checks. A separate read-only transaction with the explicitly quoted test-schema search path verified containing database `mcp_trading_test`, login/schema owner `joe`, and new-table placement in `mcp_trading`. No migration/reset command targeted development or production business databases.

The new page requires this migration before use on a bound account. A future reviewed production release must use the existing owner-authorized migration helper, retain restricted runtime database access and verify permissions/backup/restore coverage for the additional table. This source change has **not been deployed** to the port-8080 service. No production readiness or deployed browser check is claimed.

## Remaining M5 work

- The saved draft is **not consumed** by a model, scheduler, risk monitor or paper executor. Dynamic frequency, validated activation and per-decision policy/guidance request snapshots still need implementation. No live-mode switch exists here.
- Complete the owner-defined paper mandate: starting portfolio, exact eligibility, sizing discretion, loss/pause semantics and order/fill assumptions. Draft editing is available without requiring those to be invented in chat.
- Choose and verify the data/news provider, model and reviewed pricing/credential. No real provider/model compatibility, affordable five-minute decision cadence or automatic broad-universe ingestion is established.
- Implement the separate decimal paper ledger and realistic order lifecycle, decision pipeline, recovery and evaluation described in [M5 design](M5-DESIGN.md).
- Production migration/deployment and interactive owner review of the deployed editor remain unperformed. M6 real brokerage writes remain unauthorized.
