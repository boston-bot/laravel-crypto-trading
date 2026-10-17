# M1 read-only integration: completed evidence

Verified October 16, 2026. **M1 is complete for the selected existing account and the configured equity read coverage.** M2 was not started. No brokerage mutation, trade review, account creation, brokerage-approval change or live execution was performed. No commit was created.

The operator completed this application's OAuth consent at `http://127.0.0.1:8080`. Robinhood subsequently showed an onboarding error for the proposed Electron account/agent. That onboarding remains unresolved; the operator explicitly chose the existing agentic-enabled account instead. The app did not retry account creation or silently substitute an account.

## Delivered and verified behavior

- A closed `Operation` enum and `BrokerGateway` contract expose reads only. The configured operations are accounts, portfolio, equity positions, equity orders, exact equity-symbol lookup, and equity quotes. Optional cash flows, historical-performance/cash-flow coverage and exchange-session calendars raise explicit unsupported-capability errors; earnings calendars are not treated as exchange-session calendars. No guessed endpoints or order parameters were added.
- The official PHP MCP SDK is pinned at **0.8.1**. Thirteen dependencies were added, with zero existing package upgrades and no Python bridge. Initialization, JSON/SSE handling and session transport stay in the SDK; Laravel owns policy, OAuth and business state. See [the transport decision](M1-PHP-SDK-SPIKE.md).
- A PSR-18 adapter restricts URLs, RPC methods, headers and tools, refuses redirects, applies size/time bounds and preserves typed HTTP errors. The SDK can invoke only locally reviewed read-tool mappings. No tool-review, placement, cancellation, watchlist mutation or approval-setting operation is available through this adapter, even though the remote catalog advertises such tools.
- Complete tool discovery encrypts a sanitized catalog and canonical schema fingerprints in PostgreSQL. Functional schemas are pinned in `config/robinhood_mcp.php`; fresh discovery must match before reads proceed. Descriptions, examples and defaults cannot grant authority; remote schema references/header-changing annotations are refused. Partial discovery is not published.
- OAuth uses the observed broker metadata, a fixed resource parameter, PKCE S256, one-use random state, ten-minute expiry, operator/session binding, issuer checks and a fixed configured loopback callback. Only the observed public-client flow is supported: optional pre-registration or operator-triggered dynamic registration, with `token_endpoint_auth_method=none`.
- Tokens, client IDs, metadata, PKCE verifiers, capability catalogs and account binding identifiers are encrypted with the existing application key and hidden from ordinary serialization. Row locks serialize refreshes; rotated credentials persist atomically. Revocation/ambiguous refresh requires reconnect, while rate limiting preserves credentials for later retry. Explicit OAuth renewal is operator-only, CSRF-protected and independently throttled. New authorization clears the account binding; ordinary renewal preserves it.
- The selected existing account was re-read and verified active, self-directed, individual, broker type `limited_margin`, `agentic_allowed=true`, with both deactivation flags false. The operator's selection was bound as **`agentic_monitoring`**, recording the local operator identity and time. This monitoring classification is not a future execution grant. No borrowing or broker account-setting changes are authorized. Other reviewed broker account types are classified separately as personal/advisory; unknown or inactive types stay unknown and cannot be automatically bound.
- Typed normalized records preserve exact quoted `Decimal` values, nulls, retrieval time, source time when available, account-scope provenance, data coverage and completeness. Portfolio totals include separate asset-class values and unleveraged buying power; total account value is not confused with the equities-only value. Missing monetary values do not become zero.
- The broker's position tool supplies symbols without position/instrument IDs. The app preserves that absence and does not fabricate broker references. Account-scoped response bodies omit account numbers, so their normalized scope is explicitly labeled `request_binding`; this is request evidence, not a falsely claimed response echo. Binding/authorization changes invalidate in-flight results.
- Cursor reads continue until a terminal page and detect loops, duplicates, bounds, lost pages and transport failures. Broker pagination links are parsed only for cursor data, never fetched; foreign links/account changes are rejected. The reviewed broker schema defines optional terminal `next`, unlike generic fixtures requiring an explicit null. Nullable lists remain unknown/incomplete; they are never silently changed into empty holdings.
- Quotes preserve regular and extended-hours prices/source times separately. The newer available last-price source is labeled with its session; bid/ask/last source times drive conservative freshness checks. Zero/crossed/malformed quotes are rejected. An extended-hours observation does not assert trading-session eligibility.
- `/` redirects to `/mcp/robinhood`. The operator page supports connection/reconnect, schema discovery, credential renewal, account choice and manual read links. It remains session-authenticated, operator-authorized, CSRF-protected for mutations, account-scoped and served with `no-store`/`no-referrer`. Browser read responses mask account numbers. There is no public registration or generic remote-tool endpoint.
- There are no new schedules, jobs, snapshot publications, risk decisions, AI calls or execution capabilities. `FakeBrokerGateway` is visibly labeled synthetic and cannot establish a real account binding.

## External checks actually performed

Unauthenticated discovery returned the exact resource/issuer `https://agent.robinhood.com/mcp/trading`, authorization URL `https://robinhood.com/oauth`, token URL `https://api.robinhood.com/oauth2/token/`, registration URL `https://agent.robinhood.com/oauth/trading/register`, PKCE S256, authorization-code/refresh grants and public-client authentication. Only the `internal` scope was advertised; no broker-enforced granular read-only scope is claimed. The app's boundary restricts its actual use.

After the operator's consent, a new PHP process used only the app's encrypted OAuth credentials to negotiate **MCP 2025-11-25** and discover **83 tool schemas**. No credentials were borrowed from ChatGPT, Codex, browser or connector sessions.

