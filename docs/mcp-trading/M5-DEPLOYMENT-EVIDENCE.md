# M5 partial release deployment and paper settings handoff

October 18, 2026. The owner explicitly requested deployment of the completed M5 work and application of the reviewed paper settings. **Deployment and subsequent authenticated settings application succeeded.** The original application blocker and its resolution are recorded below. M5 remains partial, and no live trading is authorized. The seven-session M4 period remains waived, not passed (0/7 completed).

## Applied owner settings verification

Subsequent October 18 update: the owner requested next step #1, and the existing signed-in Chrome tab became accessible through normal browser control. The prepared mandate and execution settings were saved and applied with the normal authenticated, CSRF-protected Control Center forms. This resolves the earlier browser/application blocker described below. No permissions, credentials or service flags were changed to gain access.

The mandate was saved as **draft v1** and applied as **paper policy revision 1** at **22:04:37 UTC / 17:04:37 Chicago** on October 18. The execution settings were saved as **draft v1** and then **applied execution v2**, tied to that paper policy. Both application actions displayed success in the deployed UI. A subsequent PostgreSQL read-only transaction verified:

- All mandate/applied-policy/execution values exactly match the prepared owner JSON, including the 44-symbol snapshot; identities come from the signed-in operator. No broker account identifiers or exact symbol list are added to Git.
- One virtual portfolio and exactly one `opening_balance` entry for **$500**; cash **$500**, reserved cash **$0**, ledger version **1**.
- Both execution history records are retained; mandate, applied-policy and execution payloads decrypt successfully from encrypted storage.
- Zero simulation positions, orders, lifecycle events, AI decision runs and handoff outcomes.
- Monitoring/operations remain on. Research, paper decisions, offline simulation, market-input collection, handoff, watchlist reads, live brokerage writes and legacy schedules remain off.

Visual confirmation was saved at `/private/tmp/m5-paper-settings-applied.jpg`; the Control Center remains available for owner inspection. No application code, migration, dependency or deployed revision changed. Verification used actual authenticated save/apply responses plus a read-only database comparison; automated tests were not rerun for these operator settings actions. No model call, broker request or brokerage mutation was dispatched.

Next operation is a new encrypted backup and isolated restore check containing these populated M5 records. **That backup/restore step has not been performed by the settings-application task.** Earlier restore evidence below covers the empty M5 tables only. M5 real-data/provider/model limitations remain open.

## Populated M5 backup and restore verification

Subsequent owner instruction authorized next step #2. At **2026-10-18 23:01:25 UTC / 18:01:25 Chicago**, the deployed application's existing `mcp:backup` command created `mcp-trading-20261018T230125Z.dump.enc`: **750,870 bytes**, SHA-256 **`80256f12f1d57f35dd347922ef56cdf8af6b8b9df73d618bb8cb422fb358b42e`**. The encrypted file and manifest are mode `0600`. Existing retention pruned one older backup; no key was created or replaced. The existing offsite adapter copied the encrypted dump and manifest to the previously approved iCloud folder. Both copied hashes match locally; independent-device/cloud-upload verification was not repeated for this pair.

The existing `Backups::restoreDrill()` workflow restored this exact file into a fresh uniquely named scratch database, compared all **66 tables / 434 rows** with the transaction-consistent dump manifest, checked existing encrypted OAuth/account columns and disabled restored execution policy, and dropped the scratch database. No scheduler, queue worker, model or broker client was run against the restored database.

A temporary subclass added read-only M5 assertions inside that workflow before its normal cleanup. It verified **12 paper tables / 6 populated paper rows** against the applied runtime state, including exact encrypted row contents, decryption of mandate payload/reason, both execution payloads and the applied policy snapshot. The restored values match the owner's prepared mandate/execution JSON: mandate v1, policy revision 1, execution v2 and all 44 symbols. Portfolio/policy/mandate/execution references match; there is exactly one opening entry with amount and resulting balance **$500**, portfolio starting cash/current cash **$500**, reserved cash **$0**, and ledger version **1**. Positions, orders, fill/lifecycle events, decisions and handoff outcomes remain empty.

After the drill, a separate catalog query confirmed the exact scratch database is absent, and the temporary plaintext dump is absent. Runtime `.env` and cached configuration hashes are unchanged. A fresh read-only verification of runtime settings again matched the prepared choices, encrypted payloads and $500 ledger; all AI/paper/live/legacy gates remain disabled. Monitoring and operations remain enabled, and `/ready` returned healthy.

