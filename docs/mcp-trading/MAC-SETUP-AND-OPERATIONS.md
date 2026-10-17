# MacBook setup and operations guide

Audience: the owner running the application. Codex should consult this document for deployment work. This is a runbook for the planned application, not evidence that installation or deployment has happened.

## 1. Separate development from unattended operation

Use VS Code and Codex for development in the repository. Once monitoring is ready, run unattended services from a stable deployed checkout/release directory, with its own environment and database configuration. Editing the checkout used by running workers can put different processes on different code versions.

Start with a loopback-only site at `http://127.0.0.1:8080`. No router forwarding or public tunnel is required. Remote access is a later decision requiring authenticated HTTPS/private network access. Development and runtime environments must not share test databases or queue tables.

The MacBook needs power, a stable network, and a supported awake configuration. Closing the lid may suspend work; `caffeinate` is not a guarantee against lid sleep, reboot, battery exhaustion, or network failure. Use an independent heartbeat alert. FileVault can require interactive unlock after a reboot. Do not disable it to claim unattended availability.

## 2. Verify installed prerequisites

Run locally in the repository, inspecting output rather than assuming every binary is on the shell PATH:

```bash
pwd
git status --short
command -v php
command -v composer
command -v nginx
command -v php-fpm
command -v psql
command -v node
command -v npm
php -v
php -m
composer --version
nginx -v
psql --version
```

PHP must satisfy `composer.json` and the lockfile. Verify `pdo_pgsql`, `mbstring`, `openssl`, `curl`, and the extensions Composer reports; add `bcmath` or the chosen decimal library for precise arithmetic. Queue timeouts need the supported process-control capabilities on the actual CLI runtime. PHP-FPM and CLI should use compatible versions/configuration.

Composer and Node/npm are required for dependencies and Vite assets even though Python is installed. Choose Node compatible with the locked Vite release. Python is needed only if M1 selects the optional bridge or later analytics require it. Do not install unneeded services such as Redis just because `.env.example` contains their defaults.

If using Homebrew, `brew --prefix` and `brew services list` identify the actual prefix and versioned service names. Apple Silicon often uses `/opt/homebrew`; Intel often uses `/usr/local`. Discover paths before writing service definitions. Commands launched by `launchd` do not inherit your interactive shell's setup.

## 3. PostgreSQL: database versus schema

`mcp_trading` was reported created as a **schema**. A database contains schemas. Laravel's `DB_DATABASE` must be the database containing that schema, not automatically `mcp_trading`.

Using your PostgreSQL client, identify the intended database. In that database run these read-only checks:

```sql
SELECT current_database(), current_user;
SELECT schema_name, schema_owner
FROM information_schema.schemata
WHERE schema_name = 'mcp_trading';
SHOW search_path;
SELECT table_schema, table_name
FROM information_schema.tables
WHERE table_schema IN ('mcp_trading', 'public')
ORDER BY table_schema, table_name;
```

If the schema is not returned, check the database selection; do not immediately create another schema or rename anything. If `mcp_trading` is actually a database, document that correction and choose its application schema explicitly.

