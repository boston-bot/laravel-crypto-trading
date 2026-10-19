# M5 read-only Alpaca corporate-action data

October 18, 2026. The owner selected Alpaca and privately added `ALPACA_KEY` / `ALPACA_SECRET`. Both are present in the development checkout's ignored `.env`, not the separate runtime `.env`. No value was printed, committed, copied into the runtime, or imported from another session. This source-only increment adds a working data adapter; it does not deploy/activate paper processing or complete M5.

## Reviewed provider contract

[Alpaca's corporate-action endpoint](https://docs.alpaca.markets/us/reference/corporateactions-1) supports splits, cash/stock dividends, spin-offs, mergers, redemptions, name changes, removals, rights and other reorganizations. It paginates by `next_page_token`. Date filters and sorting use **process date**, not the date an event becomes effective or was published. The provider explicitly warns of reporting/processing delays. `data_quality=all` includes early incomplete records that the default complete-only filter can suppress.

The [official SDK's action models](https://github.com/alpacahq/alpaca-py/blob/master/alpaca/data/models/corporate_actions.py) were consulted for per-event symbol, rate and date fields; no SDK dependency was added. Laravel/PHP retains ownership of collection, persistence and policy. An Alpaca fractionability or tradability property would not establish Robinhood permissions, and no such mapping was added.

## Implemented behavior

- `config/alpaca_data.php` maps the owner's exact variable names and provides a separate **default-off** `ALPACA_DATA_ENABLED=false` gate. Both PHPUnit configurations force the gate and credentials off/empty before tests opt in with synthetic values.
- `CorporateActions` can send only GET requests to the fixed `https://data.alpaca.markets/v1/corporate-actions` endpoint. There is no configurable destination, account/trading client, order method or brokerage mutation. Keys are headers rather than query strings. Redirects are disabled; timeout, response size, pages, event count, token size and date windows are bounded.
- Requests use one exact symbol, an explicit process-date interval, all event types and `data_quality=all`. Unknown groups, wrong-symbol/out-of-window events, duplicate IDs, invalid dates/numbers, malformed envelopes and repeated cursors prevent complete-query status. Page/record caps, 401/403/429, transfer failures and partial reads retain explicit safe error codes. Error bodies and credential-bearing exceptions are not persisted/logged by this adapter.
- `ExactJsonNumbers` preserves JSON number lexemes as strings before PHP converts decimal numbers into floats. Rates are normalized using Brick Math and the existing exact-decimal boundary; bounded scientific notation is expanded exactly. Split/dividend rates never cross the normalized interface as binary floats.
- Event IDs, type, symbol lineage, dates and rates are preserved; early missing fields and unreviewed adjustment contracts remain explicit. A returned event is not silently applied as a split/dividend/merger. Every event retains `adjustment_supported=false` pending separate ledger implementation/review.
- When explicitly enabled alongside paper market collection, scoped immutable paper inputs retain the supplemental response in **encrypted JSON**, including process-date request bounds, data-quality mode, page count, retrieval time, complete/partial status, events and errors. Base Robinhood quote completeness stays independent. Existing collection idempotency and authorization/binding checks apply. No new table or migration/backfill is needed.
- Readiness now reports Alpaca query status, event count and incomplete/unreviewed event count. A completed query means every requested process-date page was read; it is not a guarantee of all events affecting a holding period. Source publication time stays unknown. Empty results, provider delays, unsupported actions and partial records never set `corporate_actions_clear=true` or grant eligibility.

The collector defaults to a bounded 30-day lookback / 30-day lookahead in New York dates. These are **process-date query bounds**, not an effective-date clearance interval, future event guarantee or historical corporate-action backfill.

## Operator read check

```sh
php artisan mcp:corporate-actions --account=<local-connection-id> --symbol=AAPL --start=2026-01-01 --end=2026-10-18
```

Requires a bound connection owned by an allowed operator and explicitly configured/enabled Alpaca data. It prints redacted query coverage/counts, not keys or event monetary values. It sends no broker mutation, writes no ledger/settings/input rows and installs no schedule. A complete read returns success even when events require review; missing configuration or an incomplete read returns failure. The separate runtime currently lacks these provider keys, so do not claim runtime readiness from the development check.

## Actual verification

A bounded initial GET authenticated successfully with the owner-supplied development credentials (HTTP 200). After implementation, the actual new adapter was checked with its data gate enabled **in that process only**. At **October 18, 19:57:36.603054 Chicago / October 19, 00:57:36.603054 UTC**, a one-page AAPL query for January 1–October 18 returned **three cash-dividend events**, all normalized rates as quoted decimal strings. The result retained unknown publication time and null clearance. This was Sunday, not a regular-session fill validation.

Normalized evidence is encrypted at `/private/tmp/m5-alpaca-adapter-verified.encrypted`, mode 0600, outside Git, using the development application key. No real event, symbol universe, credential or account identifier is embedded in automated fixtures. Development `.env` hash matched before/after; runtime configuration, deployed source, policy, $500 paper ledger, provider gates and all execution schedules remained unchanged. No AI request, brokerage account read/mutation, account creation or new subscription was performed.

## Verification

Full SQLite: **334 passed / 2,384 assertions, 24 PostgreSQL-only skips**. Full PostgreSQL: **357 passed / 2,585 assertions, 1 existing PHP-FPM environment skip** (358 total each). Focused PostgreSQL adapter/reference/readiness tests also passed: **16 / 153 assertions**. Tests use fake HTTP/MCP clients with stray requests rejected and only the guarded test database.

Coverage includes raw decimal/scientific/large JSON numbers and escaped strings; all-quality pagination and early incomplete events; empty data without clearance; wrong symbols/dates/groups; duplicate IDs/cursors; page limits; authentication/access/rate/connection failures; partial evidence and secret redaction; disabled/missing credentials; encrypted scoped/idempotent collection; readiness and the read-only CLI. Initial test-only failures were corrected by guarding an absent first-page token and resetting HTTP fakes between independent error scenarios.

Composer validation, Pint and diff checks passed. No dependency/frontend change in this increment, so the previous frontend build remains applicable. The owner's `.env` is ignored by Git; real secret values were checked against changed source/document/test files before completion.

## Remaining M5 work

The provider connectivity/data-feed gap is now addressed by an actual authorized Alpaca read. Next is business handling: map supported events to reviewed paper adjustments, reconcile late/corrected events, define a bounded observed-data policy using the provider's process/effective date semantics, and retain unknowns for unsupported or incomplete events. This feed cannot truthfully promise immediate knowledge or clearance of every corporate event. Leveraged/inverse classification and Robinhood fractional-limit support remain independent gaps; verified held-portfolio opening baselines and regular-session validation also remain outstanding.

No source was deployed or committed in this increment; no gate was permanently enabled. M5 remains partial and M6 live trading remains unauthorized.

## Subsequent paper-accounting increment

Local source now reconciles ordinary domestic cash dividends and whole-share splits, with a separate derived observed-data policy and review blocks for late/corrected/unsupported events. Raw provider clearance and publication time remain unknown. This does not complete all action accounting or classification. [Accounting evidence and migration impact](M5-CORPORATE-ACTION-ACCOUNTING-EVIDENCE.md).