No production restore, application-code edit, migration, deployment, credential change or broker/model request occurred. Automated tests were not rerun: this was a real operator backup/restore verification using the deployed helper. The additional M5 checks were a one-off assertion extension in `/private/tmp/m5-populated-restore-drill.php`, not a change to the recurring restore implementation. Its redacted result is `/private/tmp/m5-populated-restore-result.json` (0600). Restoring filled paper portfolios, corporate actions, performance history and model runs remains untested because those records do not exist yet.

This completes the populated-settings recovery step previously marked pending above. Next operator work is separately enabling/verifying read-only watchlist access in runtime; next development work remains M5 real-data eligibility/liquidity/actions/fractional coverage and paper fills. No enablement was performed by this backup task.

## Runtime read-only watchlist activation

Subsequent owner instruction authorized the next read-only watchlist step. Enabled **only** `ROBINHOOD_MCP_WATCHLISTS_ENABLED=true` in the deployed private `.env`; every other environment-file byte was preserved. Refreshed configuration/routes/views with the deployed `artisan optimize`, signaled graceful queue-worker restarts and restarted the dedicated PHP-FPM LaunchAgent. The cached configuration remains mode `0600`. No application revision, migration, dependency or broader execution gate changed.

A real invocation of the deployed `mcp:watchlist` command, using the unique operator-owned agentic-monitoring connection and the existing app-owned MCP/OAuth gateway, succeeded at **2026-10-18 23:08:14 UTC / 18:08:14 Chicago**. “On Watch” returned **44 items / 44 instrument symbols**, with no missing/other item types. The gateway rechecked list identity/count. These symbols exactly match the currently applied execution-v2 allowlist: **0 added / 0 removed**. No policy or allowlist was modified. Source timestamps remain unknown; retrieval time and structural completeness are recorded, and membership is not proof of execution eligibility.

The returned private source evidence is encrypted at `/private/tmp/m5-runtime-on-watch-20261018.encrypted` (0600), using the app's existing encryption context; exact symbols and broker identifiers are not added to Git. This is an observation artifact, not an automatic watchlist-to-permissions subscription. Reads remain manual; no polling schedule or new service was installed.

After PHP-FPM restart, the immediate readiness probe briefly returned HTTP 502; the subsequent probe returned healthy without further intervention. All six existing LaunchAgents were loaded, with fresh worker/FPM processes, and the scheduler remains its normal on-demand job. A read-only PostgreSQL/settings check confirmed mandate v1 / policy revision 1 / execution v2, exactly one $500 opening entry, $0 reserved, zero positions/orders/decisions and unchanged encrypted prepared settings. Monitoring and operations remain on. Research, paper decisions, offline simulation, market-input collection, handoff, live writes and legacy schedules remain off; watchlist reads alone are now on. No AI API request or broker mutation occurred.

No automated-test rerun was needed for this configuration-only action; verification used the real deployed CLI/gateway, the readiness endpoint, process status and a read-only runtime state comparison. No deployment or migration ran. Next work remains M5 verified instrument/fractional/liquidity/corporate-action inputs and real-data paper fills, followed by model/quota verification when credits are available.

## Release and database changes

Deployed exact source commit **`b9b514641c6d573bec1713142f92a26c9cb2d540`** into `/Users/joe/Services/investing-ops/app`, replacing `d11fc1c`. Both source and runtime were clean before deployment. The runtime origin was verified as the local development repository; no remote push or new release commit was created. Locked dependencies were installed and Vite assets built without a dependency upgrade.

Before deployment, Laravel verified **database `postgres`, schema `mcp_trading`, runtime role `mcp_trading_runtime`, owner `admin`**. Reviewed migration PHP and owner-connected pretend SQL for `000010`–`000014`. A fresh encrypted backup was created before any DDL. The existing owner-authorized deployment helper applied all five additive migrations as **batch 7**: twelve M5 tables and two nullable execution-version foreign-key columns. There was no destructive migration, reset or backfill; no migrations remain pending.

Post-deployment checks verified all twelve tables are owned by `admin` and permit runtime SELECT/INSERT/UPDATE/DELETE. All eleven new sequences are owned by `admin` and permit runtime USAGE/SELECT/UPDATE through the existing default grants. The runtime role remains non-superuser, cannot create roles/databases and has no schema CREATE permission. The temporary owner connection is absent from cached configuration; cache mode is `0600`.

## Availability and safety verification

The existing scheduler and three queue workers were cleanly unloaded with zero queued work, from **20:36:38–20:36:47 UTC (15:36 Chicago)**. A shell recovery trap restored their original LaunchAgents after deployment. PHP-FPM restarted through the existing deploy script; nginx stayed running. All six existing services are loaded afterward. No new service or recurring task was installed.

