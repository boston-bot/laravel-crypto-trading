# M5 verified execution-data controls and remaining source gap

October 18, 2026. Source follow-up to `54f0c06`. **Verified execution data is not complete:** stock/ETF and leveraged/inverse classification, corporate-action coverage and the account's fractional-limit contract are still unavailable from the configured source. This increment resolves share-unit semantics, verifies quote/book joins and exposes all missing requirements. It does not authorize or claim real-data paper fills.

## Authoritative contract review

Fetched the full tool descriptions/output schemas through the application's own MCP session using `tools/list` only. The previously stored functional catalog had intentionally stripped prose, so it could not resolve unit meanings. The broker's full `get_equity_price_book` quantity definitions explicitly identify aggregate shares; `updated_at` describes the time the snapshot was taken. The application now records `book_quantity_unit=shares` for this reviewed contract, rather than treating that particular fact as unknown.

These definitions are data-contract evidence, not instructions or trading permission. The code pins a canonical hash of exactly the bid quantity, ask quantity and snapshot-time definitions: **`dd76d07a755062e252175451ccf92f050be453c404de990f637c94563e5d5e60`**. Discovery retains this semantic hash separately from the sanitized functional schema. A missing or changed semantic hash rejects the price-book profile before any tool call, including cases where the JSON field types remain unchanged. Initial use after deployment requires normal capability discovery to populate the new hash; it does not silently trust an old manifest without it. Descriptions/defaults/examples otherwise remain stripped, and remote prose cannot change policy.

The same review clarified that the broker's fractional state describes decimal-quantity eligibility and that session/account-type restrictions exist. It does not provide a reviewed regular-session fractional-limit-order contract. Names/search matches remain insufficient for product classification. No corporate-action coverage tool was found in the inspected catalog.

Public source review also confirmed why substitutes cannot be treated as equivalent coverage:

- [Robinhood fractional-share guidance](https://robinhood.com/us/en/support/articles/fractional-shares/) discusses eligible fractional securities and execution handling; it is not per-instrument, account-bound proof for the agentic fractional-limit combination.
- [Alpaca corporate-action documentation](https://docs.alpaca.markets/us/reference/corporateactions-1) provides a possible authenticated provider interface, with pagination/incomplete-record options and explicit availability delays. No Alpaca account, subscription, API key or adapter was selected or created. Empty or delayed action data cannot automatically establish clearance.
- [Nasdaq Daily List specifications](https://nasdaqtrader.com/content/technicalSupport/specifications/dataproducts/dlcompletespec.pdf) describe enhanced reference fields including leveraged/inverse flags. Reviewing a specification does not supply an entitled, complete current dataset.

## Verified quote/book size join

`BookQuoteMatcher` requires the reviewed semantic pin, correct provider, complete book/base reads, exact symbol, individual share units, strict quoted quantities and exact decimal equality of both bid/ask prices. Both quote component source times and the book snapshot time must precede their own retrieval, be available by the cutoff and meet the applied owner's quote-age limit. Missing sides, stale/future observations, different prices, unreviewed units and altered semantic metadata return explicit unavailable reasons with null sizes. Explicit observed zero stays zero.

Collection retains the semantic hash in independent encrypted book evidence. It only derives `bid_size`/`ask_size` from a successful match using the current applied execution settings' maximum age. No setting or evidence attribute makes classification, leverage/inverse, corporate-action or fractional-limit fields true. Existing local recording/retrieval cutoffs still prevent later arrivals from entering earlier decisions. Matching a snapshot is evidence of displayed size, not a guarantee of available executions or price realism.

## Readiness audit

Added read-only `ExecutionDataReadiness` and a Control Center panel with every allowed symbol's missing requirements. The report distinguishes absent observations, synthetic fixtures, late/wrong-origin inputs, incomplete base reads, independently verified quote/book evidence, observed/denied broker tradability, and unavailable reference/real-fill coverage. Synthetic success and extra payload text cannot become real readiness. Stored snapshots are reevaluated at the current cutoff, so a formerly fresh join can become stale.

The CLI equivalent is:

```sh
php artisan mcp:paper-readiness --account=<local-connection-id>
```

It requires a bound operator-owned connection, performs no network request, creates no records, and returns a nonzero status when blocked. A standalone PostgreSQL invocation uses a repeatable-read, read-only transaction. Nested invocations inherit the caller's transaction rather than trying to change isolation after queries. The dashboard uses the same account-scoped audit. Missing-provider checks are explicit; this report cannot authorize or initialize orders.

## Actual verification

A bounded one-off PHP process used the existing app-owned OAuth/configuration context and development gateway classes, enabled the new profile only in that process, performed normal discovery and one AAPL price-book read. At **23:31:28 UTC / 18:31:28 Chicago**, it returned one complete book with `shares` units and a matching semantic pin. The response is encrypted privately at `/private/tmp/m5-reviewed-book-shares.encrypted` (0600); no real prices/account IDs are committed. This was Sunday, not a regular-session fill check.

A separate read-only audit of the applied runtime universe reported **44 symbols / 0 ready / 44 with no stored market observation**. Direct connectivity checks are not fabricated as locally collected decision inputs. The owner was asked which existing additional provider can supply the remaining reference/action data; no credential, subscription or new paid-provider request was invented.

No runtime `.env`, enablement gate, applied policy, $500 ledger, deployed source or service was changed. Normal MCP capability-catalog publication/OAuth renewal is part of the read path. No model request or broker mutation occurred. The existing real-provider refusal and synthetic-only simulator remain intact.

## Verification and migration impact

No migration/backfill is needed: the new evidence is additive encrypted JSON, and readiness is derived from existing scoped records. No dependency upgrade. Composer validation, targeted Pint, frontend build and diff checks passed. HTTP feature tests render the new panel; no deployed interactive/visual acceptance is claimed.

SQLite full suite: **303 passed / 2,181 assertions, 23 PostgreSQL-only skips** (326 total). Final PostgreSQL full suite: **325 passed / 2,372 assertions, 1 existing PHP-FPM environment skip** (326 total). The first PostgreSQL run caught isolation setup inside the test framework's existing transaction; the command now changes isolation only for its own outer transaction, and the focused PostgreSQL readiness tests passed (**4 / 58 assertions**). All automated external clients are fake/stray-request-blocked.

New tests cover semantic-only drift before tool calls, decimal price/size integrity, independent timing, stale and future sources, wrong symbols/units, explicit zero, encrypted collection without invented classification, synthetic/late/missing report states, account-scoped read-only CLI/UI and no dispatch. PostgreSQL tests use only the guarded `mcp_trading_test` database.

## Completion requirement

To complete actual verified execution data, provide a reviewed source for current stock/ETF and leveraged/inverse classification, complete corporate-action coverage with timestamps/coverage boundaries, and authoritative account/instrument fractional-limit rules. A fresh regular-session observation and held-portfolio session baseline will also be required before future real-data paper execution. No provider is currently configured for those gaps. The application remains blocked rather than substituting guessed values or treating absence as clearance. Next work stays within M5; real-data fills and M6 remain unimplemented/unauthorized respectively.

## Subsequent paper-fill adapter implementation

The [real-data paper fill increment](M5-REAL-DATA-PAPER-FILLS-EVIDENCE.md) supersedes the earlier adapter-unavailable checkpoint: a separately gated whole-share path now connects immutable broker observations to simulated orders/fills with provenance, shared liquidity and transactional recovery. It does not resolve actual classification or corporate-action coverage, activate the runtime, or verify a regular-session fill. Readiness still reports those missing source requirements.

## Subsequent reference-data increment

[Robinhood stock/ETF and dividend-subset reads](M5-REFERENCE-COVERAGE-EVIDENCE.md) now provide observed type values and dividend date warnings through the same application-owned gateway. They do not establish leveraged/inverse exclusion, precise source freshness or complete corporate-action coverage. Runtime fills remain blocked/disabled; the earlier unavailable-classification checkpoint above is historical.