Use the underscore schema name `mcp_trading` and quote identifiers in SQL: `"mcp_trading"`. Changing the application reference does not rename an existing database schema. The first applicable schema on the search path determines where unqualified new tables are created. Restrict schema creation privileges to trusted roles. [PostgreSQL schema documentation](https://www.postgresql.org/docs/current/ddl-schemas.html).

### Roles and privileges

Use a dedicated migration/owner role and a restricted runtime role before unattended deployment. The migration role owns application tables and can create/alter them. The runtime role has connection, schema usage, table read/write, and sequence privileges needed by Laravel, but is not a superuser and does not create arbitrary objects.

Have an administrator grant these privileges only within the intended database/schema and set default privileges **for the role that actually creates tables**. Roles and default privileges are not fully preserved by a normal per-database dump; include their recreation in the recovery record. Avoid changing privileges on unrelated schemas/databases.

### Required Laravel change in M0

The PostgreSQL runtime connection uses:

```php
'search_path' => env('DB_SCHEMA', 'mcp_trading'),
```

Then configure the actual containing database and role in the private environment:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=<actual_containing_database>
DB_SCHEMA=mcp_trading
DB_USERNAME=<dedicated_application_role>
DB_PASSWORD=<secret_not_committed>
```

Angle-bracket values are placeholders, not working credentials. Keep `DB_URL` unset unless intentionally using it; a connection URL can override individual fields. After the M0 change, verify through Laravel that `current_schema()` is `mcp_trading` and that migration/queue/cache/session tables land there. Testing only a standalone `psql` connection is insufficient.

Do not migrate until database, schema, ownership, and target tables are confirmed. If existing application tables live in `public`, plan a backup and explicit migration/backfill; changing search path can otherwise look like an empty installation.

Use a separate database, e.g. `mcp_trading_test`, with its own `mcp_trading` schema and credentials for integration tests. Never point `RefreshDatabase` or `migrate:fresh` at the runtime database.

## 4. Bootstrap the local application

These are standard setup commands, not permission to run them against an unidentified database. Complete the M0 schema/config changes and legacy scheduler guards first.

```bash
composer install
composer check-platform-reqs
npm ci
npm run build
```

Create `.env` from `.env.example` only if it does not already exist. Replace example database credentials and set the verified database/schema. Generate `APP_KEY` once for a new installation:

```bash
php artisan key:generate
```

Do not regenerate an existing key: it may be required to decrypt stored credentials. Keep a secure backup separate from database dumps.

Initial settings:

```dotenv
APP_NAME="MCP Trading"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8080
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
TRADING_ENABLED=false
BROKER_MODE=paper
HUMAN_APPROVAL_REQUIRED=true
```

The existing `BROKER` values select legacy crypto adapters. Do not set a fictional `BROKER=robinhood_mcp` until the new adapter/configuration exists. M0 must independently prevent legacy sync/evaluation jobs from being scheduled. New execution-mode settings are implemented by the milestones, not activated by this sample.

After database verification:

```bash
php artisan config:clear
php artisan migrate:status
php artisan migrate
php artisan route:list
php artisan schedule:list
```

Use a migration credential for migrations; reconnect runtime services using their restricted credential afterward. Verify route protection and scheduled jobs before starting workers. During stable unattended deployment set `APP_DEBUG=false`, use a suitable runtime environment name, build assets, and refresh configuration/cache as part of a controlled deployment.

## 5. Nginx and PHP-FPM

Serve only the deployed application's `public` directory. Confirm PHP-FPM's actual listening address and permissions. The following is a template: replace both paths and the FPM address before testing.

```nginx
server {
    listen 127.0.0.1:8080;
    server_name localhost;
    root /ABSOLUTE/DEPLOYED/REPO/public;
    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass 127.0.0.1:9000;
    }

    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

Port 9000 is illustrative; use the verified socket/port. Avoid duplicate listeners if Nginx already serves another site. Validate with `nginx -t` before reloading. The service user needs read access to application files and write access only where needed, especially `storage` and `bootstrap/cache`; never solve permissions with recursive `777`. [Laravel deployment guidance](https://laravel.com/docs/12.x/deployment).

Acceptance: the app loads; unauthenticated requests cannot access portfolio/approval endpoints; `/.env`, `/.git/config`, and non-front-controller PHP paths are inaccessible. Test session/CSRF behavior in the browser. Loopback HTTP is the initial local-only arrangement; HTTPS and secure cookies are required before non-loopback access.

## 6. Background processes

Run PostgreSQL, PHP-FPM, and Nginx through their installed service manager. Run one Laravel scheduler and separate queue workers through `launchd`. Use Homebrew services only for installed packages it manages; use your own plist for application workers. Avoid two schedulers (for example both cron and `schedule:work`).

Planned foreground commands after M0–M2 are implemented:

```bash
php artisan schedule:work
php artisan queue:work mcp_monitoring --queue=monitoring --sleep=2 --tries=3 --timeout=600
php artisan queue:work mcp_monitoring --queue=reconciliation --sleep=2 --tries=3 --timeout=600
php artisan queue:work mcp_research --queue=research --sleep=3 --tries=1 --timeout=300
php artisan queue:work database --queue=notifications --sleep=3 --tries=3 --timeout=60
```

These are M4 deployment examples, not an instruction to start services now. M2 adds the `mcp_monitoring` database queue connection with `retry_after=720` for 600-second monitoring/reconciliation workers; the default legacy database connection remains separate. M3 adds the separate `mcp_research` connection (`retry_after=420`) for 300-second research jobs that are never generically retried. Notification workers remain future work. Run each in a separate supervised process. Job-level retry policy must distinguish idempotent reads from writes. Configure the database queue `retry_after` longer than the longest worker timeout plus margin; M3 uses 420 seconds for the 300-second research worker. Use shorter HTTP timeouts inside each job. Confirm the actual configuration key/environment mapping in `config/queue.php`. Future execution jobs use their own explicit recovery rules, not generic retries. [Laravel queues](https://laravel.com/docs/12.x/queues).

Example monitoring-worker LaunchAgent template:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>local.mcp-trading.monitoring</string>
  <key>ProgramArguments</key><array>
    <string>/ABSOLUTE/PATH/TO/php</string>
    <string>/ABSOLUTE/DEPLOYED/REPO/artisan</string>
    <string>queue:work</string><string>mcp_monitoring</string>
    <string>--queue=monitoring</string><string>--sleep=2</string>
    <string>--tries=3</string><string>--timeout=600</string>
  </array>
  <key>WorkingDirectory</key><string>/ABSOLUTE/DEPLOYED/REPO</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>ThrottleInterval</key><integer>10</integer>
  <key>StandardOutPath</key><string>/ABSOLUTE/LOG/DIRECTORY/monitoring.out.log</string>
  <key>StandardErrorPath</key><string>/ABSOLUTE/LOG/DIRECTORY/monitoring.err.log</string>
</dict></plist>
```

Generate equivalent plists for scheduler, research, and notifications with unique labels/log files. Create log directories before loading; validate each plist with `plutil -lint`. Do not embed secrets in plists. A user LaunchAgent runs in the user's login session; it does not guarantee operation before login or after logout. If operation without login is required, configure a dedicated service account and LaunchDaemons deliberately and test permissions/startup. This is an operator choice, not an implicit reason to run the app as root.

For temporary awake operation, `caffeinate -i` can prevent idle sleep while it runs. Configure the Mac's supported power behavior and test the actual lid/monitor setup. Keep the production checklist honest about unavoidable manual unlock after reboot.

## 7. Credentials and integrations

Keep application secrets out of Git. Store the initial local credentials in a permission-restricted environment file; encrypt broker OAuth tokens at rest using the chosen application secret store. Mask identifiers in UI/logs and redact access/refresh tokens, authorization headers, passwords, and OAuth callback codes.

Connect Robinhood through the application's supported OAuth flow when M1 is ready. The owner completes login/consent. Verify the account shown by the application before accepting the binding. Tests must not borrow the owner's live mutation access.

Configure an AI API project and a model available to that project in M3. Configure provider-side budget alerts/limits and the application's own cost accounting. Use read-only source data for research; keep trade execution out of AI tool permissions. M3 reads only the application-specific `MCP_RESEARCH_*` settings listed in the M3 notes below; do not reuse a developer-tool or chat-session credential.

Choose the alert destination and independent heartbeat service in M4. A local notification alone cannot tell you the laptop has shut down. Verify a forced missed heartbeat reaches your off-device destination. Never put full portfolio details or account identifiers into an external heartbeat URL.

## 8. Backups and recovery

Back up the application database nightly, retain at least 14 daily and 8 weekly recovery points, encrypt stored copies, and keep one copy off the Mac. This is a proposed retention default. Record backup success/age and test restore monthly.

Use a dedicated application database where feasible. If this database is shared, document exactly what must be restored: a whole-database dump includes unrelated data; a schema-only dump may omit dependencies and roles. Do not blindly restore a shared database.

Example whole-database backup template for a dedicated application database:

```bash
pg_dump --host=127.0.0.1 --username=<backup_role> --dbname=<app_database> --format=custom --file=<backup_file.dump>
```

Replace placeholders; supply passwords through a protected password mechanism, not command-line arguments. Back up `APP_KEY`, required secret-store recovery material, service configuration, installed version inventory, and role/grant definitions separately. Database backups alone are insufficient if encrypted tokens cannot be decrypted.

Restore into a newly created isolated recovery database, with scheduler/execution disabled and no live write credentials. Verify schema, migrations, record counts, sample theses, policy versions, snapshots, and ledger consistency. Reconcile from fresh broker reads before resuming any runtime. Never replay an old queue or stale order intent just because it was restored.

## 9. Deployment and rollback

1. Finish tests/build in development and record the release commit.
2. Back up data. For a future live deployment, disable new execution and reconcile outstanding orders before maintenance.
3. Stop scheduler/worker dispatch cleanly; account for in-flight work.
4. Deploy the reviewed code and locked dependencies to the stable runtime directory; run reviewed additive migrations with the migration role.
5. Refresh configuration, restart long-running workers/scheduler, and verify health, schema, account freshness, and logs.
6. Keep execution disabled until its separate readiness checks pass. Monitoring release deployment never enables live mode.

Rollback application code only if the schema remains compatible. Prefer a forward fix for data migrations. Do not run automatic destructive down migrations or restore an older database over new broker activity. After any recovery, reconcile external state before resuming work.

## 10. Incident procedures

| Incident | Operator action |
|---|---|
| Broker authentication expired/revoked | Reconnect through supported OAuth; verify account binding; monitoring shows unavailable until a complete sync |
| Stale/partial account data | Inspect integration and pagination errors; keep last valid snapshot labeled stale; block new exposure |
| Worker or scheduler stopped | Inspect service/logs, restart the failed component, verify a fresh run and no duplicate events |
| Database unavailable or disk full | Stop mutation dispatch, preserve logs, restore service/storage, verify transactions and reconcile |
| Unknown order outcome | Disable new exposure; inspect broker history/reference and local intent; never resubmit with a fresh key |
| Research budget exhausted | Review usage/subscriptions; monitoring continues; increase budget only deliberately |
| Alert delivery failed | Inspect destination/provider and independent heartbeat; acknowledge only after delivery is verified |
| Legacy spreadsheet lock | Treat as an external migration incident; do not clear it automatically or assume it proves an order exists |
| Suspected credential disclosure | Disable affected integration, rotate/revoke through provider-supported flow, review audit history |

The future emergency-disable control blocks new broker mutations. It does not cancel open orders or sell holdings. Those are separate consequential actions requiring their own verification/authorization.

## 11. Daily and weekly routine

Daily: check latest successful sync, unresolved incidents/orders, data age, heartbeat, and spend. Review material thesis changes rather than every routine HOLD.

Weekly: review performance with flows separated, research costs, attribution, missed opportunities, backup health, and whether alerts were useful. Adjust the system based on evidence; do not increase capital or risk solely because the latest trades won.

Before any live pilot: confirm owner-selected limits and sleeves, actual approval behavior, complete broker reconciliation, tested emergency disable, current backups, exclusive executor cutover, and explicit live authorization. Until then the application remains a monitoring/research system.

## M0 implementation notes (October 16, 2026)

M0 is implemented; see [the verified environment and test evidence](M0-FOUNDATION-EVIDENCE.md). On this Mac the existing schema is `mcp_trading` in database `postgres`, owned by `admin`. The runtime schema was verified read-only after the schema reference update. Configure private credentials before using the bootstrap commands above.

Use `php artisan mcp:doctor` for a read-only Laravel database/schema/owner check. Exit 1 with `migrations_present=false` is expected before the first migration. Migration commands refuse an unavailable/wrong application schema before DDL. Public `/up` checks liveness; `/ready` reports only schema/migration-repository availability, not overall monitoring readiness.

Keep these settings false through local foundation setup:

```dotenv
LEGACY_CRYPTO_SCHEDULES_ENABLED=false
MCP_MONITORING_ENABLED=false
BROKERAGE_WRITES_ENABLED=false
```

Set `MCP_OPERATOR_EMAILS` to explicitly trusted local operators. This grants access to **all legacy accounts**. Only operator emails also listed in `TRADING_APPROVER_EMAILS` may approve/reject decisions. After migrations, provision each local login with `php artisan mcp:operator-create <email> <name>`; the password prompt is hidden and existing users are not overwritten. Visit `/login`. Financial endpoints keep `/api/...` URLs but require session cookies and CSRF tokens for mutations. No registration endpoint exists.

Run `vendor/bin/phpunit` for memory-only tests and `vendor/bin/phpunit -c phpunit.pgsql.xml` for the dedicated PostgreSQL fixture. The latter requires `mcp_trading_test` and its quoted `"mcp_trading"` schema; configure `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USERNAME`, `TEST_DB_PASSWORD` independently of runtime. The default socket is `/tmp` with the local PHP file owner. Tests refresh that fixture's tables. Never configure runtime to use that database. Clear cached runtime configuration before testing. Existing lockfile dependency advisories are recorded in the evidence and require review before unattended deployment.

## M1 manual connection checks

The additive M1 migration is applied locally. See [M1 evidence and exact connection steps](M1-INTEGRATION-EVIDENCE.md). The manual connection page is `/mcp/robinhood`; OAuth uses this application's own consent and encrypted PostgreSQL state. The supported callback is a fixed loopback URL on the same hostname/port used for sign-in. Set `ROBINHOOD_MCP_REDIRECT_URI` explicitly; do not reuse ChatGPT/browser tokens.

`ROBINHOOD_MCP_ENABLED` defaults false. Enabling it permits manual OAuth/discovery/approved reads only; all scheduler, monitoring and write flags remain false. Six real read mappings are pinned after authorized schema review. The selected existing account is bound for read-only monitoring; the connection page provides manual portfolio/equity position/equity order reads and exact equity symbol/quote lookups. The other account onboarding attempt remains unresolved and was not retried. Coverage is explicit: equity position/order lists are not all-asset snapshots. Missing source times remain unknown. OAuth renewal and reads after a new process start have been verified; this does not establish unattended monitoring readiness. When configuring Nginx for OAuth, omit callback query strings from access logs; one-use callback codes must not become operational log data. The application responses already disable caching and referrer propagation.


## Current M2 operator notes

M2 is implemented locally; see [M2 monitoring evidence](M2-MONITORING-EVIDENCE.md). The additive twelve-table migration is applied in `postgres` / `mcp_trading`, owner `admin`. The landing route is now `/mcp/dashboard`; the existing OAuth connection screen remains `/mcp/robinhood`. A foreground `mcp:sync --account=<local-connection-id> --key=<stable-key>` or the dashboard POST refresh reads the bound account without enabling schedules. Reusing a completed key does not query the broker again. `mcp:monitor --once` respects the disabled monitoring flag.

No services were started by M2. Keep monitoring, legacy schedules and brokerage writes disabled until the separate deployment work is reviewed. Source timestamps/quotes, calendar/daily bars, flow history, owner sleeve mappings and external alerts remain explicit coverage limitations. Runtime returns and drawdown are unavailable; no historical baseline was inferred.

Archive import accepts the JSON contract in the M2 evidence. Preview with `mcp:import --file=/path/export.json --dry-run`; committing requires a separate explicit `--commit --account=<local-connection-id>` operator action. This stores historical evidence/quarantine only; it never activates policy or replaces current holdings. No real archive was imported.

## Current M3 operator notes

M3 is implemented locally; see [M3 research evidence](M3-RESEARCH-EVIDENCE.md). Research is **disabled** and the Research page reports why. Evidence entry, the local watchlist, thesis authoring and summaries work without AI; `php artisan mcp:summary --kind=daily` (or `weekly`) summarizes the previous closed New York day or week and refuses open periods. To enable research deliberately, set in the private `.env`: `MCP_RESEARCH_MODEL` (a model your API project can access), a dedicated `MCP_RESEARCH_API_KEY`, reviewed `MCP_RESEARCH_INPUT_COST_PER_MTOK` / `MCP_RESEARCH_OUTPUT_COST_PER_MTOK` prices, `MCP_RESEARCH_FIXED_MONTHLY_COSTS` covering every AI/data subscription (use `0.00` only if there are none) and optionally `MCP_RESEARCH_MONTHLY_ENVELOPE` (default `100.00`, ceiling `150.00`). Configure provider-side limits too; the application ledger cannot guarantee an invoice. Then set `MCP_RESEARCH_ENABLED=true` and try one bounded foreground cycle with `php artisan mcp:research --once --sync` before any worker or schedule.

Research proposals are non-executable. Adopting research creates a thesis version under your signed-in identity; it never creates orders or changes policy. If the budget is exhausted, monitoring continues and optional research stops until the next UTC month or a deliberate configuration change.