The runtime `.env`, APP_KEY and database configuration matched their private pre-deployment hashes. No development credential/configuration was copied into production. Monitoring and operations remain enabled; research, paper decisions, offline simulation, market-input collection, proposal handoff, brokerage writes and legacy crypto schedules are **all disabled**. An applied policy alone would not enable any of them.

Observed checks:

| Check | Result |
|---|---|
| Composer validation | Passed |
| Full PostgreSQL suite on dedicated test database | **311 tests / 2,449 assertions**, all passed |
| Full SQLite suite | **288 passed / 2,047 assertions**, 23 PostgreSQL-only skips |
| Frontend build | Passed, locked Vite 7.3.7 |
| Pint on all 84 changed PHP files | Passed |
| Whole-repository `pint --test` | Failed on pre-existing legacy formatting; unrelated files preserved |
| `/ready` after reload | Healthy; six subsequent HTTP 200 responses, **0.0190–0.1827 seconds** |
| `mcp:doctor` and migration check | Correct restricted identity; no pending migrations |
| `mcp:health --no-ping` | All eleven checks healthy; manual heartbeat skipped |
| Scheduler/heartbeat after restart | Tick and acknowledged heartbeat at **20:37:49 UTC** |
| Fresh correlated queue probes | Monitoring/reconciliation acknowledged at **20:37:49**, research at **20:37:51 UTC** |
| Queue/failure state | Zero queued jobs and zero failed jobs at verification |
| Paper state | All twelve tables empty; no policy, virtual cash, order or fill created |

The latest complete broker snapshot at verification was **20:00:50 UTC**; the existing out-of-session data-age check reported healthy. No manual broker/model request was made for deployment, and no brokerage mutation occurred. Existing read-only monitoring schedules were restored. Browser acceptance of the deployed Control Center remains unverified because browser access was blocked, as described below.

## Backup and recovery evidence

Pre-deployment backup: `mcp-trading-20261018T203609Z.dump.enc`, **654,629 bytes**, SHA-256 `e94bace81d0cd34dcf071a1d361b8f04e0405a55dac4dac90c9500740c4bba5d`.

Post-migration backup: `mcp-trading-20261018T203716Z.dump.enc`, **721,937 bytes**, SHA-256 `da9200a0a9c8fdaf4d13f7d2bd2a957135deb5c773265ab8c50612432423e9bf`. Its isolated restore verified **66 tables / 404 rows**, readable existing encrypted columns and disabled restored execution policy; the scratch database was dropped. New M5 tables were empty, so this drill does not establish recovery of an applied M5 policy or filled paper ledger yet.

Both backups and manifests were copied by the existing adapter to the approved iCloud folder. This is a local copy/hash result, not an independent-device upload check for these new files. Both keys remain outside the backup destination. No production database restore or destructive rollback was performed.

## Prepared paper settings and remaining operator action

The private review bundle decrypts with the runtime's existing key. The deployed validators accepted the complete mandate and execution settings: **$500 cash-only**, **100% active allocation**, **90% maximum position**, **20% daily loss pause**, **five-minute AI review**, **44-symbol On Watch membership snapshot**, **1% per-fill fee**, **1 bp slippage**, **5 bp limit offset** and **20-second quote freshness**. Exact symbols and broker object identifiers are not committed. These values are owner choices/delegated assumptions, not proof of investment suitability, actual broker fees, instrument eligibility or attainable cadence.

Prepared owner-readable files, all mode `0600`, outside the repository/cloud folders:

- `/private/tmp/m5-paper-mandate.json`
- `/private/tmp/m5-paper-execution.json`
- `/private/tmp/m5-apply-paper-settings.md` — complete form values, symbol list and save/apply instructions

**No settings have been saved or applied to PostgreSQL.** Browser access to `http://127.0.0.1:8080` was initially declined. After the owner explicitly allowed access and later reported clearing the saved denial, two retries still failed with a saved permission/managed-policy denial. No alternative browser surface, session extraction, impersonated actor, direct controller call or database policy insertion was used to bypass that restriction.

The owner can use the prepared guide at `/mcp/dashboard/control` to preview/save/apply the mandate, then save/apply its execution draft. This must use the existing authenticated operator context and normal CSRF-protected forms. Once the owner confirms completion, verify immutable policy/execution versions, one $500 opening cash entry, zero holdings/reservations/orders, encrypted payloads and disabled enablement; then make a new encrypted backup and restore check including those records. Temporary review files are handoff artifacts, not durable policy records or backups.

Remaining M5 limitations are unchanged: provider quota admission versus the reported 50-RPD/five-minute mismatch, HTTP-429-blocked real generation compatibility, real instrument/fractional/liquidity/corporate-action coverage, broad-universe market/news collection, real-data fill processing and forward performance/cadence evaluation. Deploying the completed portion does not complete M5 or authorize M6.
