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

## M4 runtime layout and operations

M5 watchlist source follow-up (not deployed by this task): `ROBINHOOD_MCP_WATCHLISTS_ENABLED=false` independently gates the two reviewed read-only Robinhood watchlist tools. After deploying that source, an operator can explicitly enable manual reads and use `php artisan mcp:watchlist --account=<local-connection-id> --name='On Watch'` or the connection page. Reuse the app-owned OAuth connection; do not borrow browser/ChatGPT credentials. This neither edits brokerage lists nor changes paper allowlists or schedules. See [verification and limitations](M5-WATCHLIST-EVIDENCE.md).

The runtime is installed at `~/Services/investing-ops/app` as a frozen, locally versioned release of the tested source (origin: the development repository), with `runtime/` (rendered nginx/PHP-FPM configs, socket, pid files) and `logs/` beside it. It serves `http://127.0.0.1:8080` through a dedicated nginx and PHP-FPM pair run as user LaunchAgents (`local.investing-ops.*`); Herd keeps port 80 and Valet stays untouched. See [M4 operations evidence](M4-OPERATIONS-EVIDENCE.md).

```bash
# First install (after cloning the app and creating its .env):
deploy/macos/install.sh
# Every later update, from a committed revision only:
~/Services/investing-ops/app/deploy/macos/deploy.sh main            # add --migrate after reviewing pending migrations
launchctl list | grep investing-ops                                  # agent status
tail -f ~/Services/investing-ops/logs/*.err.log                      # problems
deploy/macos/uninstall.sh                                            # stop and remove agents; data kept
```

Set `MCP_OPERATIONS_ENABLED=true` only in the runtime `.env`. After any `.env` change, run `php artisan optimize` and `php artisan queue:restart` in the runtime directory (or redeploy). Before using separate development and runtime checkouts, give development its own database; they must not share queues or business state. The IDE checkout now uses the separate `mcp_trading_development` / `mcp_trading` database, a development-only APP_KEY, disabled integrations and port 8081. Production uses the existing `postgres` / `mcp_trading` state and original APP_KEY, preserved in the runtime private `.env`. Six user LaunchAgents are installed; port 8080 belongs to runtime Nginx/PHP-FPM.

Owner steps that cannot be automated:
- Subscribe to the ntfy topic stored in the runtime `.env`, then run `php artisan mcp:notify --test` from the runtime directory.
- Create the Healthchecks.io check (1-minute period, 10-minute grace) and set its ping URL.
- Install Google Drive for desktop and set `MCP_BACKUP_OFFSITE_DIR`.
- Store the backup key (`~/.config/investing-ops/backup.key`) and `APP_KEY` in a password manager.
- Configure power settings, then test restart, sleep and outage recovery.



M4 takeover verification: the `000009` additive migration is applied as batch 6. A private backup key exists at `~/.config/investing-ops/backup.key`; a local encrypted backup in `storage/app/backups/` passed a real isolated 54-table restore/decryption drill. Store the backup key and `APP_KEY` in a password manager separately from backups. An off-Mac folder is still unconfigured; a successful local copy alone cannot prove Google Drive has uploaded it. Operations and monitoring remain disabled. See the M4 evidence for final tests and remaining release gates.


October 17 service setup: ntfy now has a random topic in the private `.env`. In System Health choose **Show phone subscription setup** to reveal the server/topic, then subscribe in the official ntfy phone app. No test message has been sent. The owner-approved encrypted backup destination is `~/Library/Mobile Documents/com~apple~CloudDocs/Investing Ops Backups`; a fresh encrypted backup and manifest were copied and hash-verified there. Both keys remain outside that folder. macOS confirms iCloud-managed files but does not supply upload completion, so verify cloud availability from another device before relying on off-Mac recovery. Scheduling remains disabled.


