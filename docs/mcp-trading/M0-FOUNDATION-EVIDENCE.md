# M0 foundation evidence

Verified October 16, 2026 in the development checkout. Implementation complete; this is not an unattended deployment or broker-connectivity claim. No commit was created.

## Environment and baseline

| Component | Installed version | Resolved path |
|---|---|---|
| PHP CLI | 8.5.10, Herd | `/Users/joe/Library/Application Support/Herd/bin/php` (Composer reports `php85`) |
| Composer | 2.10.3 | `/Users/joe/Library/Application Support/Herd/bin/composer` |
| PostgreSQL client/server | 18.6 | `/opt/homebrew/bin/psql`, `/opt/homebrew/bin/postgres` |
| Nginx | 1.31.7 | `/opt/homebrew/bin/nginx` |
| PHP-FPM | 8.5.11, Homebrew | `/opt/homebrew/sbin/php-fpm` |
| Node / npm | 24.22.0 / 11.19.1 | `/Users/joe/Library/Application Support/Herd/config/nvm/versions/node/v24.22.0/bin/` |
| Python, optional | 3.15.0 | `/opt/homebrew/bin/python3` |

CLI includes `pdo_pgsql`, `pdo_sqlite`, `bcmath`, `curl`, `mbstring`, `openssl`, and `pcntl`. `composer check-platform-reqs` passed. Locked Laravel 12.56.0, PHPUnit 11.5.55, Brick Math 0.14.8, and Vite 7.3.2 were used. No package versions were upgraded. Brick Math is now a direct Composer requirement; lock metadata was refreshed.

Initial working tree contained edits to `.env.example` and `README.md`, plus untracked `AGENTS.md` and `docs/mcp-trading/`. Those were preserved; the requested checklist, operator guide and environment sample were extended. No `.env`, vendor directory, or node_modules existed. After installing locked PHP dependencies and rejecting stray HTTP requests, the initial baseline was **1 failure, 15 warnings, 1 pass, 92 assertions**: the welcome-page test lacked an encryption key; warnings referenced the absent `.env`. The test configuration now supplies a public, test-only key. Final direct PHPUnit runs have no warnings. No runtime application key was created or changed.

## PostgreSQL discovery and migration impact

Read-only catalog discovery found one connectable non-template database: **`postgres`**. Its existing **`mcp-trading` schema is owned by `admin`**. Neither that schema nor `public` contained tables. Local socket access as `joe` worked; no passwords were read from external sessions.

Laravel independently confirmed, using `mcp:doctor` and temporary process environment settings:

```json
{"database":"postgres","schema":"mcp-trading","role":"joe","owner":"admin","migrations_present":false}
```

The command correctly exited 1 because the runtime schema is not migrated. No runtime tables, privileges, roles, or schema ownership were changed.

Created the isolated database **`mcp_trading_test`**, owned by local role `joe`, and its quoted `"mcp-trading"` schema. Existing migrations were exercised there by `RefreshDatabase`. Tests assert the migration repository, users, sessions, jobs, cache and financial tables are in `mcp-trading`, and **zero tables are in `public`**. Missing-schema migration attempts are rejected before DDL. No new application tables or backfills are required by M0; later milestones add their own migrations.

`DB_SCHEMA` controls the PostgreSQL search path without a public fallback. Migration commands validate the selected connection (including `--database`) before DDL. The M0 check requires the designated `mcp-trading` schema. It does not create a missing schema.

`phpunit.pgsql.xml` uses the separate `pgsql_test` connection, fixed database `mcp_trading_test`, fixed schema `mcp-trading`, and independent `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USERNAME`, `TEST_DB_PASSWORD`. Defaults use `/tmp`, port 5432, the local PHP file owner, and no password. Runtime `DB_URL` and login fields cannot redirect it. Before refresh/migration, tests reject other database identities, the runtime database named in `.env`/`DB_URL`, and cached runtime configuration; they verify actual PostgreSQL identity too. SQLite tests accept only `:memory:`. Do not use the designated fixture database as a runtime database in any deployment.

## Behavior delivered

