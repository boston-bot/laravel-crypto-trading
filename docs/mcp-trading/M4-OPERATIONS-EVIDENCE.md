# M4 unattended operations evidence

Started October 16, 2026. M4 is **in progress**: application code, tests and deployment tooling are prepared and verified locally; the runtime installation, owner-provided alert/heartbeat accounts, Mac power/restart tests and the seven-session proving period are tracked below. Current status: production read-only monitoring and operations are running as of October 17 with an approved restricted database login; research, legacy schedules and brokerage writes remain disabled. External outage receipt, remote cloud recovery, Mac recovery drills and the seven-session proving period remain open. Historical setup observations below are dated. No brokerage mutation occurred.

## Delivered behavior

- **Health checks** (`mcp:health`, every minute on the runtime):
  - **Scheduler:** the last tick must be under 3 minutes old; checked from outside the scheduler, a silent scheduler shows as critical.
  - **Queue liveness:** an individually identified probe job per queue (monitoring, reconciliation, research); a probe left unprocessed for 15 minutes is critical, and a job waiting 10 minutes is a warning.
  - **Failed jobs:** any failure in the last 24 hours is a warning.
  - **Data age:** checked only while monitoring is enabled. The limit is 15 minutes during a verified regular session and 2 hours otherwise, including when the calendar is unknown.
  - **Disk space:** a warning below 5 GiB free, critical below 1 GiB.
  - **Backups:** a warning when the last backup is more than 26 hours old or failed, or the off-Mac copy is missing.
  - **Alerts and heartbeat:** whether each is configured.

  Critical and warning results open and resolve their own incidents under separate keys, so a worsening check opens a new urgent episode.
- **Independent heartbeat.** Each run pings the configured Healthchecks.io URL, adding `/fail` with the failing check names when anything is critical. The external service alerts if pings stop: Mac asleep or off, network down, scheduler stopped. Ping bodies name checks only.
- **One external alert destination: ntfy.** Notification intents flow through the transactional outbox. An attempt is recorded as delivered only when ntfy returns a message id.
  - Failed attempts back off exponentially, from 1 up to 30 minutes, for at most 10 attempts.
  - A crashed send becomes `unknown` and is retried, never reported as delivered.
  - Messages carry incident keys, severity, source and times only. They contain no account identifiers, balances or symbols.
- **Incident lifecycle.**
  - An incident has a source (monitoring, research or operations), plus resolution, reopen count and notification count.
  - A recurrence while open or acknowledged refreshes `last_seen_at` only, so an acknowledgement covers the ongoing episode.
  - A recurrence after resolution reopens the incident, clears the acknowledgement and notifies again.
  - A complete monitoring publication resolves earlier monitoring failures and pause reasons it no longer asserts. Incidents from other sources are untouched.
  - A successful research call resolves research credential and configuration incidents.

  This fixes the M2 gap where acknowledged incidents could never alert again.
- **Escalation and digest.** Critical incidents, and the configured urgent warnings (thesis breach, unknown order state, missed monitoring window, stale portfolio, positions or orders), are pushed immediately. If unacknowledged, they repeat every 30 minutes until acknowledged or resolved. Everything else is deferred to a daily 08:30 New York digest: counts, top open keys, monitoring and snapshot age, pending research, last backup, failed deliveries.
- **Encrypted backups** (`mcp:backup`, daily 02:30 Central):
  - All schema table row counts (including credentials, outbox and budget state) and a schema-scoped `pg_dump` (custom format) come from one exported `REPEATABLE READ` snapshot, so the manifest describes exactly what was dumped. The password is passed through a private temporary pass file.
  - Encryption is libsodium secretstream (authenticated, chunked) with a separately stored key; `mcp:backup --init-key` creates it and never replaces an existing one. The plaintext dump is deleted.
  - Each file is verified by decrypting it, then written with a SHA-256 manifest. A nonblocking directory lock prevents concurrent capture/retention; an existing same-second backup is refused without overwriting it. Retention keeps the newest backup of each of the last 14 days and of the last 8 ISO weeks.
  - A verified copy goes to the off-Mac folder when configured.
