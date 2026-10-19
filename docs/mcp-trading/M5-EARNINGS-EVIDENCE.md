# M5 earnings-date source

October 19, 2026. The owner chose Robinhood for earnings dates. This document closes the earnings decision in [calendar and profiles](M5-CALENDAR-AND-PROFILES-EVIDENCE.md#earnings-date-source-owner-decision-needed). Source and tests, plus owner-authorized read-only contract review. No deployment, runtime migration, settings change or enablement.

## Contract review

1. **Stored catalog (owner-approved, read-only).** A script read only the runtime's stored tool catalog: no network, no writes, no tokens or account data. It found 83 tools, among them `get_earnings_calendar`, `get_earnings_results` and `get_equity_historicals`, and saved their functional schemas. The stored catalog strips descriptions. The agent's attempts to run the script were blocked by the session's production-read safeguard until the owner added a narrow allow rule. The rule can now be removed.
2. **Full metadata and one bounded call (owner-run).** The owner ran a second script through the runtime's own OAuth connection. Its RPC allowlist permitted only `tools/list` and `get_earnings_calendar`. It saved the full descriptions for two tools and made one call for 7 days from 2026-10-19: 334 events for 330 symbols, with timings `am`, `pm` and null. No order, account or watchlist tool was reachable, and no token was printed.

Reviewed meaning, from Robinhood's own descriptions:
- **Scope:** "earnings reports scheduled across the market", all names when `filter` is omitted (`high_market_cap` restricts to over $1B).
- **Window:** `start_date` (YYYY-MM-DD, New York) plus `days`, at most 31.
- **Rows:** one per company-quarter, sorted by date.
- **Report date and timing:** `report.date` is YYYY-MM-DD; `timing` is `am` (before market), `pm` (after market) or null when unknown.
- **Confirmation:** `verified=false` marks a tentative date.
- **Status:** a null `eps.actual` means the report hasn't happened yet; an empty array means no reports in the window.
- **Tool flag:** `readOnlyHint: true`.

| Item | Value |
|---|---|
| Operation | `earnings_calendar` (`get_earnings_calendar`), reviewed read-only |
| Fingerprint | `b0e03b8395d0b0c8818e9085edffe8816254b752e005466e9d161967b3fa443b`, matching the schema fixture `tests/Fixtures/Mcp/robinhood-earnings-calendar-schema.json` |
| Fixed arguments | `days=31`, no filter (all names); `start_date` is the New York date |
| Gate | `ROBINHOOD_MCP_EARNINGS_ENABLED=false`, independent of other read gates |
| Coverage label | `market_wide_earnings_calendar_all_names_31_days` |

The five real sample rows pass the pinned output schema, the new normalizer and record validation, including a negative EPS and both timings. This check ran offline on the saved sample.

`get_equity_historicals` was reviewed only by description: split-adjusted bars for up to 10 symbols per call, with gap-filled bars flagged as `interpolated`. It is a possible later replacement or cross-check for Alpaca bars and is not used yet.

## Changed behavior

**Gateway.** Earnings is a new closed `Operation` case with its own gate in the session allowlist, catalog checks and profile resolution. The gateway accepts a validated `start_date` filter only where the reviewed profile maps it. Each row is normalized strictly:
- integer year and quarter 1–4
- a date-shaped report date
- timing limited to `am`, `pm` or null
- a boolean confirmation flag
- exact EPS decimals

Any other value refuses the whole calendar. Events are keyed by company, fiscal quarter and report date: two dates for one company are both kept, and an exact repeated event refuses the read. The live sample had four symbols with two events in 7 days, so keying by company-quarter alone could have refused every real read.

**Records.** `EarningsCalendar::collect` stores one immutable encrypted market-wide record per request in `mcp_earnings_calendar_reads`: start date, days, status, reason, event count and normalized events. Broker guide text is never stored. The network read happens outside transactions, and the record is written under a fresh operator, binding and authorization check. A failed read is stored `incomplete` and never hides or extends the last complete calendar. `php artisan mcp:earnings-calendar --account=<id>` runs a read; `--scheduled` is the idempotent daily refresh, at 06:30 New York when `ROBINHOOD_MCP_EARNINGS_ENABLED` and `MCP_PAPER_MARKET_COLLECTION_ENABLED` are on.

**Blackout rule.** `EarningsCalendar::status` uses the latest complete calendar stored by the cutoff, if it is at most 30 hours old:
- **Horizon:** the next five trading sessions, counting the cutoff's own session and skipping days without a session, from the stored market calendar. Any unknown day in that horizon makes the result `session_calendar_unknown`.
- **Coverage:** the calendar window must start no later than the cutoff day and reach the horizon end; otherwise `earnings_window_too_short`.
- **Upcoming reports:** a report is upcoming until it has an actual EPS, including a same-day morning report not yet updated. Tentative dates and unknown timing count as real.
- **Result:** if the earliest upcoming report falls on or before the horizon end, the result is `blocked` (`earnings_within_blackout`, with date, timing and confirmation). Otherwise it is `pass` (`no_report_within_blackout`), with the next report in the window, if any, and the window end.
- **Unknown:** a missing or stale calendar is unknown and never a pass.

**Profiles.** When the earnings gate is on, profiles take this result. `earnings_date_unknown` is replaced by no blocker (`pass`), `earnings_blackout` (`blocked`), or stays `earnings_date_unknown` (unknown). The calculator version is now `swing_profile_v2`, and the earnings read ID is part of each profile's fingerprint evidence. Exit timing ("last session before a known report") can use `next_report` in the later signal step.

## Verification

| Check | Result |
|---|---|
| Full SQLite | **458 passed / 3,299 assertions, 31 PostgreSQL-only skips** |
| Full PostgreSQL | **488 passed / 3,586 assertions, 1 existing PHP-FPM environment skip** |
| `composer validate`, Pint, `git diff --check` | Passed |
| Real sample contract check (offline) | Output schema valid; five rows normalized |

`EarningsCalendarTest` (6 tests / 55 assertions) covers:
- the pinned fingerprint against the schema fixture, the exact arguments, encrypted immutable storage, and no other tools called
- five malformed or ambiguous row cases refusing the read, while two dates for one company keep the earliest
- blackout by trading sessions across a weekend, with tentative and untimed reports, already-reported and same-day reports, and unscheduled and absent symbols
- missing, point-in-time, stale and calendar-unknown cases, and a failed refresh
- gates, operator scope, idempotency and the command
- profiles switching from the unknown blocker to pass and blackout

The calculator and profile suites were updated for the new parameter.

## Limits

- **No end-to-end live read through the new code.** The new code was not run against the live server (the development app has no broker connection, and runtime reads need the owner); the contract comes from the owner-run review. The first runtime refresh will show whether real responses ever contain exact repeated events, which would be recorded as `incomplete`, failing closed.
- **Coverage is Robinhood's.** "All names" is the provider's statement: a missing symbol is treated as nothing scheduled within the window, the documented meaning. ETFs normally have no earnings events.
- **Exits not built yet:** exits before a report and signal-time rechecks belong to the signal step.
- **Not used yet:** `get_earnings_results` (per-ticker history) and `get_equity_historicals`.

## Migration and configuration impact

Additive migration `2026_10_19_000022_create_mcp_earnings_calendar_reads.php` creates one table: unique request identity, a history index, and a PostgreSQL check of status, a 1–31 day window and event count. There is no backfill. The new default-off `ROBINHOOD_MCP_EARNINGS_ENABLED` is forced off in tests. Next M5 work: 5-minute entry and exit signals.