October 17 activation: read-only monitoring and operations are enabled only in `~/Services/investing-ops/app/.env`; edit runtime settings there, then rebuild its cached config/restart workers as appropriate. The IDE `.env` is development-only and intentionally has no ntfy topic, heartbeat or broker enablement. The initial frozen release was `d92f0d6a102e06e06016e58729b2a5aacba9e04e`; its manifest remains recoverable from runtime Git history. Current deployed revision: **`401d6a233c54893cfa35529ffadd72a35d7c9e9c`**, containing the committed M4 work and separate owner migration helper. Later deployments should name a reviewed explicit revision; old M3 revision `deff98e` would roll back M4.

The production scheduler has eight tasks; daily encrypted backups run at 02:30 America/Chicago, monthly restore drills at 03:30 on day 1, and heartbeat/health ticks every minute. Background backup/iCloud copy and an explicit one-shot launchd restore passed; copied files still need remote upload/recovery confirmation. Owner ntfy phone receipt and recurring heartbeat acknowledgements are verified. After explicit owner approval, production now connects as `mcp_trading_runtime`, with table data and sequence permissions only in `postgres` / `mcp_trading`, matching default grants, no administrative privileges/memberships and no schema-create permission. `admin` retains ownership of the schema and all 54 tables. The random login password lives only in runtime private `.env`; cached config is private. The one-time applied grant plan is recorded in `deploy/macos/runtime-role-recovery.sql`. Reloaded services, health, transactional data access, DDL denial and a new encrypted backup/54-table restore all passed. User-session/logout/reboot/network recovery, external outage alert receipt, remote iCloud recovery and the seven-session proving period remain open. AC sleep was already 0; no global power setting was changed.

The original snapshot had no separate migration identity. Deployed release `401d6a2` now includes the owner workflow below. Persistent services stay on the restricted role and cannot perform DDL. Restore drills continue to use the separate local scratch-database administrator; neither the runtime role nor backup files gain owner credentials.

### Operator console

Open `http://127.0.0.1:8080` and sign in with the provisioned operator account. Nothing extra starts the console: the runtime Nginx and PHP-FPM LaunchAgents serve it whenever this macOS user is logged in, including after logout/login, sleep/wake and reboot (verified in the M4 drills). It is not available before login. UI changes reach the runtime only through a committed revision and `deploy/macos/deploy.sh`. System Health shows the operating switches read-only and runs the safe operator actions (health checks without heartbeat, test alert, closed-period summary, database identity). Backups, restore drills and imports stay terminal-only.

### Separate owner connection for reviewed migrations

`deploy/macos/migrate.php` is a one-off CLI process, not a web route or scheduled command. It reads the cached runtime connection, verifies the containing database and `"mcp_trading"` search path, then opens a temporary connection with the verified schema owner's username/password. It accepts no alternative host, database, schema or URL. Current owner: `admin`; runtime: `mcp_trading_runtime`. No new role or grant is required for this workflow. Owner credentials are never added to `.env`, Laravel configuration files or the config cache; the temporary connection is purged on success/failure.

Prepare an operator-owned file outside both checkouts and cloud backup folders, for example `~/.config/investing-ops/migration.json`, using a local editor. Its directory should be `0700`, the file must be `0600`, and it must not be a symlink. Preserve an existing file rather than overwriting it. Use the existing owner's actual database password; do not paste it into chat or command arguments:

```json
{"username":"admin","password":"<existing owner database password>"}
```

Once the helper is present in the reviewed runtime release, review pending migrations and their SQL from that checkout:

```bash
cd "$HOME/Services/investing-ops/app"
export MCP_MIGRATION_CREDENTIALS_FILE="$HOME/.config/investing-ops/migration.json"
/opt/homebrew/opt/php@8.5/bin/php deploy/macos/migrate.php --check
/opt/homebrew/opt/php@8.5/bin/php deploy/macos/migrate.php --pretend
```

`--check` uses only the runtime login and returns 0 for no pending files, 2 for pending files, or 1 for an error. `--pretend` requires owner credentials and runs Laravel's pretend mode; review the migration PHP too, since pretend mode cannot neutralize arbitrary PHP side effects. A missing/malformed/public credential file, wrong owner/target or competing migration process fails closed. A PostgreSQL advisory lock serializes this helper's migrations; use this helper consistently rather than running concurrent raw migration commands.