- **Restore drill** (`mcp:restore-drill`, monthly):
  - decrypts the newest backup whose manifest hash matches;
  - restores it into a uniquely named scratch database (`mcp_trading_restore_drill_<timestamp>_<random-suffix>`, never the runtime or test database);
  - verifies the schema and exact row counts against the manifest, that all restored connection bindings/manifests and OAuth credentials decrypt with `APP_KEY`, and that restored policies are execution-disabled;
  - always drops the scratch database.
- **Dashboard.** System Health shows every check with its detail, alert configuration, the alert delivery history with provider references or error codes, and each incident's source, resolution and recurrence. It never shows destination secrets.
- **Gating.** `MCP_OPERATIONS_ENABLED=false` by default. The five operations schedules register only when it is true, which is intended for the deployed runtime only.

## Deployment tooling

`deploy/macos/` runs a dedicated nginx and PHP-FPM pair and the workers as **user LaunchAgents**, with no sudo. Herd (port 80), Valet and Homebrew service configurations are untouched.

| Agent | Command |
|---|---|
| `local.investing-ops.nginx` | Homebrew nginx with its own config, `127.0.0.1:8080`, access log without query strings |
| `local.investing-ops.php-fpm` | Homebrew PHP-FPM 8.5 pool on a private `0600` Unix socket; opcache without timestamp checks |
| `local.investing-ops.scheduler` | `artisan schedule:run` every 60 seconds |
| `local.investing-ops.monitoring` / `.reconciliation` | `queue:work mcp_monitoring` per queue, recycled hourly |
| `local.investing-ops.research` | `queue:work mcp_research`, one try, recycled hourly |

`install.sh` renders configs with verified absolute paths, tests them (`nginx -t`, `php-fpm -t`, `plutil -lint`), refuses a port held by another program, and bootstraps the agents idempotently.

`deploy.sh <commit-or-branch> [--migrate]` deploys only committed revisions:
1. installs locked dependencies without dev packages and builds assets;
2. runs `mcp:doctor`;
3. stops if migrations are pending, unless `--migrate` is given;
4. caches configuration, restarts the workers and PHP-FPM, and checks `/ready`.

`uninstall.sh` removes the agents and keeps all data.

## Verification

| Check | Result |
|---|---|
| `vendor/bin/phpunit` | 167 passed / 998 assertions; 8 PostgreSQL-only skips (175 discovered) |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | 175 tests / 1082 assertions passed |
| New suites | 11 operations feature, 4 backup feature, 1 subprocess credential-boundary test, 4 cipher/retention unit, 1 deployment guard test, and 2 PostgreSQL backup + restore-drill integration tests with real `pg_dump`/`pg_restore` |
| Pint (changed files), `git diff --check`, `bash -n` and `plutil -lint` on templates | Passed |

Tests cover:
- **Incidents:** episodes, acknowledgement coverage, reopening, and publication resolving cleared monitoring incidents only.
- **Delivery:** acknowledged-only delivery; unacknowledged and HTTP failures with backoff; crashed-send recovery; unconfigured destinations keeping intents pending; 30-minute escalation until acknowledged; one private daily digest per day.
- **Health and heartbeat:** every health check state; heartbeat success and `/fail` bodies; health incidents opening and resolving.
- **Gating:** operations schedules register only when enabled.
- **Dashboard:** no destination secrets on the health page.
- **Encryption:** round trips at chunk boundaries; rejection of tampering, truncation, trailing data, wrong keys and non-backups, with no partial plaintext left behind; key privacy and no-overwrite.
- **Backups:** retention; off-Mac copy states and hash verification.
- **Real PostgreSQL restore drill:** matching counts, readable encrypted columns, scratch database dropped, and a tampered manifest refused.

## Runtime deployment

Takeover verification October 16, 2026 (America/Chicago): no separate runtime checkout exists at `~/Services/investing-ops/app`; no LaunchAgents were installed or started. The current local checkout still uses `postgres` / `mcp_trading`, verified owner/login `admin`. Contrary to a deployment already being present, the layout below remains a prepared deployment target.