- `LEGACY_CRYPTO_SCHEDULES_ENABLED=false` prevents all legacy sync, signal and reconciliation schedules from registering. New monitoring and `BROKERAGE_WRITES_ENABLED` default false. Live submissions are blocked in both the legacy execution service and each broker client's write method. Paper simulation remains separate legacy functionality. These flags do not implement or authorize M6 execution.
- All 16 financial endpoints retain their `/api/...` paths but now use browser session authentication and the `web` CSRF middleware. Stateless unauthenticated scripts must migrate to an authenticated session with CSRF tokens for mutations.
- `MCP_OPERATOR_EMAILS` is a server-controlled, explicit **all-legacy-account** operator grant. Unlisted authenticated users have no financial access. `TRADING_APPROVER_EMAILS` additionally grants decision approval/rejection. Authorization runs before route model binding. This single-owner administration model is not per-account delegation; M1 must bind the intended account explicitly.
- Approval and rejection record the authenticated user's immutable local ID; caller-supplied `actor` is ignored. Approval can dispatch existing paper work; the independent write gate still blocks live submission. Broker credential references and metadata are hidden from serialization; account responses no longer load credentials.
- `/login`, throttled session creation, and CSRF-protected `/logout` are available; there is no public registration. `mcp:operator-create` provisions a configured operator through a hidden password prompt and refuses to overwrite existing credentials.
- `App\Support\Mcp\Decimal` accepts only quoted plain decimal strings, performs exact addition/multiplication/comparison, serializes to a quoted string, and requires an explicit rounding mode. Legacy crypto float calculations are not migrated or approved for reuse by this work. All future new-domain DTOs must use this boundary; M1 supplies those integration DTOs.
- Log channels use JSON formatting and a restrictive context allowlist (`event`, `status`, `error_code`, `run_id`). Freeform messages, exception objects, payloads and other context are omitted to prevent credential/account leakage. Callers should emit stable event/error codes; old freeform diagnostics are intentionally not retained. Private environment files remain private.
- Public `/ready` returns only `ready`/`unavailable` with 200/503, and no connection details or exception text. It checks PostgreSQL schema and migration repository presence, not M1–M4 release readiness. `/up` remains process liveness. `mcp:doctor` gives local CLI database/schema/role/owner details without secrets.

## Final verification

| Command | Result |
|---|---|
| `composer validate` | Passed |
| `composer check-platform-reqs` | Passed |
| `vendor/bin/phpunit` | 29 passing tests, 172 assertions; 2 PostgreSQL-only tests skipped |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | 31 passing tests, 185 assertions; no skips |
| `vendor/bin/pint --dirty` | Applied formatting to changed PHP files |
| `git diff --check` | Passed |
| `npm ci --ignore-scripts` then `npm run build` | Passed, using locked Vite 7.3.2 |
| `php artisan route:list --path=api -v` | All financial routes have session/auth/operator middleware; approvals additionally require approver permission |
| `php artisan schedule:list` | No scheduled tasks defined |

Tests cover all financial routes for anonymous/unprivileged access, forged approver identity, separate approver permission, real CSRF enforcement, login failure/success/logout, disabled scheduler/integrations, disabled live submission and both direct broker-client methods, precise decimals and float rejection, safe structured logs, minimal readiness errors, database guards, and actual PostgreSQL migration placement. External HTTP is faked or mocked, and unexpected Laravel HTTP requests are rejected. No broker/AI calls, real orders, worker/scheduler startup, or background-service changes occurred.

Dependency audits found **43 Composer advisories across 12 existing packages**, and **12 npm vulnerabilities (1 low, 1 moderate, 8 high, 2 critical)**. Audit commands return nonzero; these are existing lockfile findings, not passing security checks. Dependency remediation needs a separate reviewed change before unattended deployment; no automatic upgrade or audit-fix was performed.

## Remaining local setup and next work

The private runtime `.env` is absent. Configure `DB_DATABASE=postgres`, `DB_SCHEMA=mcp-trading` and an explicitly selected migration login authorized for the existing schema owned by `admin` (supply that login's private credential if required). Local `joe` was used for read-only discovery and the disposable fixture, not configured as the unattended runtime role. Before deployment, configure the restricted runtime role/grants described in the operator guide. Re-run `mcp:doctor`, review `migrate:status`, then run ordinary additive `migrate` with the migration role; never run test refresh/reset commands on `postgres`.

For a new local installation, create `.env` from the example, generate its application key once, configure `MCP_OPERATOR_EMAILS` (and `TRADING_APPROVER_EMAILS` only for authorized approvers), then use `php artisan mcp:operator-create <email> <name>` after migrations. All scheduler, monitoring and write flags stay false. Production Nginx/FPM compatibility, grants, browser-over-Nginx checks, service supervision and off-device alerts remain M4 work.

Next product milestone: **M1**, read-only Robinhood MCP integration and application-owned OAuth/account binding. No brokerage or AI credential is needed for the completed M0 fixture tests. No live authorization has been supplied.

## Follow-up after operator migration

October 16, 2026: the operator reported running migrations. Read-only verification confirmed all five migrations applied in batch 1, `mcp:doctor` returned database `postgres`, schema `mcp-trading`, role/owner `admin`, and `migrations_present=true`. The private `.env` now exists. Composer validation and the SQLite suite passed again (29 passing tests, 172 assertions, 2 PostgreSQL-only skips). No scheduled jobs are registered; legacy schedules, monitoring and brokerage writes remain false. The application key is absent, the operator allowlist is empty, and zero allowed operators are provisioned. M1 development may begin; generate the new installation key and configure/provision a local operator before OAuth or browser testing. This follow-up supersedes the earlier runtime migration/environment setup status, not the separate deployment-role and dependency-remediation requirements.

Subsequent operator setup verification on October 16, 2026 confirmed a valid application key through an encryption/decryption round trip and one provisioned user matching `MCP_OPERATOR_EMAILS`. No credentials were printed. Legacy schedules, monitoring and brokerage writes remain disabled. This resolves the key/operator setup items above; M1 OAuth and account binding remain unimplemented.
