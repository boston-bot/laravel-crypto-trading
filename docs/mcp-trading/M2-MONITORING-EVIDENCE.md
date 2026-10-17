# M2 monitoring evidence

Implemented October 16, 2026. M2 only; no unattended deployment or brokerage mutation. Existing M0/M1 and unrelated working-tree changes were preserved. No dependency versions changed.

## Delivered behavior

- `Monitor` uses the existing read-only `BrokerGateway`. One UUID run and one staged snapshot per account/deduplication key are persisted before broker reads. Portfolio, complete equity positions and complete equity orders are required for publication. Partial/error payloads remain staged. A failed or partial observation cannot close holdings or replace the last published snapshot; a complete empty equity set can replace it. Other asset classes are not claimed reconciled.
- Short connection-row transactions serialize claims and publication; broker calls run outside transactions. Renewable leases and claim tokens fence expired workers at every stage and at publication, including quote enrichment. A resumed worker cannot overwrite a replacement's completed payload. Account identity and OAuth generation are checked again. A monotonic publication version makes a successful retry of an older staged record the latest visible snapshot.
- Overlapping work coalesces; completed keys cause no further broker reads. Failed runs can retry with the same UUID/snapshot. Progress, typed errors, sanitized `Retry-After` delays and timestamps survive restarts. Provider cooldowns apply across triggers. Three failed runs in fifteen minutes open the scheduled circuit until that window expires. Read jobs have three bounded attempts with backoff; authorization/contract failures are not generically retried.
- Snapshot payloads/observations, thesis and policy versions, sleeve assignments, flow evidence and archive rows use encrypted casts. Quantities/market values also have PostgreSQL `numeric(38,18)` projections. Quantities must be representable exactly at that scale; derived valuations use explicit HALF_EVEN rounding. All financial API values stay decimal strings; no legacy float arithmetic is reused.
- Account equity/cash/broker buying power, observed equity exposure and sleeve quantities/values are recorded. Broker buying power is never reduced again by pending notional. Unfilled limit-buy notional includes manual broker orders; fee-inclusive commitments remain unknown where future fees are not verified. Unknown order types/states block a definitive commitment total. Unexplained sleeve reductions are flagged rather than silently rewriting prior attribution. Unassigned holdings remain unclassified; no owner allocation was invented.
- Disabled policy drafts, thesis versions and sleeve assignments have append-only application writer services and actor/effective-time history. Historical records reject ordinary Eloquent updates/deletes. There is no policy activation endpoint. Thesis authoring/research workflows are not fabricated by the dashboard; M3 will add research authoring. Local DB administrators still have database privileges; application hooks are not a database-administrator tamper barrier.
- Deterministic invalidation types distinguish current price from completed-session close, fundamental event and manual review. A last-price quote cannot satisfy a completed-close rule. Closing-bar evaluation requires explicit complete daily-bar evidence and a verified finished exchange session; unsupported fundamentals require review. Missing/stale evidence yields unavailable, never clear.
- `MarketCalendar` stores sourced UTC session boundaries for the New York exchange date, including holiday/early-close/DST fixtures. Missing or expired coverage is unknown; there is no weekday fallback. No live calendar provider or daily-bar provider was invented.
- `FlowAccounting` unitizes only with a verified positive baseline, complete flow coverage, unique original event references and contemporaneous pre-flow valuations. Deposits/withdrawals change units; fees/distributions remain investment performance. Unknown classification/timing/coverage makes returns unavailable. Runtime snapshots persist unavailable returns/drawdown because the actual integration has neither verified flow history nor a historical baseline. No historical performance or benchmark is inferred.
- Incidents deduplicate by account and reason; first/last occurrence and server-derived acknowledgement are stored. Notification intents enter the outbox transactionally. They remain pending: no external destination/delivery adapter exists until M4. Acknowledgement is not resolution or evidence of delivery.

## Scheduling, commands and UI

`MCP_MONITORING_ENABLED=false` remains unchanged. If explicitly configured later, one minute scheduler ticks coalesce account reads into five-minute regular-session slots or hourly outside/unknown-session slots. Unresolved orders request a separate reconciliation queue. A long observation gap becomes an incident; stale queued work expires instead of replaying old observations. The monitoring and reconciliation jobs both perform broker reads only.

`mcp_monitoring` is a dedicated database queue connection with `retry_after=720`, longer than the job's 600-second timeout. Jobs recheck enablement, operator ownership, account scope and work age before reading. The outbox tolerates duplicate dispatch through the run uniqueness boundary; it does not claim exactly-once network delivery. No worker, scheduler or LaunchAgent was started.

Available manual commands:

```bash
php artisan mcp:sync --account=<local-connection-id> --key=<stable-key>
php artisan mcp:monitor --once
php artisan mcp:import --file=/path/to/export.json --dry-run
# Separate explicit operator action only:
php artisan mcp:import --file=/path/to/export.json --account=<local-connection-id> --commit
```

`mcp:sync` performs a foreground observation without enabling schedules. `mcp:monitor --once` dispatches nothing while monitoring is disabled. CLI account selection is restricted to allowed local operators. CLI reports omit account numbers, balances and source free text.