After reviewing the interrupted work and passing the tests, additive migration `2026_10_16_000009_create_mcp_operations_tables` was applied as batch 6. Existing rows were preserved. A private backup key was created once at `/Users/joe/.config/investing-ops/backup.key` (contents never printed). A real local encrypted schema backup was created in `storage/app/backups/`, and `mcp:restore-drill` verified **54 tables**, readable encrypted OAuth/connection data, disabled restored policies, and successful scratch database removal. The encrypted file is `mcp-trading-20261017T023652Z.dump.enc`; its UTC name falls on October 17. Offsite status is **unconfigured**, not delivered or remotely stored.

Installed binaries were verified at `/opt/homebrew/opt/php@8.5/bin/php`, `/opt/homebrew/opt/php@8.5/sbin/php-fpm`, `/opt/homebrew/bin/nginx`, and `/opt/homebrew/opt/postgresql@18/bin/pg_dump`. Temporarily rendered nginx/PHP-FPM configs passed their native validation; no server was started. The initial sandboxed nginx check was blocked by its read-only macOS sysctl query; the same configuration check passed with the necessary sandbox permission.

Operations, monitoring, research, legacy schedules, legacy trading and brokerage writes all remain **false**, with zero registered scheduler tasks. ntfy, Healthchecks and the off-Mac directory are unconfigured. No alert or heartbeat message was sent, no broker or AI call was made, and no sleep/restart setting was changed.

### Repairs after the interrupted session

- Queue acknowledgements now carry a UUID, are recorded before dispatch and are accepted only for the current probe. Equal timestamps and late jobs cannot prove the wrong worker check succeeded.
- Restore manifests count every application-schema table, rather than the original small subset. Every restored OAuth credential is exercised, rather than only one connection manifest. A regression test initially demonstrated that checking just the first credential missed a corrupted later credential; iteration over all credentials fixes it.
- Backup capture/retention is serialized, existing timestamp files are protected, restore scratch names are randomized, and plaintext cleanup runs even if scratch-database cleanup fails. PostgreSQL passfile escaping handles both colons and backslashes without changing the password; a fake client process verifies the real file boundary and private permissions.
- Deployment refuses a dirty runtime checkout before fetching, installing dependencies or changing services, preserving operator edits. A temporary Git checkout regression test verifies that boundary.

The full pre-takeover suite also passed unchanged (170 discovered tests). The final full suite and build passed after these repairs; unrelated dependency lockfile remediation was preserved. No deployment commit was created.

## Owner actions and remaining verification

1. **ntfy:** install the ntfy app and subscribe to the topic stored in the runtime `.env` (`MCP_NTFY_TOPIC`); then run `php artisan mcp:notify --test` from the runtime and confirm the phone shows it.
2. **Healthchecks.io:** create a check (period 1 minute, grace 10 minutes), put its ping URL in `MCP_HEALTHCHECKS_PING_URL`, and confirm alerts reach you when pings stop.
3. **Google Drive:** install Google Drive for desktop, sign in, create a backups folder, set `MCP_BACKUP_OFFSITE_DIR` to its local path, and run `php artisan mcp:backup`.
4. **Keys:** store the backup key file and `APP_KEY` in a password manager, separately from the backups. Never put them in Google Drive next to the backup files.
5. **Power and restart behaviour:**
   - Keep the Mac on power and set it never to sleep on power (Battery settings or `sudo pmset -c sleep 0`). Turn off automatic macOS update installation.
   - Test logout, restart, sleep, network outage and a forced worker kill, and record the recovery.
   - After a restart, FileVault requires an unlock before anything runs. Planned restarts can use `sudo fdesetup authrestart`.
6. **Proving period:** decide when to enable monitoring (`MCP_MONITORING_ENABLED=true` in the runtime `.env`, then `deploy.sh` or `php artisan optimize` plus `queue:restart`), then record seven consecutive trading sessions.