The reviewed real tool/schema mappings are:

| Operation | Actual tool | Coverage |
|---|---|---|
| Accounts | `get_accounts` | Authorized account discovery; binding remains operator-selected |
| Portfolio | `get_portfolio` | Account totals and available asset-class totals |
| Positions | `get_equity_positions` | Equity positions only |
| Orders | `get_equity_orders` | Equity orders only |
| Instruments | `search` | Exact requested equity-symbol match, not an exhaustive search/universe scan |
| Quotes | `get_equity_quotes` | Requested equity-symbol quote and its component source times |

The real account lookup returned three accounts, one agentic-enabled. After explicit selection/revalidation, the fresh-process normalized reads returned complete results: portfolio **1 record**, equity positions **2**, equity orders **3**, exact symbol lookup **1**, quotes **1**. Each actual response was one terminal page; multi-page/failure behavior was verified with fakes. SPY was used solely as a public quote/lookup connectivity check, not an investment recommendation.

A subsequent real OAuth renewal succeeded and **rotated the refresh token**. Another new PHP process then completed a portfolio read with the persisted renewed credentials and unchanged binding. No token values, account numbers or balances were printed. Source times were supplied for quote components; portfolio/positions/orders supplied none, so their source times remain **null/unknown**. Structural completeness does not claim verified market-data freshness where source timestamps are absent.

## Captured fixtures and privacy

`tests/Fixtures/Mcp/robinhood-catalog-2026-10-16.json` contains the six sanitized functional schemas. The six `robinhood-*-sanitized.json` response fixtures came from app-authorized reads. Private account/order/execution/instrument identifiers were replaced with sequential aliases, nicknames and freeform fields redacted, holding symbols aliased, financial values replaced with synthetic values, trade timestamps replaced, and broker guidance removed. Original IDs and hashes of private IDs are not retained. Public quote times are preserved to test session/freshness handling. The instrument-search fixture preserves a single exact synthetic symbol plus distinct alternatives.

These are contract-shape fixtures, not broker references, real portfolio balances, trading history evidence, simulated performance or replay inputs. Captured-contract tests use fake HTTP and a fixed clock. No secrets are stored in fixtures or logs.

## Persistence and final verification

The only M1 migration is `2026_10_16_000005_create_mcp_connections`, creating `mcp_connections`, `mcp_oauth_credentials` and `mcp_oauth_attempts`. It was tested in `mcp_trading_test` and applied as batch 2 in the verified runtime database **`postgres`**, schema **`mcp-trading`**, role/owner **`admin`**. Catalog checks confirmed placement in that schema. There was no financial-ledger backfill, destructive runtime migration, key regeneration or privilege/ownership change.

PostgreSQL runtime/test sessions now use **UTC**. The initial refresh-race test caught that UTC expiry strings could otherwise be interpreted in the server's local timezone. M1 runtime token tables had no prior data when that session setting was corrected.

| Check | Final result |
|---|---|
| `composer validate` / `composer check-platform-reqs` | Passed |
| `vendor/bin/phpunit` | 67 passed, 363 assertions; 3 PostgreSQL-only tests skipped |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | 70 passed, 387 assertions; no skips |
| `npm run build` | Passed, locked Vite 7.3.2 |
| Targeted Pint / `git diff --check` | Passed |
| `php artisan migrate:status` | Six migrations applied; M1 in batch 2 |
| `php artisan schedule:list` | No schedules defined |
| Runtime status | Connected, bound as `agentic_monitoring`; manual integration enabled |
| Runtime safety flags | Legacy schedules, monitoring, legacy trading and brokerage writes all false |

Automated tests cover schema/contract drift, untrusted catalog prose, forbidden RPC/tools/URLs, account scoping, CSRF, cross-operator access, forged identity, exact decimals, source-time/session handling, null-versus-empty results, duplicate/partial/limited pagination, 401/403/429/timeouts, OAuth state/PKCE/expiry/replay/issuer, encrypted storage, explicit renewal, revocation, ambiguous refresh and binding races. Two separate PostgreSQL worker processes produce one refresh request; another process reuses the persisted credentials. All automated external HTTP is faked and unexpected requests are rejected. Real reads and OAuth renewal above were separate bounded operator-authorized integration checks; no live brokerage mutation was used as a test.

## Limits and next milestone

M1 provides manual, account-bound reads and connection health, not durable monitoring snapshots, risk observations, portfolio performance, a monitoring dashboard, external alerts or unattended readiness. Those begin with M2 and later milestones. Equity position/order coverage must not be described as a complete all-asset snapshot. Unsupported cash-flow coverage makes flow-dependent performance unknown; earnings-calendar tools do not supply exchange sessions. The existing account's `limited_margin` broker label does not authorize borrowing or leverage; later policies must enforce the initial cash-only strategy and verify eligible funds at any future execution boundary.

The earlier Electron onboarding error was not repaired or retried; selecting the existing account was an explicit operator decision. No requirement for a new account remains in M1. Unknown/unreviewed broker type combinations, absent source times, source schema drift and missing pages stay explicit failures/unknowns.

Existing M0 dependency advisories remain unresolved and require a separate reviewed remediation before unattended deployment. Nginx deployment, worker/scheduler supervision, off-device alerts, backups and the seven-session proving period remain future work. The loopback development server suppresses output to avoid printing callback query strings; deployed access logs must also omit OAuth callback queries.

**Next milestone: M2 — Durable monitoring and risk observations.** No M2 work or live-mode activation was performed.
