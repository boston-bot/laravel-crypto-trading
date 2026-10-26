# MacBook setup and operations guide

October 25 source-only scheduler follow-up: the scheduler LaunchAgent template now uses wildcard `StartCalendarInterval` for each wall-clock minute. The existing `StartInterval=60` runtime agent drifted across two due minutes on October 23. A normal application deployment does not update the installed plist: preserve/back up its dedicated PHP/environment/log paths, replace only the interval key, validate with `plutil -lint`, and reload only `local.investing-ops.scheduler` when deploying this increment. Avoid a full installer rerun, which can overwrite LAN/Nginx configuration. The application also evaluates each recorded symbol before gathering the next while retaining batched quotes. Deployment follow-up: code `3012873` and the scheduler-only calendar-key update are now installed. The previously pending, separately owner-approved migration `000029` applied in batch 14 after a verified encrypted backup/restore drill; runtime flags and the paper ledger are unchanged. Market-session verification remains pending. [Evidence, checks and remaining acceptance](M5-SYMBOL-EVALUATION-AND-SCHEDULER-EVIDENCE.md).

Current M5 application update, October 18: normal authenticated browser access now works. Owner-approved mandate v1 / policy revision 1 / execution v2 are applied, with exactly one $500 virtual opening balance and the reviewed 44-symbol snapshot. Read-only verification confirms $0 reserved, zero positions/orders and all AI/paper/live gates off. This supersedes earlier empty-M5/browser-blocker checkpoints below. The subsequent fresh encrypted backup passed an isolated 66-table / 434-row restore, including exact restored/decrypted M5 values, the $500 ledger and scratch/plaintext cleanup. The new encrypted iCloud copy and manifest match locally; independent-device retrieval of this pair remains unverified. The added M5 assertions were one-off, not a recurring helper change. [Evidence](M5-DEPLOYMENT-EVIDENCE.md#applied-owner-settings-verification).

Current collection deployment, October 18: runtime source **`0aa7478`** now has migration `000015` (67 tables) and enabled read-only input collection. There are 47 observations across all 44 symbols; 14 base quote reads remain stale/unavailable. Latest supplemental inputs report 20 dividend events. AI, handoff, orders/fills, action accounting, live writes and legacy schedules remain disabled. $500 virtual cash is unchanged. Populated restore verified 67 tables / 502 rows and all 47 encrypted/decrypted market inputs. [Current evidence, cadence and remaining gaps](M5-COLLECTION-DEPLOYMENT-EVIDENCE.md). This supersedes earlier disabled-collection and 66-table checkpoints below.

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

### October 21 PHP security runtime

The initial October 21 emergency patch moved the investing application's PHP-FPM, scheduler and four queue workers to a separate **PHP 8.5.12** source build through `~/Services/investing-ops/php/current`, then a symlink to `8.5.12`. That build has since been replaced by the [Herd-managed investing runtime](#herd-managed-investing-runtime) below. This fixes [CVE-2026-109618 / CVE-2026-109619](https://www.php.net/archive/2026.php#2026-10-21-5). The owner's `~/Downloads/php-8.5.12.tar.gz` matched SHA-256 `81a0f450349e84b08eb43faa85f8a4c835684f3cc1c50471775d1317a046c525` in [PHP's official release metadata](https://www.php.net/releases/index.php?json&version=8.5.12). At source-build time, Homebrew's feed still offered 8.5.11 and Herd still offered 8.5.10. Neither vendor installation was overwritten by that build. Herd's PHP server was stopped with owner approval, and the owner stopped the separate root Homebrew PHP service. Keep those older servers stopped until their vendors provide a fixed version.

The custom build preserves the former runtime's loaded extension set, using the installed Homebrew libraries, a separate ini/configuration directory, and the existing investing FPM pool/socket. Apache module, PEAR and DTrace builds were omitted; they are not used by this application. Build prerequisites `pkgconf`, `bison` and `re2c` were installed. Explicit Homebrew LDAP/tidy library paths corrected the first link failure. Configuration/plist backups, official release metadata, build/activation scripts and verification logs are retained privately in `~/Services/investing-ops/runtime/php-security-2026-10-21`. This is an operator-maintained build; keep its linked Homebrew dependencies available until transitioning back to a vendor runtime.

Later on October 21, Herd offered its 8.5 update. CLI/FPM and bare terminal `php` now verify as **8.5.12** after `herd php:update 8.5 --no-interaction`. Its installed 8.6 option is **8.6.0RC3**, a preview that was not selected for this app. Herd PHP was stopped again after that update, including the 8.6 pool; the updater had restarted pools, so the stop request was reapplied. The subsequent owner-requested development restoration is recorded below. At that checkpoint the investing app still used the separate source runtime; the later consolidation below changed the `current` target to Herd adapters. The stable command path remains:

```bash
cd ~/Services/investing-ops/app
~/Services/investing-ops/php/current/bin/php -v
~/Services/investing-ops/php/current/bin/php artisan mcp:doctor
```

The development copies of `deploy/macos/install.sh` and `deploy/macos/deploy.sh` now prefer `$INVESTING_OPS_HOME/php/current` when present, while retaining explicit `PHP_BIN` / `PHP_FPM_BIN` overrides and the prior Homebrew fallback otherwise. A broken `current` symlink does not silently fall back. Runtime release `3f60571` includes this runtime-path preference. The explicit override also works with an older deployed deployment script:

```bash
PHP_BIN="$HOME/Services/investing-ops/php/current/bin/php" \
  deploy/macos/deploy.sh <reviewed-commit>
```

Before any service reinstall with the old deployed installer, also provide `PHP_FPM_BIN="$HOME/Services/investing-ops/php/current/sbin/php-fpm"`. Do not reinstall services merely to change PHP: the installer also renders the application pool/Nginx configuration. The October 21 switch preserved those configurations and application flags, stopped scheduled dispatches, paused/drained queue work, then reloaded the six PHP LaunchAgents with the new binary and PATH. `launchctl bootout` can complete asynchronously; bounded bootstrap retries handled that race. Nginx was left running on the same private FPM socket.

Verification passed on PHP 8.5.12: Composer validation and development/runtime platform requirements; PostgreSQL PHPUnit **610 tests / 4,492 assertions**, including actual FPM cold starts/recovery against `mcp_trading_test`; and **21/21** upstream OOM/request-parsing/session-upload tests. A temporary private FastCGI probe verified the serving FPM version/ini and was removed afterward. `/ready` returned HTTP 200; doctor confirmed the existing database/schema. No migrations, backfills, frontend changes, new strategy activation or brokerage mutations were performed.

When official packages provide PHP 8.5.12 or a later fixed release, update and verify each vendor's actual binary before using it. Validate extensions, ini, the FPM pool and the isolated application tests; then pause/drain workers and deliberately switch the LaunchAgent executable/PATH settings, reload services and verify the serving FPM version and readiness again. Change/remove `php/current` only as part of that verified transition. The backed-up plists select vulnerable 8.5.11: they are historical recovery evidence, not a safe rollback target. Restart Herd PHP only after its own binary is patched.

### Herd development restoration

On October 21 the owner requested Herd again. Its default is now patched PHP **8.5.12**. The development checkout is linked as `http://laravel-crypto-trading.test` and isolated to PHP 8.5; `.env` APP_URL was updated by `herd link`. Development still uses `mcp_trading_development` / `mcp_trading`. Composer validation/platform checks passed. At this development-restoration checkpoint the investing app on port 8080 still used its separate source runtime; the later approved consolidation below switched its binaries to Herd.

The former root Homebrew Nginx/DNS services initially conflicted with Herd, producing development **HTTP 502**. The owner stopped those legacy services using these Terminal commands (administrator password required):

```bash
sudo /opt/homebrew/bin/brew services stop nginx
sudo /opt/homebrew/bin/brew services stop dnsmasq
```

Herd’s Nginx/DNS services were then started. Verification passed: `herd status` reports Nginx, DNSMasq and FPM-8.5 active with a healthy socket, development `/login` returns HTTP 200, and investing `/ready` returns HTTP 200. Those stopped Homebrew services are separate from `local.investing-ops.nginx`, which remains running. No production-runtime switch or trading activation occurred. The owner then approved using Herd’s patched PHP binaries for the existing independently supervised investing services; that completed switch is documented below.

### Herd-managed investing runtime

The owner-approved October 21 consolidation is complete. **Herd PHP 8.5.12 supplies the binaries for both development and the investing runtime.** The investing FPM pool, scheduler, four workers and Nginx retain their existing macOS LaunchAgents, independent of Herd’s development-site services. Stopping or starting the Herd development web server does not manage these investing LaunchAgents; keep both application configurations separate.

`~/Services/investing-ops/php/current` now points to `herd-8.5`. Its `bin/php` and `sbin/php-fpm` are small shell adapters that export the dedicated application `PHPRC` / `PHP_INI_SCAN_DIR`, then execute `~/Library/Application Support/Herd/bin/php85` or `php85-fpm`. They pin the stable 8.5 family and use the actual vendor-maintained binaries, so subsequent Herd 8.5 patch updates require no new source build. The separate ini/scan directory preserves application settings without importing development tooling. The old explicit extension-directory override was removed to use the vendor default. No binaries were copied into the adapters. Do not point the investing app at the installed 8.6 RC preview.

The six PHP LaunchAgent command paths and PATH continue to select `php/current`. Application `.env`, FPM pool/socket, database, credentials and trading settings were unchanged; release `3f60571` stayed deployed. Scheduled launches were disabled briefly to allow any running collection to finish, queue workers paused/drained, `current` replaced atomically, PHP services reloaded and scheduling re-enabled. Bootstrap retries account for asynchronous launchd unloads. The source-built patched `php/8.5.12` is retained inactive as a recovery option.

Verification on Herd’s PHP passed Composer validation and all development/runtime platform requirements; **610 PostgreSQL PHPUnit tests / 4,493 assertions**, including actual Herd FPM cold starts, worker recycling and authentication-failure recovery; live socket binary/version/ini checks; and a graceful research-worker termination followed by automatic launchd recovery. Investing `/ready` and `/login`, and development `/login`, all returned HTTP 200. No migrations, backfills, frontend build or application release was needed. Private evidence, adapters’ configuration, prior plists/environment, process records and logs are under `runtime/php-herd-2026-10-21` (the adapters themselves live in `php/herd-8.5`).

For later Herd PHP patch updates:

1. Run `herd php:update 8.5` and verify both Herd binaries report the intended patched version. Keep the investing app pinned to the stable 8.5 family.
2. From the development checkout, run Composer validation/platform checks and the isolated PostgreSQL suite. The explicit FPM override ensures the cold-start test actually runs with Herd’s differently named binary:

   ```bash
   MCP_TEST_PHP_FPM_BIN="$HOME/Services/investing-ops/php/herd-8.5/sbin/php-fpm" \
     "$HOME/Services/investing-ops/php/herd-8.5/bin/php" \
     vendor/bin/phpunit -c phpunit.pgsql.xml
   ```

3. Drain scheduled/queued work and reload the independently supervised investing FPM and workers. Herd’s update/restart controls manage its development pools; the investing LaunchAgents need their own reload to replace running processes. Preserve the app’s flags, pool and environment, and verify scheduling is enabled afterward.
4. Verify the actual serving FPM binary/version and ini, worker executables, doctor, readiness and development access. Restart/recovery scripts and previous source-runtime backups are retained in the private evidence directory; use only a verified patched runtime for recovery.

The FPM integration test supports `MCP_TEST_PHP_FPM_BIN` for this layout. An explicit non-executable path fails rather than silently skipping, and the selected FPM version must match the PHP CLI running the suite.

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

**Superseded October 18:** no paid LLM API runs in this application ([AGENTS.md](../../AGENTS.md#project-direction)). Keep `MCP_RESEARCH_ENABLED`, `MCP_PAPER_DECISIONS_ENABLED` and `MCP_PAPER_HANDOFF_ENABLED` off. An existing `MCP_RESEARCH_API_KEY` goes unused while they stay off. The rest of this paragraph describes the disabled M3 component.

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
COINBASE_WRITES_ENABLED=false
ROBINHOOD_CRYPTO_WRITES_ENABLED=false
```

The two crypto venue flags (added October 22, crypto C0) gate the legacy Coinbase and Robinhood Crypto clients independently of `BROKERAGE_WRITES_ENABLED`. Keep them false: no application code currently submits crypto orders, and legacy live submission is retired. To check a configured Coinbase key's own permissions without changing anything, run `php artisan broker:key-permissions`; a view-only key is recommended until an authorized crypto pilot.

Crypto C1 (source only, October 22) adds `CRYPTO_MARKET_DATA_ENABLED` (default false) and `COINBASE_MARKET_DATA_PRODUCTS` for read-only Coinbase public market data, with additive migration `2026_10_22_000028`. Enabling steps and verification: [C1 evidence](CRYPTO-C1-MARKET-DATA-EVIDENCE.md#deployment-owner-action-not-performed).

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

M3 is implemented locally; see [M3 research evidence](M3-RESEARCH-EVIDENCE.md). Research is **disabled** and the Research page reports why. Evidence entry, the local watchlist, thesis authoring and summaries work without AI; `php artisan mcp:summary --kind=daily` (or `weekly`) summarizes the previous closed New York day or week and refuses open periods. Since October 18, do not enable research for continuous operation; strategy analysis runs in ChatGPT outside the app. The steps below are kept for reference. To enable research deliberately, set in the private `.env`: `MCP_RESEARCH_MODEL` (a model your API project can access), a dedicated `MCP_RESEARCH_API_KEY`, reviewed `MCP_RESEARCH_INPUT_COST_PER_MTOK` / `MCP_RESEARCH_OUTPUT_COST_PER_MTOK` prices, `MCP_RESEARCH_FIXED_MONTHLY_COSTS` covering every AI/data subscription (use `0.00` only if there are none) and optionally `MCP_RESEARCH_MONTHLY_ENVELOPE` (default `100.00`, ceiling `150.00`). Configure provider-side limits too; the application ledger cannot guarantee an invoice. Then set `MCP_RESEARCH_ENABLED=true` and try one bounded foreground cycle with `php artisan mcp:research --once --sync` before any worker or schedule.

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

Current watchlist update, October 18: runtime `ROBINHOOD_MCP_WATCHLISTS_ENABLED=true` now permits the two reviewed manual read tools. A deployed CLI check at 18:08:14 Chicago returned all 44 On Watch instrument symbols and exactly matched execution v2's allowlist, without changing it. Cached config/services were refreshed; readiness recovered after a transient restart 502. All AI/paper/live/legacy gates remain off. This supersedes earlier watchlist-disabled checkpoints. [Evidence](M5-DEPLOYMENT-EVIDENCE.md#runtime-read-only-watchlist-activation).

October 18 current deployment: **`b9b514641c6d573bec1713142f92a26c9cb2d540`** supersedes the older release named above. M5 migrations `000010`–`000014` are applied as batch 7 with the verified owner connection. Twelve new tables remain empty; actual owner paper settings are not applied. Runtime research/new paper gates, legacy crypto and brokerage writes remain disabled; monitoring/operations remain enabled. Post-migration encrypted backup passed a 66-table isolated restore. See [deployment evidence](M5-DEPLOYMENT-EVIDENCE.md) for checks and the saved-browser-permission blocker to authenticated settings application.

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

### Read-only paper market collection

The independent `MCP_PAPER_MARKET_COLLECTION_ENABLED` switch and existing `MCP_PAPER_MARKET_INPUTS_ENABLED` gate enable the bounded allowlist collector. Existing monitoring workers handle its read-only jobs; `mcp:paper-collect --once --sync --limit=5` performs one explicit foreground batch. The scheduler selects up to five oldest symbols every five minutes in verified regular sessions, hourly otherwise. This baseline does not meet a 20-second execution freshness requirement. Source-specific Robinhood reference/book and Alpaca gates remain independent. Keep AI/decisions/handoff/fills/action accounting/live/legacy disabled until their separate requirements are met. Disable `MCP_PAPER_MARKET_COLLECTION_ENABLED`, refresh runtime cached configuration and restart workers to stop new collection; stale queued jobs also check the gates. [Current evidence](M5-COLLECTION-DEPLOYMENT-EVIDENCE.md).

### Broker read sessions and rate limits

Broker reads share one MCP session per monitor run, collection job or watchlist read. After a broker 429, all reads for that connection pause locally until `Retry-After` passes (at least 30 seconds), and the collector skips dispatch meanwhile; this shows as `rate_limited`. Token refreshes are serialized with a cache lock, so keep `CACHE_STORE=database` (or another store shared across processes) in the runtime `.env`; the collector lock already depends on it. Broker-read buttons on the connection page share a limit of 10 per minute per user. No new environment settings. [Evidence](M5-BROKER-READ-EFFICIENCY-EVIDENCE.md).

### SEC filing classification (leveraged/inverse)

Requires migration `2026_10_19_000016` (additive table). In the runtime `.env`, set `SEC_EDGAR_USER_AGENT` to a tool name plus an operator contact address of the owner's choosing; SEC rejects undeclared automated tools. No key or subscription is involved. Then enable `SEC_EDGAR_ENABLED`, refresh cached configuration and run `php artisan mcp:instrument-classification --account=<local-id>`. Review every printed row (`basis`, `reason`, `multiple_or_inverse`, report period) before enabling `MCP_PAPER_CLASSIFICATION_ENABLED`. With market collection on, the scheduler refreshes evidence daily at 06:20 New York; stored evidence older than seven days is treated as unknown. Unclassified symbols stay ineligible, for example UIT ETFs, non-1940-Act ETPs and multi-ticker issuers. To stop reads, disable `SEC_EDGAR_ENABLED` and refresh configuration. [Evidence and limits](M5-CLASSIFICATION-AND-ACTION-COVERAGE-EVIDENCE.md).


### Dedicated strategy watchlist intake (M5 source, not deployed)

The authenticated Control Center now saves an account-scoped, immutable version of the dedicated Robinhood list name. Confirm it is separate from personal lists; the existing `On Watch` personal list is refused. Saving does not make any broker call or enable intake. A settings version change requires a new successful read before nominations resume, even when the name is unchanged. The first successful read pins the list identity for that settings version; a same-named replacement is rejected until the owner saves a new version.

Deploy the additive `2026_10_19_000017` migration through the established schema/role checks before using this source. It adds three tables; there is no backfill or runtime migration in the implementation evidence. Keep `MCP_STRATEGY_WATCHLIST_ENABLED=false` until the dedicated name is selected and read-only activation is intended. The existing `ROBINHOOD_MCP_ENABLED` and `ROBINHOOD_MCP_WATCHLISTS_ENABLED` gates also apply. Intake uses the existing Laravel scheduler; no new service is needed.

With those gates enabled, `php artisan mcp:strategy-watchlist --once --account=<local-connection-id>` processes a deduplicated five-minute window. Omitting `--account` covers configured operator connections. Counts describe processed attempts, including failed reads; inspect the scoped read records and incidents for outcomes. A repeated failed window is not retried until the next five-minute window. No raw broker errors or membership symbols are printed by this command.

Successful reads, including unchanged membership, retain encrypted revisions. All items count toward the 75-item cap; non-equity/missing-symbol members are recorded as skipped. Failed reads never advance the last successful retrieval time. At 30 minutes the nomination gate returns no symbols. Empty successful lists immediately remove all nominations. Position exits and risk checks do not depend on this gate. Quiet-list alerts need two complete, fresh verified exchange sessions after the last membership change; missing calendar evidence does not invent trading days. No actual notification delivery or live dedicated-list read has been tested for this increment.

These are nomination controls for the future deterministic engine. Existing execution settings, market collection, AI gates and fill/order paths are unchanged. Full eligibility replacement and detection/review of external account orders remain M5 work. See [source and verification evidence](M5-STRATEGY-WATCHLIST-EVIDENCE.md).


### External equity orders and entry pauses (M5 source, not deployed)

The additive `2026_10_19_000018` migration adds encrypted immutable external-order cases, per-case owner reviews and normalized order-read attempts. Follow the established schema/role checks before deployment; no runtime migration or backfill was performed in this increment. `MCP_EXTERNAL_ORDER_DETECTION_ENABLED=false` is an independent private-config default. Enabling it observes equity orders during existing monitoring; it starts no new service or brokerage mutation. The monitoring scheduler must already be enabled and the read gateway configured. No browser toggle changes this flag.

Before M6, every returned equity order is external because the app has no agentic submission boundary. Initial activation can therefore surface historical completed/cancelled orders returned by the broker as well as pending orders. Review each case in the authenticated Control Center using the order identity, first-observed evidence and a required note. Acknowledging an alert does not release the entry pause. Empty reads, reconnects and disabling detection do not dismiss existing cases. Reviewing the last pending case resolves the external-order incident; any other readiness or policy blocker still applies. Reviewing a case does not cancel the broker order or authorize a future trade.

With detection enabled, missing, partial or five-minute-old order coverage blocks new buys. A partial page still records any observed external orders, and a failed monitoring attempt cannot erase them. The pause suppresses watchlist nominations, rejects real-data paper buys and cancels their remaining reservations before further fills; held-position sells remain available under their existing checks. No live path exists. Future strategy/live activation must require detection enabled and recheck this gate at the execution boundary.

The critical incident uses the existing notification outbox; actual phone delivery was not tested for this feature. Read coverage is limited to the exposed equity-order feed; do not assume coverage for unexposed asset classes or unavailable history. Source timestamps remain unknown where the broker omits them. [Verification and limits](M5-EXTERNAL-ORDER-EVIDENCE.md).


### Deterministic eligibility policy (M5 source, not deployed)

The additive `2026_10_19_000019` migration adds immutable encrypted eligibility policies and per-symbol assessment records. Follow the established schema/role deployment checks. No runtime migration or backfill was performed. `MCP_RULE_ELIGIBILITY_ENABLED=false` independently defaults evaluation off. The Control Center's explicit eligibility-policy application selects the watchlist universe; it changes no operating flag, existing cash, costs, risk settings or execution-version payload. Listing, 252-session history and 20-session dollar volume now come from the market reference reads below; without them those checks stay unavailable.

Once a rule policy is selected, disabling evaluation cannot restore the old allowlist as entry authority. Data collection/readiness/classification use current watchlist members plus held symbols; held symbols remain in the data universe even when the watchlist expires or evaluation is disabled. The current five-symbol collector limit remains unchanged. No new recurring service or full-watchlist quote-cadence guarantee is added.

`php artisan mcp:eligibility --account=<local-connection-id>` records the selected data universe from local observations. Optional `--symbol`, `--request` and `--cutoff` select a symbol or stable logical assessment. The Control Center's assessment action also pins its cutoff for safe retries. Both actions are local database work only. Assessments record rule/parameter-policy/execution versions, source references, per-check reasons and fingerprints. Current safety guards still apply; this is not historical strategy backtesting.

Old broker inputs without the new authorization-generation metadata cannot satisfy rule provenance; collect fresh observations rather than backfilling a guessed generation. The paper boundary rejects unavailable/ineligible buys, releases invalidated buy reservations and retains held-position sells independently of entry membership/history. Selecting this mode blocks the superseded model handoff. Full signal-based decisions, realistic fee replacement and fractional sizing remain future work. [Evidence and verification](M5-RULE-ELIGIBILITY-EVIDENCE.md).

### Listing and daily-history reference reads (M5 source, not deployed)

Requires additive migration `2026_10_19_000020` (one table). In the runtime `.env`, keep the existing `ALPACA_DATA_ENABLED`/`ALPACA_KEY`/`ALPACA_SECRET`, set `ALPACA_ACCOUNT_ENVIRONMENT` to `paper` for keys starting `PK` or `live` for keys starting `AK`, then set `ALPACA_REFERENCE_ENABLED=true` when ready. Try one bounded foreground read with `php artisan mcp:market-reference --account=<local-connection-id> --symbol=<one symbol>`. With `MCP_PAPER_MARKET_COLLECTION_ENABLED=true`, the scheduler refreshes every symbol in the selected universe daily at 06:40 New York; history older than 30 hours becomes unknown, so a missed refresh shows as `daily_history_stale` rather than silently reusing old data. Reads are market data only: no Robinhood call, account, position or order request. [Evidence](M5-MARKET-REFERENCE-EVIDENCE.md).

### Market calendar and daily symbol profiles (M5 source, not deployed)

Requires additive migration `2026_10_19_000021` (one table). `ALPACA_CALENDAR_ENABLED=true` (with the existing Alpaca settings) makes the scheduler refresh XNYS sessions daily at 06:10 New York; try `php artisan mcp:market-calendar` once first. It exits non-zero and lists dates when a stored session from another source, such as the hand-loaded manifest, disagrees; those dates are left to expire rather than overwritten. `MCP_SYMBOL_PROFILES_ENABLED=true` records daily profiles at 06:50 from stored history only; `php artisan mcp:symbol-profiles --account=<local-connection-id>` shows each symbol's setup, reason, blockers and levels. Every profile shows `earnings_date_unknown` until an earnings source is chosen, so no paper entry can follow from it yet. [Evidence](M5-CALENDAR-AND-PROFILES-EVIDENCE.md).

### Earnings calendar (M5 source, not deployed)

Requires additive migration `2026_10_19_000022` (one table). Set `ROBINHOOD_MCP_EARNINGS_ENABLED=true` in the runtime `.env`, then try `php artisan mcp:earnings-calendar --account=<local-connection-id>` once; it reads Robinhood's market-wide earnings calendar for the next 31 days through the existing connection (read-only). With `MCP_PAPER_MARKET_COLLECTION_ENABLED=true` it refreshes daily at 06:30 New York, before the 06:50 profiles. Profiles then show no earnings blocker, `earnings_blackout` (a report within the next five trading sessions), or `earnings_date_unknown` when the calendar or session data is missing or stale. The narrow permission rule added for the catalog review can be removed. [Evidence](M5-EARNINGS-EVIDENCE.md).


### Deterministic five-minute signal evaluations (M5 source, not deployed)

Migration `2026_10_19_000023` adds two evidence tables; no backfill. After a reviewed deployment and additive migration, `MCP_STRATEGY_SIGNALS_ENABLED=true` enables stored-data evaluation every five minutes. Try `php artisan mcp:strategy-cycle --once --account=<local-connection-id>` for one bounded foreground run. It records signals/waits/unavailable reasons and exposes them in the authenticated Control Center. It makes no provider requests or orders. Keep the superseded AI decision runner and paper/live execution gates disabled. Fresh data must already be collected; the existing bounded collector does not establish timely full-universe coverage. A retry within the same five-minute slot returns the first immutable outcome set, so wait for the next slot after refreshing data. [Signal behavior, deployment requirements and limits](M5-STRATEGY-SIGNALS-EVIDENCE.md).


### Signal paper risk, costs and combined cycle (M5 source, not deployed)

After reviewing/applying migrations `000023` and `000024` through the separate owner connection, review the new **Signal paper risk and costs** form in the authenticated Control Center. Its explicit application binds the current allocation/execution settings and snapshots the dated fee schedule; it changes no balance or service flag. Whole shares are required until fractional LIMIT permission is verified. Settings cannot exceed 1% planned risk, 20% maximum position or eight occupied positions, and existing cash floors/sleeve budgets still apply.

`MCP_SIGNAL_PAPER_HANDOFF_ENABLED=false` defaults off. The combined `php artisan mcp:signal-paper-cycle --once --account=<local-id>` loop requires that flag plus `MCP_STRATEGY_SIGNALS_ENABLED` and `MCP_PAPER_REAL_DATA_FILLS_ENABLED`; it processes existing paper orders first and creates new intents afterward. When all are enabled, Laravel schedules it every five minutes and suppresses the standalone signal schedule. It does not collect quotes, submit brokerage orders or activate legacy schedules/AI. Keep live brokerage writes and the superseded model handoff disabled. A current entry signal also needs an owner-applied signal policy and complete fresh observations; enabling flags cannot fill those gaps.

Migration `000024` creates two tables and widens four paper timestamps without fabricating precision in old records. The fee schedule expires December 31, 2026; do not reuse it for January without a new review. New-day entries with held positions still need a future verified opening-valuation source; exits remain independent. Full-universe quote cadence, fractional support, replay and forward-evaluation activation are outstanding. [Verification, cost sources and limits](M5-SIGNAL-PAPER-HANDOFF-EVIDENCE.md).


## Opening valuations and batched collection (source, pending deployment)

October 19: the owner deployed `80225da`; read-only checks confirmed migrations `000023`/`000024`, healthy `/ready` and doctor, $500 cash/no paper positions, no applied signal execution policy and all signal/paper execution/AI/live-write gates off. This supersedes the pending-deployment statements in the preceding signal sections. No runtime setting was changed during this follow-up.

The next source release adds additive migration `2026_10_19_000025_create_mcp_paper_session_valuations.php` (one encrypted evidence table, no backfill). Use the existing separate schema-owner migration/deployment procedure. Its two independent flags default false:

```dotenv
MCP_PAPER_BATCH_COLLECTION_ENABLED=false
MCP_PAPER_SESSION_VALUATIONS_ENABLED=false
```

Once deployed and deliberately configured, batch collection also requires the existing market collection/input flags. `php artisan mcp:market-cycle --once --account=<local-connection-id>` makes read-only provider requests, processes holdings first, batches quotes and reuses a session for exact-symbol evidence. Counts include observations reused on a same-window retry. The scheduler selects this command instead of the old five-symbol collector when the batch flag is enabled; old queued collection jobs skip. It uses five-minute regular-session/hourly off-session cadence, a 240-second processing budget and broker backoff. One in-flight request can finish after the deadline. No signal, fill or order is created by this command.

With opening valuations enabled, the batch collector and combined paper cycle attempt capture before fills. A held paper portfolio needs stored bid source/retrieval times inside the first minute of the verified exchange session, current account authorization, clear observed actions and unchanged accounting since the open. Evidence may be captured later only if the ledger has stayed unchanged. It values paper cash plus held shares at those bids; this is an opening-minute liquidation reference, not an official auction price. Missing/late evidence blocks new-day buys. Previously captured evidence stays immutable after trades. Cash-only inception and verified same-session baselines remain supported; exits do not need an entry baseline.

Keep paper fills/handoff and AI/live execution disabled while cadence, fractional LIMIT support, replay and forward paper readiness remain unverified. Enabling collection or valuations does not enable those paths. [Detailed evidence and limitations](M5-OPENING-AND-BATCH-COLLECTION-EVIDENCE.md).

### Replay and cadence reports (M5 source, not deployed)

Requires additive migration `2026_10_19_000026` (one table). `php artisan mcp:strategy-replay --account=<local-connection-id> [--years=3] [--equity=500]` records a retrospective daily replay of the live rules over Alpaca's split-adjusted history for today's nominating watchlist symbols; it needs the Alpaca reference reads enabled, takes about a minute for 25 symbols, and never changes orders, the paper ledger or settings. Results are labeled retrospective and are not forward performance. `php artisan mcp:cadence-report --account=<local-connection-id> [--date=YYYY-MM-DD]` measures a regular session's collection, quote age and strategy-cycle cadence from stored records (read-only). Robinhood's agentic order contract allows fractional quantities only on regular-hours market orders, so new execution settings cannot request fractional limit orders. [Evidence](M5-REPLAY-FRACTIONAL-CADENCE-EVIDENCE.md).

Research variants (source, October 21): add up to six `--variant=id:key=value,key=value` options. Each replays the same downloaded history beside the live-parameter baseline, and the record stores every variant's settings and result. Rule keys: `initial_stop_atr`, `trailing_stop_atr`, `max_chase_atr`, `time_exit_sessions`, `cooldown_sessions`, `market_filter_sma_sessions` (50–200), `exit_on_fast_sma_close` and `market_filter_required` (`true`/`false`). Order keys: `order_mode=fractional_market` and optional `market_slippage_bps` (default 5, 1–50). Research risk keys: `risk_per_trade_pct` (0.25–3), `max_position_pct` (5–50), `max_open_positions` (1–8). Example: `php artisan mcp:strategy-replay --account=1 --variant=fractional:order_mode=fractional_market --variant=no_sma_exit:exit_on_fast_sma_close=false`. Expect about half a minute per variant. Variants are research only; they never change live parameters, owner settings or the paper ledger. The Experiments page accepts the same rule keys (not the order mode). Deploying this source changes the frozen experiment source hashes. The deploy script's queue restart also restarts the experiment worker. Completed experiment reports stay readable, but an unfinished experiment pauses with `authorization_gate_or_source_changed` and must be started again; deploy when no experiment is running. [Evidence](M5-RESEARCH-VARIANTS-EVIDENCE.md).


### October 20 deployment recovery

The owner created encrypted backup `mcp-trading-20261021T021151Z.dump.enc` (the filename uses UTC), then attempted deployment with `--migrate`. The command stopped with `000025`/`000026` still pending. The private migration file had correct owner/permissions, and a dry run with its path explicitly supplied succeeded; the pasted command omitted the credential-path export. The failed attempt had already checked out the new release, but had not restarted services or applied either migration.

Deployment was completed at exact release `51392ea` using a command-scoped path:

```bash
cd "$HOME/Services/investing-ops/app"
MCP_MIGRATION_CREDENTIALS_FILE="$HOME/.config/investing-ops/migration.json" \
  bash deploy/macos/deploy.sh 51392ea --migrate
```

Both additive migrations completed as batch 11. Doctor, `/ready`, empty pending-migration list and reads of the two new empty tables as `mcp_trading_runtime` passed in `postgres` / `mcp_trading`. No backfill or policy/configuration change occurred. Batch collection, opening valuations, signals, paper fills/handoff, AI and brokerage writes stayed disabled. No owner credential was persisted or printed. The inline variable applies only to that command; no later `unset` is necessary. Next: deliberate read-only collection activation and cadence measurement, separately from paper execution.


### October 20 read-only batch activation

Runtime remains on deployed `51392ea`, with migrations `000025`/`000026` applied. At the owner's request, only `MCP_PAPER_BATCH_COLLECTION_ENABLED=true` and `MCP_PAPER_SESSION_VALUATIONS_ENABLED=true` were changed in the runtime's private `.env`. The prior file was preserved in a private `0700` directory outside the checkout, with a `0600` backup. Configuration was recached, queue workers signaled to restart, and PHP-FPM restarted. The checked-in `.env.example` remains default-off. Signals, signal handoff, real-data paper fills, AI and brokerage writes stayed false; no owner execution policy was applied.

`schedule:list` selects `mcp:market-cycle --once --scheduled` instead of the old rotating collector; it attempts every five minutes during verified regular sessions and hourly otherwise. An after-close scheduled probe correctly selected zero symbols. `/ready` and doctor passed. Opening valuations cannot establish a new regular-session baseline after the close; the next verified session must supply its opening evidence.

Initial after-close manual measurement (October 20 21:21:43–21:22:32 CDT): 32 symbols selected, 32 new observations, 48.874 seconds, no budget/backoff stop. All base observations were incomplete. A read-only quote-batch diagnostic established `stale_data` for the mixed batches; no stale threshold was relaxed and no executable quote was invented. Direct single/two-symbol CRM/MSFT reads returned complete responses, which does not make the larger batches fresh. This is attempt throughput, not 32 usable quote reads or a regular-session freshness pass. $500 cash, $0 reserved, zero paper orders/positions and ledger version 1 were unchanged.

A second manual sample at 21:26:52–21:27:47 CDT again selected/recorded 32 symbols, taking 54.807 seconds, with no backoff/budget stop and zero complete base quote observations. Start-to-start spacing was 308.527 seconds. Across the 32 common symbols, record-to-record intervals were median 311.043 seconds / p95 314.227 seconds / maximum 314.461 seconds. These intervals were produced by two operator-initiated runs; the off-session scheduler itself remains hourly. Both preserved paper accounting and all execution gates. `comparison.json` records these measurements; `quote-diagnostic.json` records the separate stale-batch diagnostic. No opening valuation was created after close. The stored calendar confirms October 21 regular hours as 08:30–15:00 Central.

The last completed regular-session baseline, October 20 under the old collector: 365 observations across 32 symbols, 73/78 slots (93.6%), per-symbol refresh median 34.287 minutes / p95 40.434 minutes, quote age at retrieval median 4.241 seconds / p95 9.216 seconds. Strategy evaluation was disabled throughout; no quote-age-at-evaluation result exists. Private measurement artifacts are in runtime `storage/app/private/cadence/activation-2026-10-20/`.

A full regular session with the batch collector is still required. After the October 21 session, inspect:

```bash
cd "$HOME/Services/investing-ops/app"
/opt/homebrew/opt/php@8.5/bin/php artisan mcp:cadence-report --account=1 --date=2026-10-21
```

Use its per-symbol intervals, slot spans and quote ages, plus observation completeness, to assess coverage. Do not count incomplete observations as usable data, or these manually initiated off-session samples as proof of unattended regular-session cadence. No paper execution is enabled by this activation.


### Manual historical quote trial (source only)

The new `mcp:intraday-replay` mode is not deployed in runtime `51392ea`. It uses the existing encrypted replay archive (`000026`); no new migration is needed. Its separate `MCP_HISTORICAL_QUOTE_REPLAY_ENABLED=false` flag defaults off. After deploying the source, deliberately configure that flag for manual research and refresh cached configuration through the normal operator procedure. The existing Alpaca data/reference/calendar flags and app-owned credentials must be configured. No scheduler is added. Changing this flag does not enable forward paper fills or broker writes.

```bash
php artisan mcp:intraday-replay --account=1 --date=2026-10-20 \
  --symbol=CRM --symbol=MSFT --equity=500 --minutes=390
```

`--request=<uuid>` provides duplicate-safe archival. Dates must be completed sessions covered by the reviewed cost schedule; one or two explicit symbols and 20–390 minutes are supported. Invalid/missing historical access yields an incomplete record without partial performance. The archive's encrypted payload includes the full normalized snapshot and research limitations. It is separate from forward paper orders, cash and positions. A shorter window keeps outstanding DAY orders pending; a complete session expires remaining orders at the close.

The first actual full-session trial used the development app's own data access and wrote private local artifacts only, leaving runtime settings/ledger unchanged. At $500 no order could be sized; a $10,000 comparison produced one fill and ended with one open position. This is one-session research with historical earnings/eligibility assumptions, not point-in-time walk-forward or performance acceptance. [Evidence, reproduction and exact limitations](M5-INTRADAY-REPLAY-EVIDENCE.md).

For two to five consecutive **full** sessions, use repeated `--session` instead of `--date`, in chronological order, with `--minutes=390` (verified early closes are honored):

```bash
php artisan mcp:intraday-replay --account=1 \
  --session=2026-10-19 --session=2026-10-20 \
  --symbol=CRM --symbol=MSFT --equity=500 --minutes=390
```

This selects `intraday_quotes_multisession_v1` and carries cash/positions across closes, while expiring each session's DAY remainder. The range must contain every intervening trading session. A single incomplete acquisition or unsupported overnight action produces no partial performance. Per-session opening marks are required for new entries; exits continue when an opening baseline is unavailable. UUID retries bind the same dates/mode and reuse the encrypted `postgres` / `mcp_trading` / `mcp_strategy_replays` record. A run may acquire up to five bounded sessions; it does not start a continuous loop.

Use the [strategy experiment register](STRATEGY-EXPERIMENTS.md) to find each hypothesis, exact comparison, source/input versions, result and interpretation, including inconclusive and superseded results. [Multi-session evidence and actual two-session results](M5-MULTISESSION-REPLAY-EVIDENCE.md) remain source-only. No actual overnight holding or completed trade occurred in that small real sample; automated tests cover carry and later exits. Historical earnings/watchlist/account assumptions still prevent a point-in-time claim.

### Durable historical experiments (source only)

[Runner design, recovery and evidence](M5-EXPERIMENT-RUNNER-EVIDENCE.md). Migration `2026_10_20_000027` adds two encrypted research tables, with no backfill. Runtime database/schema remain `postgres` / `mcp_trading`. This source is not deployed or enabled. Use the normal encrypted backup, explicit-owner migration review and `deploy.sh <committed-release> --migrate` procedure before using the commands.

After deployment, configure `MCP_STRATEGY_EXPERIMENTS_ENABLED=true` and `MCP_HISTORICAL_QUOTE_REPLAY_ENABLED=true` through the normal private-environment/config-cache procedure. Existing Alpaca data/reference/calendar access and operator/account configuration must also be valid. These flags do not activate signals, forward paper fills, AI decisions or brokerage writes.

The updated `deploy/macos/install.sh` installs the separate `local.investing-ops.experiments` LaunchAgent (alongside existing agents). Review/apply it through the normal install procedure after migration/configuration. It uses the `mcp_experiments` database connection and `experiments` queue. For a foreground worker during setup:

```bash
/opt/homebrew/opt/php@8.5/bin/php artisan queue:work mcp_experiments \
  --queue=experiments --sleep=3 --tries=5 --timeout=300 --max-time=3600
```

Launchd restarts the installed worker after its hourly recycle or an exit. An experiment continues independently of a ChatGPT/Codex conversation while the Mac and worker are running. Deployments signal queue restarts; source changes require a worker restart before new definitions can run. Check `experiments.out.log` / `experiments.err.log` in the private runtime logs directory. No worker/service was installed by the source implementation itself.

Start from the checked-in example or a reviewed JSON specification:

```bash
php artisan mcp:experiment start --account=1 \
  --spec=docs/mcp-trading/examples/strategy-experiment.json
```

The response contains `experiment_id`, `request_id`, status and the frozen definition hash; processing is queued, not performed by this prompt. To retry the same submission without creating another experiment, supply its original `--request=<uuid>` and unchanged specification.

```bash
php artisan mcp:experiment report --account=1 --experiment=<experiment-uuid>
php artisan mcp:experiment resume --account=1 --experiment=<experiment-uuid>
```

`report` is read-only and returns JSON: progress, reasons, failed attempts, completed variant metrics and the final comparison when complete. `resume` is explicit recovery after failures/expired leases; it retains all evidence and grants another bounded attempt budget. It refuses an active lease or changed frozen code/definition. If a step is paused by disabled gates, restore the intended research configuration before resuming. An authorization/binding change requires a new authorized experiment rather than silently continuing the old one.

Specifications accept 1–2 symbols, 2–20 consecutive completed sessions and 1–8 variants. Monetary/ATR values must be strings; risk limits, trading permissions and arbitrary code cannot be supplied. Owner cost/allocation/risk settings are snapshotted once. A twenty-session experiment uses up to 29 bounded jobs (20 acquisitions, 8 variants, 1 report); each variant carries positions across the entire window. Current reviewed costs only cover Q4 2026. Nothing continuously repeats or automatically promotes a winning variant.

For a new comparison after changing a parameter or rebuilding rules, add `"dataset_experiment": "<completed-experiment-uuid>"` to a new specification with identical symbols/dates. The application reuses the parent's verified observations, calculates differences against its baseline, and records changed sources. It withholds cross-experiment deltas when capital or cost/risk settings differ. Keep original experiments/releases as evidence; do not modify them.

Example prompt after activation: “Start the historical experiment in `docs/mcp-trading/examples/strategy-experiment.json` and return its ID.” A later prompt: “Read experiment `<uuid>` and explain the calculated results and limitations.” The prompt selects/starts work or interprets output; the application's classes perform the processing.


### October 21 experiment-runner deployment and activation

At approximately 07:05–07:07 Central, completed the owner's deployment of `5b59abf` after the encrypted backup `mcp-trading-20261021T120418Z.dump.enc` and the failed migration attempt. The private operator-owned `0600` credential file and the verified `postgres` / `mcp_trading` schema-owner connection passed explicit-path pretend review. Only migration `2026_10_20_000027` was pending. Deployment with a command-scoped credential path applied it as **batch 12**, recached the release and recovered `/ready` successfully:

```bash
cd "$HOME/Services/investing-ops/app"
MCP_MIGRATION_CREDENTIALS_FILE="$HOME/.config/investing-ops/migration.json" \
  bash deploy/macos/deploy.sh 5b59abf --migrate
```

The pasted failed command did not show the credential-path setting. The inline form supplies it only for that invocation; no later `unset` is needed. No credential contents were printed or stored in Laravel configuration.

Enabled **only** runtime `MCP_STRATEGY_EXPERIMENTS_ENABLED=true` and `MCP_HISTORICAL_QUOTE_REPLAY_ENABLED=true`, preserving the prior private environment in the existing `0700` private-config-backups directory with a `0600` backup. Verified all non-target environment lines stayed identical; refreshed configuration cache, signaled queue restarts and restarted FPM. Existing Alpaca data/reference/calendar and read-only batch/opening-valuation flags remain enabled. AI research/decisions, signals, handoff, real-data fills, legacy trading and brokerage writes remain disabled.

Rendered and installed only `~/Library/LaunchAgents/local.investing-ops.experiments.plist` from the deployed template, then bootstrapped it in the user's GUI domain. Plist validation passed. Launchd reports a running PHP worker on `mcp_experiments` / `experiments`, one start and no exit. Existing monitoring/reconciliation services remain available. The seven-service setup now includes the idle historical experiment worker; this is no completed experiment or endurance claim.

Doctor and restricted-role reads of both new tables passed; no migrations remain pending and `/ready` is healthy. Before/after checks show the same **$500 cash, $0 reserved, ledger version 1, zero paper orders/positions**, zero experiments/steps and an empty experiment queue. No trial, provider/model call, policy application, forward paper fill or brokerage action was started. Private activation evidence is in runtime `runtime/experiment-activation-2026-10-21/` (before/after redacted reports and verifier). No source/dependency/frontend change or full test rerun for activation; the source release's 599-test verification remains the applicable implementation evidence. Next: submit the predefined historical experiment and inspect its UUID report.


### Experiment controls and trade tables (source only)

After deploying this UI increment, sign in and open **Strategy research → Experiments** (`/mcp/experiments`). The previously enabled research flags and experiment worker are still required; the page does not install workers or enable settings. This increment adds no migration beyond the already deployed `000027`.

1. Give the experiment a name and a specific comparison question. Enter 1–2 tickers and 2–20 consecutive completed session dates, separated by commas or whitespace.
2. Define up to eight variants with unique IDs and simulated starting cash. Select one baseline. Optional rule overrides are restricted to the runner's existing five supported parameters; leaving a field blank retains the frozen applied setting. The initial example is editable; it is not automatically started.
3. Select **Queue historical experiment**. The application validates and freezes the specification and queues the existing worker. Closing the page does not stop the work. Refresh results to see completed checkpoints and variants. The JSON report remains available for later prompt-based interpretation.
4. Inspect a variant in the comparison table. A failed or interrupted run offers an explicit resume control when its lease is no longer active. Completed runs offer **New comparison with this dataset**, which prefills the original dates/tickers and source UUID. Submitting creates a new experiment and retains the original; normal frozen-data reuse checks still apply.

The trade table groups simulated fills into position episodes, including partial fills/sales and later reentries. Columns are ticker/state, playbook/quantities, weighted buy price/first buy time, weighted sell price/close time, net P&L/recorded fees, exit plan/latest valid historical mark, and entry/exit reasons. Expand fill records for individual execution prices, quantities, fees and times. A partial sale has its own latest-fill time and leaves the position open. Order intents appear separately: acceptance time and limit bounds are not execution times/prices. Trades and intents paginate independently, twenty rows per page. Times are Central.

There is no fixed take-profit rule, so the page does not invent a projected sell price. Open positions show recorded initial/trailing stop thresholds and the terminal valid historical bid mark. These describe the replay window; stops are triggers rather than guaranteed fill prices. Closed episodes show their actual simulated sale rather than reconstructing an unrecorded final stop. Closed P&L includes recorded fees; open marked P&L includes partial sales and the remaining bid-marked quantity, excludes hypothetical terminal sale fees, and stays unavailable if a valid terminal mark is missing.

History, report and mutation routes require the authenticated financial operator and current bound account/authorization generation. CSRF and stale-context checks protect start/resume. Reading results remains available with research flags disabled, while starting/resuming still respects the runner's gates. The UI calls the same deterministic services as the CLI; it neither executes a shell command nor runs an LLM or the replay synchronously in the browser request. No forward paper ledger or brokerage permissions are changed.

### Fractional forward paper (source October 22, not deployed)

No migration. To use guarded fractional market orders in forward paper trading:
1. Apply a new execution version with order type "Market orders with a submission price guard" and fractional shares allowed.
2. Apply signal settings with slippage of at least 5 bp and "Fractional on market orders".
3. Confirm these runtime flags, then refresh cached configuration and restart queue workers:
   - `ROBINHOOD_MCP_EXECUTION_INPUTS_ENABLED`
   - `MCP_STRATEGY_SIGNALS_ENABLED`
   - `MCP_SIGNAL_PAPER_HANDOFF_ENABLED`
   - `MCP_PAPER_REAL_DATA_FILLS_ENABLED`
   - `MCP_EXTERNAL_ORDER_DETECTION_ENABLED`
4. After the session closes, run `mcp:cadence-report` to measure quote age at evaluation.

Paper only; no broker order. [Evidence and details](M5-FRACTIONAL-MARKET-PAPER-EVIDENCE.md).


### Batch evaluation and midday additions (October 22 source increment)

No migration. After deploying this increment, an enabled batched forward-paper cycle collects and evaluates each batch before reading the next. With signals/handoff/fills all enabled, the scheduler no longer performs a separate all-symbol market collection first. `mcp:market-cycle` remains read-only; `mcp:signal-paper-cycle` now includes collection when batch mode is enabled. Quote-age limits and submission-time checks stay in force.

Enable intraday reference repair separately in the private runtime environment, using the normal backup/edit/config-cache/service-restart procedure:

```dotenv
MCP_INTRADAY_SYMBOL_WARMUP_ENABLED=true
```

The scheduler runs `mcp:symbol-warmup --once` every five minutes in the background. It uses the existing data/reference/SEC/profile permissions and account-scoped universe; it attempts at most three symbols with a two-minute stage budget and a ten-minute per-symbol retry throttle. Large backlogs need several cycles. Fresh benchmark/reference evidence is reused. Its printed profile count includes unavailable profiles; confirm actual profile/eligibility reasons before interpreting a symbol as ready. Data-only lists stay non-nominating. The daily premarket schedules remain in place.

An explicit bounded diagnostic pass after enabling can use:

```sh
php artisan mcp:symbol-warmup --once --account=1
```

This command fetches permitted reference data and records profiles, without evaluating signals or creating orders. Do not use manual strategy-cycle runs as a connectivity test.

A one-time read-only cadence capture is installed for **October 22, 2026 at 15:05 Central**, after the 15:00 close. It writes runtime `runtime/cadence-baseline-2026-10-22/cadence-2026-10-22.json` and `capture-metadata.json`, then removes/unloads its own LaunchAgent. Leave the Mac/user service available. Check the private capture logs if the report does not appear. The report was still pending when this source increment completed. Deploying the fixes before the capture creates a session with mixed behavior; retain the recorded revision and do not label it a wholly pre-change baseline.

For a later stored-session comparison, run:

```sh
php artisan mcp:cadence-report --account=1 --date=2026-10-22
```

Compare completed-window coverage, quote ages **at evaluation**, within-age percentage, unavailable reasons and warm-up progress. Collection-time freshness alone does not establish evaluation-time freshness. [Implementation, test evidence and pending acceptance](M5-INTRADAY-COLLECTION-EVIDENCE.md).


### Owner-authorized home-LAN HTTP access, October 22

The owner explicitly chose HTTP for phone access on the home network, overriding the initial loopback-only/HTTPS prerequisite for this scoped use. The running Nginx configuration now listens on `0.0.0.0:8080`, with server-level access restricted to loopback `127.0.0.1` and the observed home LAN `192.168.4.0/22`. Other source networks are denied. No router forwarding, public tunnel, firewall, application authentication, session, brokerage or strategy settings were changed.

On a phone connected to the same home Wi-Fi, open **http://192.168.4.45:8080** and use the existing application login. The address is the Mac's current Wi-Fi address and may change with DHCP. Guest Wi-Fi/client isolation can prevent access. The Mac and service must remain available. HTTP does not encrypt the session; this is the explicitly requested private-network configuration.

The runtime configuration was backed up before editing to `runtime/private-config-backups/nginx-before-lan-http-20261022T203820Z.conf` (private permissions). Nginx syntax validation passed and a graceful reload succeeded. Checks from the Mac to its LAN address confirmed login HTTP 200, readiness HTTP 200, unauthenticated Control Center redirecting to the same LAN login URL, and `/.env` HTTP 403. Loopback login also remains available. Phone-side access has not been independently verified.

This is a persistent runtime configuration override; ordinary source deployments do not render Nginx again. The checked-in install template remains loopback-only by default. Re-running the installer can reset this override; review and deliberately reapply it if required. A different network requires reviewing both the address and source-network allowlist. To return to loopback-only operation, restore the private backup, validate the exact runtime configuration with `nginx -t`, and reload the dedicated Nginx instance.

No application code, frontend assets or database migration changed. No PHPUnit run was needed for this Nginx-only operational change; syntax, routing, authentication redirect and private-file protection were checked directly.


### Visual Paper Trading dashboard (October 22 source increment)

After deploying the dashboard source, select **Paper Trading** in the menu or open `/mcp/paper`. On the currently configured home Wi-Fi address this will be **http://192.168.4.45:8080/mcp/paper**. No migration or new environment flag is required. The running app will not have this route until deployment.

The page combines virtual ledger totals, a recorded opening-equity chart, session evaluation/blocker counts, quote/collection health, positions, order executions, recorded checks and reference/profile readiness. Choose an activity session, optional uppercase ticker and optional order state. Current portfolio totals and readiness remain current even when viewing a past session; historical tables and chart endpoint follow the selected date. Tables paginate independently. On a phone, swipe inside a wide table to see its remaining columns. Expand fill records or recorded checks for details.

Prices in the execution table are quantity-weighted actual simulated fills; unfilled orders show **No fill**. Buy and sell orders have separate rows. Marks require fresh scoped evidence and action clearance. Unknown equity remains unknown when a position cannot be marked. Initial/trailing stops are rule thresholds, not sale projections. Broker holdings remain separate from paper positions.

The chart contains only verified recorded session-opening valuations for the current authorization. Missing sessions are gaps, and no chart is drawn if there are no valid records. This is not an intraday/closing equity history or a performance benchmark. Use **Historical experiments** for retrospective runs; those balances/results are separate.

Refresh reloads stored data only. It does not collect quotes, repair profiles, run signals, fill orders or modify settings. Existing menu/header actions retain their documented behavior. Enabled warm-up/evaluation switches are configuration indicators; actual readiness and recorded outcomes determine whether data is available. The present warm-up failure is not fixed by adding this page.

[Accounting, privacy, tests and visual verification](M5-PAPER-DASHBOARD-EVIDENCE.md).

### October 22 release `261de7e`: migration recovery and dashboard deployment

The owner's encrypted backup `mcp-trading-20261023T012251Z.dump.enc` completed before the initial schema deployment failed. Recovery supplied the existing private credential path explicitly for both the migration dry run and deployment:

```sh
MCP_MIGRATION_CREDENTIALS_FILE="$HOME/.config/investing-ops/migration.json" \
  ./deploy/macos/deploy.sh 261de7e --migrate
```

The dry run verified the configured database/schema owner and reviewed only additive migration `2026_10_22_000028_create_mcp_crypto_market_data`. Deployment succeeded; the migration is recorded in batch 13, with no pending migrations. Its four tables are in database `postgres`, schema `mcp_trading`, owned by `admin`, with runtime DML privileges. No backfill was required.

Post-deployment `mcp:doctor` passed, `/ready` returned 200, LAN login returned 200, `/mcp/paper` redirected unauthenticated requests to the LAN login, and `/.env` returned 403. The Paper Trading dashboard is now deployed. These checks do not constitute a fresh authenticated browser review. Existing forward-paper flags remain enabled; crypto market collection, both crypto venue write flags, agentic brokerage writes, legacy schedules and in-app AI remain disabled. No crypto collection or trade was triggered. No application code changed during recovery, so the source increment's existing test evidence applies; deployment rebuilt frontend assets successfully.

### Crypto C2 stored-data evaluation (October 22 source increment)

Source only; not included in deployed `261de7e`. Commit/review the increment, take the normal encrypted backup and deploy the committed revision with `--migrate` and the explicit private migration credential path. Pending migration `000029` extends shared paper tables with instrument identity and standalone operator-owned crypto sleeves. It is forward-only; review its nullable connections, constraints and partial indexes. It does not create a portfolio or change an existing equity balance during migration.

After deployment, this explicit command records the owner-confirmed $400 crypto virtual sleeve and a versioned policy. Replace the operator ID and UUID with real local values; use the same UUID when retrying the same request. It creates no order and enables no service:

```sh
php artisan mcp:crypto-policy --actor=1 --request=YOUR-STABLE-UUID --base=0
```

The command returns portfolio/policy IDs and version. For a subsequent version, use a new request UUID and the current version as `--base`. The optional `--costs=/private/path/crypto-fees.json` accepts a JSON array of explicit product fee observations, each with these fields:

```json
{
  "version": "your-reviewed-fee-observation-version",
  "product_id": "BTC-USD",
  "cost_scope": "product_all_in",
  "maker_rate": "REPLACE_WITH_OBSERVED_DECIMAL_FRACTION",
  "taker_rate": "REPLACE_WITH_OBSERVED_DECIMAL_FRACTION",
  "observed_at": "REPLACE_WITH_OBSERVATION_TIME_IN_ISO_8601",
  "valid_until": "REPLACE_WITH_EXPIRY_WITHIN_ONE_DAY_OF_OBSERVATION",
  "source": "Your verified source and product applicability"
}
```

The placeholders deliberately fail validation. Never substitute a guessed account tier. `cost_scope=product_all_in` is the applying operator's confirmation that the quoted rates cover that particular pair's percentage charges, including applicable taxes/commissions, and no unmodeled fixed charge applies. Both timestamps require explicit ISO 8601 timezones. Without a verified, current product fee schedule the policy can exist, but entry evidence remains unavailable. The code reserves the greater of maker and taker rates on both sides, since maker is not necessarily cheaper. Actual fee/tax rounding verification is still pending; this is research evidence.

The October 23 source increment provides an independent, manual read-only fee-tier report:

```sh
php artisan mcp:crypto-fees --actor=1
```

An optional `--credential=ID` selects a specific active Coinbase credential; otherwise the existing resolver uses an active configured credential. This makes one fixed spot/CBE transaction-summary GET without redirect or retry. Output excludes balances and credentials, and includes decimal rate strings, a response hash and retrieval time. It never creates a policy or opening balance, changes flags, or calls the broker's order/preview endpoints. It is not scheduled and does not run during evaluation. A successful report says `requires_product_review`: compare each pair with the signed-in Coinbase Advanced fee page, including separate pair schedules, taxes and commissions, before creating the product-specific fee observation. Include the report hash in the source reference. Unknown tax or cost-plus commission remains a blocker; never copy account rates blindly across products.

`COINBASE_MARKET_DATA_PRODUCTS` bounds evaluation to 1–25 collected pairs. Any eligible USD spot coin can be included, with `BTC-USD` also collected for the benchmark; this is not automatic whole-exchange discovery. C1 collection has its own opt-in. After a separately deliberate configuration/cache refresh, the new default-off setting permits a manual stored-data evaluation:

```dotenv
CRYPTO_STRATEGY_EVALUATION_ENABLED=true
```

```sh
php artisan mcp:crypto-evaluate --actor=1 --portfolio=THE_RETURNED_PORTFOLIO_ID
```

There is no C2 evaluation schedule or paper-order handoff. The command returns statuses/reasons and stores immutable shared profile/signal evidence; it does not fetch data, call an LLM, reserve cash, fill orders or submit to a broker. Same-window retries return the frozen first result. Changed/funded/exposed portfolios require C3 valuation and lifecycle integration and are blocked. Disabling the switch preserves all history. Existing equity paper flags are independent.

Follow-up presence-only checks confirm both venue credentials are now recognized by the deployed app. No new authenticated call was made after private keys appeared in the IDE context; rotate exposed keys at the venues and replace local configuration. No key permission or write flag was changed by C2 development.

[Source behavior, migration review, tests and remaining C2/C3 acceptance](CRYPTO-C2-RULES-EVIDENCE.md).

## Live execution: broker approval setting (M6, read-only)

October 26 source follow-up (not deployed): migration `2026_10_26_000033` adds explicit paper/live signal source identity and changes the cycle uniqueness index. Use the normal backup/restore and owner-role migration procedure, quiescing workers during the schema/code transition. Both `ROBINHOOD_MCP_ACCOUNT_ELIGIBILITY_ENABLED` and `MCP_LIVE_EXIT_SIGNALS_ENABLED` default false. After reviewed deployment, deliberate account-read activation adds one read-only `get_accounts` call per monitoring run; failed/partial/stale metadata denies live submission while monitoring continues. The optional manual `php artisan mcp:live-exits --account=<local-id>` records live exit evaluations from stored broker-confirmed fills. It does not collect data, dispatch orders or schedule trading. The existing bound connection's portfolio/reference context is required; no virtual cash is initialized or used for sizing. Keep monitoring and held-symbol market collection available even after watchlist removal. Live submission rechecks the same episode/fill evidence. No new live schedule or activation is supplied by this procedure. [Source behavior, verification and remaining gates](M6-LIVE-EXITS-AND-SUBMISSION-EVIDENCE.md).

Requires additive migration `2026_10_26_000032` (deploy with the owner migration credentials as before). Then set `ROBINHOOD_MCP_APPROVAL_SETTING_ENABLED=true` in the runtime `.env` and redeploy the same revision so cached configuration is rebuilt. Each monitoring run then adds one read-only `get_trade_approval_setting` call; a failed read is recorded with the snapshot and never fails monitoring. The Control Center's live policy must state the approval mode you set in the Robinhood app; while the broker's setting differs or cannot be read, every live order is denied. Brokerage writes remain disabled and no order tool is callable. [Evidence](M6-ORDER-CONTRACT-AND-APPROVALS-EVIDENCE.md).