Not yet verified: real ntfy and Healthchecks delivery (no owner accounts configured), an off-Mac copy (no directory configured; sync/upload receipt is not proven by a local copy), installed runtime services, restart/sleep/outage behaviour, a live worker-restart duplicate check with monitoring enabled, and the proving period. M4 acceptance remains open until these are recorded.


## Service setup continuation

Following the owner's request to proceed, an authenticated **POST-only phone setup reveal** was added at `/mcp/operations/phone-setup`. The normal System Health page keeps the ntfy topic hidden. Operators can reveal subscription server/topic details explicitly when configured; the server token is never exposed. The response disables caching/referrers; authorization, CSRF, unconfigured state and absence of outbound HTTP are tested. The screen links the official phone instructions and distinguishes provider acceptance from actual phone delivery. This change adds no migration and does not generate or transmit credentials.

Healthchecks was opened in Chrome at its sign-in page for the owner. No email/password was entered, account created, terms accepted, or browser credential imported. Plugin discovery did not return a relevant ntfy/Healthchecks integration. Official service setup references: [ntfy phone subscription](https://docs.ntfy.sh/subscribe/phone/), [Healthchecks check configuration](https://healthchecks.io/docs/configuring_checks/).

Read-only local inspection found an iCloud Drive container, no Google Drive desktop application and no Google Drive sync folder. The proposed optional off-Mac destination is `/Users/joe/Library/Mobile Documents/com~apple~CloudDocs/Investing Ops Backups` (encrypted backup and manifest only; never APP_KEY or the backup key). A copied local sync file would still need upload verification.

**Automatic approval review rejected** the combined attempt to initialize a random ntfy topic in private `.env` and configure/copy an encrypted database backup into iCloud Drive. Its stated reason: the user had not specifically authorized that external destination and private runtime configuration change. The command never started: no topic was generated/stored, no iCloud folder was created and no backup was transmitted by that attempt. No indirect alternative was used. Specific owner approval of these concrete choices is pending. This is separate from the earlier successful local-only encrypted backup/restore drill.

Continuation verification: Composer validation passed; PostgreSQL **177 tests / 1098 assertions passed**; SQLite **169 passed / 1014 assertions, 8 PostgreSQL-only skips** (177 discovered). Frontend build, targeted Pint and diff checks passed. Existing changes were preserved. No background service, scheduler, brokerage write or research activation occurred. M4 remains open.


## Healthchecks connection — October 17, 2026

The owner supplied a ping URL for the intended check. Only `MCP_HEALTHCHECKS_PING_URL` in private `.env` was configured; file permissions remain `0600`. The application sent one empty-failure-list **connectivity-test ping** through `HeartbeatPing`, received `acknowledged`, and stored the result in `mcp_component_status` with purpose `manual_connectivity_test`. No credential appears in tracked files or command output.

This is endpoint acceptance, not a claim that the whole deployment is healthy, that the check's period/grace are correct, or that an external outage notification reached the owner. Recurring operations remain disabled with zero registered schedules, so the one-time ping can age into Down. No additional providers were configured, no alert message was sent through ntfy and no cloud copy occurred. Broker writes, monitoring, research, legacy schedules and trading remain disabled. No migration was needed. Composer validation and the relevant fake-HTTP Operations/PhoneSetup tests passed: **13 tests / 111 assertions**. Prior full-suite results remain unchanged; frontend assets did not change.


## Approved ntfy/iCloud setup — October 17, 2026

The owner explicitly approved the two previously blocked choices. Private `.env` now has an unguessable ntfy topic (not copied to this evidence) and the approved iCloud destination `~/Library/Mobile Documents/com~apple~CloudDocs/Investing Ops Backups`. Existing nonempty topic values are preserved rather than rotated. Other configuration, including the supplied heartbeat URL, was preserved; `.env` remains ignored and `0600`. No ntfy HTTP request was made.

`mcp:backup` created `mcp-trading-20261017T144255Z.dump.enc` (434,503 bytes), authenticated it locally, and copied the encrypted file and JSON manifest to the approved folder. Hashes of the backup and manifest match their local originals. The retention policy pruned one older local backup; no unrelated data was removed. The destination contains only the expected encrypted backup/manifest names. The backup key and `.env` holding APP_KEY resolve outside the destination and were not copied.

A read-only macOS Foundation check reports both destination files as iCloud items, without an upload error. The completed-upload and uploading properties were unavailable (`null`), so this is a verified local iCloud-folder copy, **not proof of remote upload or recovery from another device**. The first metadata attempt encountered a sandboxed compiler-cache path; it was rerun using a temporary module cache and completed. No iCloud credentials were accessed or imported.

Verification: Composer validation and the targeted BackupTest, PhoneSetupTest and PostgresCredentialsTest suites passed (**7 tests / 44 assertions**); diff checks passed. The local System Health URL responds with the expected authentication redirect. No migration or asset change was needed. Operations, monitoring and brokerage writes remain disabled with zero schedules. The next owner step is to open System Health → “Show phone subscription setup” and subscribe using the displayed server/topic. Provider and actual phone delivery remain untested, as expressly requested. M4 remains open for deployment, automation, verified cloud upload, recovery tests and the proving period.


## Authorized phone notification test — October 17, 2026

After the owner reported phone subscription complete and explicitly approved a test, the existing `NtfyNotifier` sent exactly one non-financial message titled **Investing Ops: test**. ntfy returned a message ID; a manual test delivery row records provider acknowledgement. The topic/token were not printed. The pending outbox was not dispatched, and no retry or recurring notification process was started. The owner subsequently confirmed receipt on their phone; this verifies end-to-end delivery for this single test. No code, migration, brokerage write or schedule enablement was involved.


Phone receipt confirmation, October 17, 2026: the owner confirmed that the authorized test notification appeared on their phone. ntfy delivery is now verified for that one test. No additional message was sent. Recurring alert/heartbeat operation, failure recovery, cloud upload confirmation and the seven-session proving period remain unverified; M4 stays open.


## Installed supervised runtime — October 17, 2026

The owner explicitly authorized proceeding with background services. `prepare-runtime.py` froze the tested working source into a separate Git release at `~/Services/investing-ops/app`, revision `d92f0d6a102e06e06016e58729b2a5aacba9e04e`. This is a private local deployment artifact, not a commit/staging change in the development repository and not a push. The manifest records 382 source files and three built assets with SHA-256 fingerprints; `.env` and ignored credentials are excluded from the commit. Locked production dependencies were installed without dev packages; the runtime Git checkout is clean. Production APP_KEY, authorized user/session state, encrypted broker binding and database records were preserved.

The original development listener was verified before termination. Homebrew PHP-FPM 8.5.11 and Nginx 1.31.7 now serve `http://127.0.0.1:8080`. Verified executable paths are `/opt/homebrew/opt/php@8.5/bin/php`, `/opt/homebrew/opt/php@8.5/sbin/php-fpm` and `/opt/homebrew/bin/nginx`. Installer guards refuse debug/non-production mode, research/legacy/write-enabled flags, wrong database placement and an unrelated port owner. No Herd/Valet/Homebrew-global service configuration was changed.

Six user LaunchAgents are installed under `~/Library/LaunchAgents/local.investing-ops.*.plist`: nginx, php-fpm, scheduler, monitoring, reconciliation, research. Their paths and logs point into the runtime. Research is disabled; its dedicated worker processes liveness probes. Runtime private `.env` and cached configuration are owner-readable only; the parent runtime directory is private. Eight expected Laravel tasks register with operations and monitoring enabled, research and every legacy/write/trading flag disabled.

Development was separated into newly created `mcp_trading_development`, schema `"mcp_trading"`, owner `admin`; tested additive migrations initialized that empty database. The IDE checkout now has a separate development APP_KEY, port 8081 and all broker/research/operations/alert integrations disabled. Its database has no copied production user/broker rows. Production remains `postgres` / `mcp_trading`, owner `admin`; no production migration, destructive DDL or backfill was performed.

Actual verification:
- `/ready` returns ready through Nginx/PHP-FPM.
- Startup read-only broker reconciliation and the first scheduled monitoring run completed.
- Successive scheduler ticks, processed probes on all three queues and recurring Healthchecks acknowledgements were observed. Current health checks all report ok. This does not prove owner receipt of an external Down alert or full release readiness.
- The monitoring LaunchAgent was forcibly restarted. Replaying the already-complete logical key drained the queue while retaining exactly one complete snapshot/run. The prior concurrency tests separately cover competing and expired workers.
- A backup invoked by launchd encrypted the schema and hash-verified the iCloud-folder copy. A separate explicit one-shot launchd restore verified 54 tables/92 rows, all connection/OAuth decryption and disabled restored policies; the scratch database was dropped. No plaintext dump remained.
- An initial temporary `launchctl submit` smoke-test probe unexpectedly restarted (several successful backups); it was removed, and the restore check used an explicit `KeepAlive=false` one-shot plist. Both temporary agents were removed. Only the six intended persistent agents remain.
- AC power already reports sleep=0; no power/update/security settings were changed. User LaunchAgents remain tied to login; actual logout/reboot/FileVault/network tests are still outstanding.
- Composer validation, build, targeted Pint, shell checks and diff checks passed. Final isolated PostgreSQL suite: **178 tests / 1110 assertions**; SQLite: **170 passed / 1026 assertions, 8 PostgreSQL-only skips**. The snapshot regression verifies no developer-index changes, clean release versioning and private environment exclusion. Full suites passed both before and after database isolation.

### Initial database-role approval block (subsequently resolved below)

The operator guide requires a restricted runtime role before claiming unattended-deployment hardening complete. The existing `admin` runtime login was found to be a PostgreSQL superuser. Automatic approval review rejected the proposed creation of a persistent `mcp_trading_runtime` login and grants/default grants because the exact role and access scope were not explicitly authorized. The rejected command did not start: no role, grant or credential was changed. A subsequent read-only check confirmed that role does not exist and the runtime still uses `admin`.

[The reviewable SQL plan](../../deploy/macos/runtime-role-recovery.sql) gives only connection access plus usage/data/sequence permissions in database `postgres`, schema `"mcp_trading"`, and corresponding default grants for future objects created by owner `admin`. It does not grant superuser, role/database creation, schema creation, replication, bypass-RLS or owner membership. After explicit approval, a random password would be saved only in the runtime private `.env`, then config/workers reloaded. The schema/table owner and development login would stay `admin`. No grant or password is applied by the plan file itself.

Runtime services currently continue with the pre-existing login; this outstanding hardening step is not claimed complete. M4 also remains open for external outage receipt, remote iCloud recovery, Mac session/restart/outage drills and seven trading sessions. The calendar provider is still unavailable, so monitoring uses conservative hourly observations when session status is unknown. Live execution remains disabled and M5 has not started.

### Approved restricted database login applied — October 17, 2026

The operator explicitly approved the exact plan above. Local administrative provisioning verified containing database `postgres`, schema `"mcp_trading"`, owner `admin`, and that `mcp_trading_runtime` did not already exist before creating it. The reviewed SQL was applied transactionally with a new random password saved only in the runtime private `.env`. No password appears in the SQL plan, command arguments or evidence. Development credentials and schema/table ownership were preserved. Production cached configuration was rebuilt with mode `0600`; queue workers and PHP-FPM were reloaded. Runtime source is still the clean frozen release.

Verified using the runtime login:

- `mcp:doctor`: `postgres` / `mcp_trading` / login `mcp_trading_runtime` / owner `admin`, migrations present. All ten migrations remain applied; no application migration or backfill ran.
- All **54 tables** remain owned by `admin` and grant SELECT/INSERT/UPDATE/DELETE; all **46 sequences** grant USAGE/SELECT/UPDATE. Corresponding default privileges for future `admin` objects were verified in PostgreSQL catalogs.
- No superuser, database/role creation, replication, bypass-RLS, inheritance or role membership. Schema USAGE is allowed; CREATE is denied. A uniquely scoped CRUD probe succeeded inside a rolled-back transaction, with an explicit temporary ID avoiding sequence consumption. An attempted application-schema CREATE TABLE failed with SQLSTATE `42501`; that transaction was rolled back. No probe data/table was retained. Existing PostgreSQL PUBLIC defaults were not changed; the plan adds explicit object access only in the approved schema.
- `/ready` returned ready; all current health checks returned ok. The restarted workers processed all three correlated queue probes, scheduler ticks continued, and the recurring heartbeat was acknowledged. Research, legacy schedules, legacy trading and brokerage-write flags are disabled.
- Backup `mcp-trading-20261017T153222Z.dump.enc` was created with the restricted source login and hash-verified into the approved iCloud folder. Restore used the separate local scratch-database administrator, verified **54 tables / 94 rows**, all encrypted credentials readable and execution-disabled policy, then dropped the scratch database. Remaining scratch databases: zero. Cloud upload/remote recovery is still unverified.

Composer validation passed. Targeted PostgreSQL operations/backup tests passed: **26 tests / 196 assertions**, with fake external clients and the guarded `mcp_trading_test` database. No frontend or application code change required a new build. Earlier full-suite evidence remains above.

The runtime deliberately cannot apply schema migrations. A future schema-changing release needs a separately reviewed owner-authorized migration process; `deploy.sh --migrate` currently uses the runtime connection and does not provide separate owner credentials. Do not restore the persistent superuser login to work around this restriction. M4 remains open for external outage receipt, remote cloud recovery, actual Mac recovery drills and the seven-session proving period. M5 has not started.

### Separate owner migration workflow — October 17, 2026

Implemented in the development checkout, following the restricted-role deployment gap. No production deployment, role/grant change or operator database migration was performed.

- Added `deploy/macos/migrate.php` with check, pretend and forward-apply modes. Pending-file checks use only the runtime connection; failures are distinct from an empty pending list. Apply/pretend require `MCP_MIGRATION_CREDENTIALS_FILE`, a path to an operator-owned `0600` JSON file outside the checkout, containing only username/password. Symlinks, public files, malformed input and target overrides are rejected. The file remains outside `.env`, the config cache, Git and backup folders.
- The helper reuses the verified runtime host/database/schema, clears any connection URL override and requires the verified schema owner distinct from the runtime identity. Its temporary owner connection is purged and in-memory configuration restored after success or failure. A session-level PostgreSQL advisory lock rejects a competing helper before DDL; only forward migrations are exposed. The CLI emits a generic failure without raw exceptions, credentials or traces.
- Updated `deploy.sh --migrate` to call this helper only for explicitly requested pending migrations. A failed status check or migration stops before optimization/worker/FPM restart. Private umask protects generated runtime config. Services keep their restricted login.
- Real PostgreSQL tests used only guarded `mcp_trading_test`, a temporary restricted fixture role and additive temporary fixture migrations. They verified owner DDL, pretend mode without DDL, unchanged runtime role/config, transaction rollback on a failing migration, owner-connection cleanup, lock release/retry, non-owner rejection and concurrent-deploy rejection. Temporary fixture roles/data were removed. Separate tests reject unsafe credential files, suppress secret sentinel output at the CLI boundary and verify shell deployment stops on status/migration errors.

Verification: targeted PostgreSQL operations/deployment/backup **33 tests / 237 assertions**; final full PostgreSQL suite **185 tests / 1151 assertions**. SQLite credential/deployment guard tests **4 tests / 23 assertions** passed. Composer validation, targeted Pint, PHP syntax, shell syntax and diff checks passed. No frontend assets changed, so no new frontend build was required.

Remaining setup: create the private owner credential file (suggested `~/.config/investing-ops/migration.json`, current owner `admin`) using the existing owner database password, and include the helper in a reviewed release. Production owner authentication and a real schema-changing deployment with this helper are unverified. The frozen runtime release and its private credentials remain unchanged. The operator guide documents review/backup/deploy steps and transactional-recovery limits; do not replay a partially applied migration blindly or revert services to a superuser login. M4 release gates remain open; M5 is unstarted.