After migration review and a fresh encrypted backup, run the updated deploy script with an explicit reviewed revision and `--migrate`:

```bash
bash deploy/macos/deploy.sh <reviewed-revision> --migrate
unset MCP_MIGRATION_CREDENTIALS_FILE
```

The script checks pending status without owner access, invokes the one-off owner process only for explicitly requested pending migrations, and stops on status/migration errors before config optimization or worker/FPM restart. Successful deployment rebuilds runtime-only cached configuration with private permissions and restarts services on `mcp_trading_runtime`. The helper supports only forward migrations; it does not expose reset/fresh/rollback/seeding operations. PostgreSQL normally rolls back a failing transactional migration; earlier successful migrations or migrations explicitly opting out of transactions require reviewed recovery, not blind retries. Always inspect an explicit deployment revision. Production owner authentication is verified below; actual schema-changing deployment through this workflow remains unverified because no migration was pending.

October 17 deployed verification supersedes the setup limitations above: the operator authorized setup and deployment. `~/.config/investing-ops/migration.json` now exists as an operator-owned `0600` file in a private `0700` directory, outside both checkouts/cloud backups, using the existing app-owned `admin` credentials. Real owner authentication and the deployed helper's `--pretend` succeeded against `postgres` / `mcp_trading`; no migrations were pending. Exact release `401d6a233c54893cfa35529ffadd72a35d7c9e9c` was deployed after a fresh encrypted backup. Runtime environment/key/database configuration and migration history remained unchanged; cached config has no owner connection and is `0600`. Readiness and operations health passed. No production DDL ran; actual production DDL through this helper remains unverified until a reviewed schema change is needed.

The first deployment attempt stopped because its shell could not locate npm, before service reload. Rerunning with the verified installed Node 24.22.0 directory on PATH completed locked installs, frontend build and service reload. For deployment on this Mac, prefix the command with `PATH="$HOME/Library/Application Support/Herd/config/nvm/versions/node/v24.22.0/bin:$PATH"` (update after verifying any future Node installation). This changes only the deploy shell environment, not Herd/global service settings.

### Current M4 acceptance status

**October 18 owner amendment:** M4 is accepted with the seven-session proving period waived, not passed (0/7 completed). M5 development proceeds. The historical drill results below remain valid only for their observed scope; the waiver does not establish continuous availability or permit brokerage writes. Calendar freshness, alerting, backups and recovery checks remain operational requirements.

The [acceptance log](M4-ACCEPTANCE-LOG.md) is the current drill/proving record. Actual missing-heartbeat Down and recovery Up emails were verified October 17 after correcting Healthchecks to one minute plus ten minutes grace. Scheduler and local incident recovered; the temporary recovery guard was removed. Owner-assisted network recovery and OS-confirmed sleep/wake passed. The first reported login return was clarified as lock/unlock; a subsequent confirmed logout produced a new 13:23 CDT console session and automatic restart of all six application services and PostgreSQL. New heartbeat/probes, fresh read-only reconciliation and duplicate protection passed. An initial five-second web readiness timeout recovered on later requests without intervention; retain this startup observation and check it again after reboot. An actual reboot then restored services but exposed FPM worker crashes and HTTP 502; the scoped fix below was installed and a second actual reboot at 13:58:38 CDT passed cold web readiness, automatic services, scheduled heartbeat, fresh correlated worker probes and fresh read/replay without manual repair. The observed Mac recovery drills now pass; overnight/lid-closed continuity remains unproven. User LaunchAgents do not promise operation while logged out or before login/unlock.

An explicit [NYSE session manifest](calendars/nyse-2026-10-proving.json) now supplies seven candidate proving dates, using verified primary-source hours and actual retrieval time. It expires October 24 at 17:41:21 UTC. Re-fetch and review the exchange calendar and hours before refreshing the stored rows for October 26–27; never extend timestamps without source verification. No automatic provider or weekday fallback was introduced. The earliest candidate window is recorded, but zero completed proving sessions are claimed. That original acceptance window was waived October 18. Material reliability defects still require repair and verification.