`/` now redirects to `/mcp/dashboard`. Six authenticated views provide Overview, Holdings/Theses, Research/Watchlist, Decisions/Orders, Performance and System Health/Settings. Research explicitly says M3 is not implemented; orders are broker observations, not app execution decisions. Each page shows provenance, source and retrieval timestamps, age-dependent freshness, operating mode and scoped incidents. Quote failures show attempt time and typed error without inventing a retrieval timestamp. Account IDs are masked. POST refresh/acknowledgement require operator permission and CSRF; run IDs must be UUIDs. Source text is Blade-escaped. A full-page refresh is synchronous; there is no asynchronous loading state.

## Archive contract

The importer accepts an operator-provided JSON list (<=5 MiB, <=10,000 rows) with original `sheet` and `id` fields. Supported historical sheet labels are Bot Control, Check Log, Decision Log, Trade Ledger, Performance Log, Opportunity Review and System Health. Preview reports historical target mappings, original row numbers, SHA-256 source hash, duplicates and quarantine reasons without writing. Missing/`undefined` IDs, unknown sheets, contradictory fills, numeric floats and unverifiable flows are quarantined. Both repeated source files and repeated sheet/reference pairs across exports are deduplicated.

Only `--commit` archives the encrypted source rows/report in one transaction. Imported rows remain historical evidence; they do not create operational run UUIDs, broker fills, current holdings, active research, performance baselines or enabled policies. A Bot Control row cannot activate trading. The operator retains the original export file; source hashes identify its exact bytes. No real archive was supplied or imported. Native workbook/CSV/Google Sheets export conversion is not implemented; it must produce this reviewed JSON contract first. No legacy locks or rows were repaired.

## Verification

- `composer validate --no-check-publish`: passed.
- `vendor/bin/phpunit`: **89 passed, 515 assertions; 4 PostgreSQL-only skips** (93 discovered).
- `vendor/bin/phpunit -c phpunit.pgsql.xml`: **93 tests / 562 assertions passed**, against guarded `mcp_trading_test` / `"mcp_trading"` only. Schema placement checks cover all twelve M2 tables and assert no tables in `public` in the fixture.
- Two separate PostgreSQL worker processes produce one run, one complete snapshot and one broker-read sequence; a third process reuses publication without reading. PostgreSQL retains the exact quantity `2.000000000000000001`.
- Failure tests cover incomplete empty responses, retries, auth/account changes, expired worker fencing before and during quote reads, old-run publication ordering, source-time unknown/stale UI, provider cooldown, repeated-failure circuit, queue separation/disablement, stale/rebound queued jobs, CSRF and cross-account isolation.
- Accounting/calendar/import tests cover deposit-neutral returns, fee/distribution treatment, missing baselines/flows/timing, intraday versus completed close, missing calendar, holidays, early close, DST, bad IDs/decimals/fills and within/across-file deduplication. HTTP tests render all six views and empty/failed/retained/stale states.
- `npm run build`, targeted Pint and `git diff --check`: passed. No browser visual review or unattended worker proving period is claimed.
- During development one PostgreSQL concurrency run failed because the fixture still had an earlier version of the not-yet-applied M2 migration. The fixture was rebuilt by the guarded test setup; targeted concurrency and the final complete suite then passed. No runtime reset or destructive repair was used.

## Runtime evidence and migration impact

`mcp:doctor` verified `postgres` / `mcp_trading`, login/owner `admin`, before DDL. `migrate:status` showed only M2 pending. Migration `2026_10_16_000006_create_mcp_monitoring_tables` applied as batch 3: twelve additive tables, no legacy table changes/backfills/deletions. The migration has a fixture/development rollback definition; no runtime rollback/reset was run.

Manual app-owned broker reads published a complete account-total/equity snapshot with **2 holdings and 3 equity orders**. Missing source times remained unknown; out-of-session stale quote reads were withheld rather than promoted to fresh valuations. A second observation verified quote-failure attempt/error metadata without fabricated retrieval times. Replaying its completed key returned the same UUID immediately; no new observation or broker read was performed. Both runtime observations retained unknown returns. No balances, quantities, account identifiers, source prose or credentials are copied into this evidence.

Monitoring, legacy schedules, legacy trading and brokerage writes remain disabled; scheduler registration count is zero. No scheduled worker was started. Existing dependency advisories from M0 remain a reviewed remediation item before unattended deployment.

## Remaining verification and next milestone

M2 implementation/fixture acceptance is complete. Live valuation/risk readiness still requires trustworthy source-time/quote freshness, a sourced exchange-calendar and completed-bar provider, verified cash-flow/baseline coverage, and owner sleeve mappings. Missing flows/calendar remain unsupported broker capabilities, not a missing OAuth credential. Actual archive compatibility requires an operator-provided export plus explicit import action. Programmatic thesis/sleeve writers are tested; no owner thesis/allocation has been supplied or invented.

External alert delivery, process supervision, independent heartbeat, restore drills, Mac sleep/restart behavior and seven-session proving are M4 work. Research/news adapters and the research/watchlist authoring workflow are M3. Nothing here authorizes live execution. Next milestone: **M3**, not started.