iCloud web sign-in failed for the operator. The alternative independent-device recovery passed October 17 at 14:04:55 CDT: the owner downloaded the encrypted dump and manifest through iPhone Files, returned them to Downloads, and those exact bytes matched the recorded original hash and passed an isolated 54-table / 105-row restore with encrypted fields readable and restored execution policy disabled. Scratch database/plaintext cleanup passed. This verifies that pair, not future sync success. Do not treat the Mac's local sync folder as remote proof. After correcting the APP_KEY source from development to production, the owner explicitly confirmed both keys were accessible through their password manager from their phone. This closes the setup gate as owner-confirmed access; an independent vault-export restore was not performed. Keep keys outside the cloud backup folder and outside chat. The seven-session gate was subsequently waived by the owner October 18; no completed session is claimed.


### Dedicated FPM PostgreSQL startup setting

This Mac's post-reboot FPM workers crashed in the macOS Kerberos/CoreFoundation path while libpq opened a PostgreSQL connection. The dedicated pool template and installed `~/Services/investing-ops/runtime/php-fpm.conf` now contain `env[PGGSSENCMODE] = disable`. Local application connections do not use GSS authentication. Preserve this app-only setting on reinstall; it disables unused GSS negotiation and leaves TLS/password configuration unchanged. Do not disable macOS fork safety or change other PHP pools. The private pre-fix config is `runtime/php-fpm.before-gss-fix.conf`; reverting would reintroduce the known startup risk. Runtime app code stays at `401d6a2`, with this rendered-config overlay installed separately; commit/review the source template and regression test before the next installer release.

Validate with `/opt/homebrew/opt/php@8.5/sbin/php-fpm -t --fpm-config "$HOME/Services/investing-ops/runtime/php-fpm.conf"`. After a future intentional pool change, restart only `launchctl kickstart -k gui/$(id -u)/local.investing-ops.php-fpm` and request `http://127.0.0.1:8080/ready`; inspect the dedicated FPM/Nginx logs for new failures. `mcp:health` alone cannot verify web readiness. Current repair passed three fresh masters and 24 HTTP requests without new crashes, plus the guarded native integration test. **Actual reboot acceptance with this setting passed October 17**: cold readiness 200 in 1.716498 seconds, five follow-ups 200, zero new FPM crashes, automatic scheduled heartbeat, three fresh correlated queue acknowledgements and scoped read/replay. No installed service was manually restarted/repaired. On future releases, inspect automatic startup before intervening and repeat these checks; `mcp:health` alone is insufficient. This repair does not establish pre-login or logged-out availability.


### Historical M4 proving procedure — waived October 18

The owner waived this waiting/review gate on October 18 and directed M5 work to continue. The October 17 preparation below is retained as history, not a current requirement to wait for seven sessions. Ongoing service monitoring and calendar refresh are still required independently of that waiver.

October 17 preflight passed with existing read-only monitoring running, restricted runtime login, 14 complete runs/snapshots and no duplicate/missing complete snapshots or failed jobs. No new service or execution mode was activated. The original [actual-session review procedure](M4-ACCEPTANCE-LOG.md#how-to-finish-the-remaining-acceptance-period) required seven reviewed sessions; that requirement is now waived, with zero completed sessions claimed. Candidate October 19–27 sessions are 08:30–15:00 Chicago. Keep this login-based installation on AC power, awake and connected during those sessions; existing AC idle sleep is 0, but sleep/logout/disconnection can still interrupt monitoring.

The original procedure required reconciliation after each session of expected monitoring keys, complete snapshots, gaps, source unknowns, latency, incidents/recovery and costs. Those seven acceptance reviews are now waived. Historical uptime still cannot be inferred from only the current component status. Reverify the exchange publications and refresh explicit calendar rows before October 26; coverage currently expires October 24 at 12:41:21 Chicago. No automatic calendar refresh or assistant review/reminder was installed. M5 proceeds under the explicit owner waiver, not a claim of seven passing sessions.
