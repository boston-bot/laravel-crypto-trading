# M5 Robinhood reference coverage: stock/ETF types and dividend subset

October 18, 2026. Source-only follow-up to the real-data paper-fill implementation. **Complete execution classification and corporate-action coverage remain unfinished.** This increment adds concrete stock/ETF and dividend reads through the existing application-owned Robinhood MCP connection; it does not introduce a provider, subscription, credential source or second integration architecture.

## Catalog and actual contract review

A fresh, complete `tools/list` review found **83 tools**. The scanner's broad `instrument_type=EQUITY` cannot distinguish stocks and ETFs. Its descriptive datapoint catalog and asset-type filter specification establish separate `STOCK` and `ETF` values. A bounded read-only `preview_scan` request for two exact symbols returned a standard `Asset type` column with those values. Nothing was saved: no scan creation/update, brokerage order or approval operation was called.

The catalog exposes dividend amounts/dates through `get_equity_fundamentals`, but no complete split/merger/spin-off/symbol-change corporate-action feed or complete action-coverage boundary. There are also no reviewed leveraged/inverse product flags in the inspected reference fields. Earnings dates, SEC filings, generic instrument names/descriptions and empty dividend fields do not establish either missing fact.

The reviewed functional schema pins are:

| Read | Tool | Fingerprint |
|---|---|---|
| `classification` | `preview_scan` | `02372cd21aa2b6bced7b4fb51943f58d60fbeccd76bb33fd8ffbd899ed28a08b` |
| `dividends` | `get_equity_fundamentals` | `e7c30e46f563db99959205d983638a5f712cd74b3b9927fd6b752392433120f1` |

The schema fixture contains sanitized public tool contracts only, with no broker response, real instrument/account identifier, watchlist or credential. Full raw metadata and the separate dynamic catalog review stay in private temporary files outside Git. Remote descriptions/guide text remain untrusted and cannot change permissions.

## Implementation

Both reads are controlled by the new independent, default-off `ROBINHOOD_MCP_REFERENCE_DATA_ENABLED=false` gate, checked before transport and during capability discovery. They use `Operation`, the existing PHP gateway, schema validation, application OAuth, fixed MCP resource and literal RPC tool allowlist. Both PHPUnit configurations force the new environment gate off unless a test explicitly opts in.

Classification accepts one exact symbol. The application constructs a fixed symbol predicate and requests the standard `Asset type` column; callers cannot supply arbitrary scan expressions, filters or columns. The gateway now supports locally configured nested argument paths. It verifies the returned total equals the available row count, rejects wrong symbols/duplicates/truncation, and requires exactly one record. Only exact `STOCK`/`ETF` cells map to `stock`/`etf`; generic `EQUITY`, unknown values, names and prose cannot be promoted. The returned broker instrument ID must match the separately resolved lookup ID before collection exposes the type.

The dividend reader retains quoted exact-decimal amount, `market_date`, ex-dividend date, record date and payable date. It rejects floats, negative amounts, invalid calendar dates and future market snapshot dates. Missing dates remain unknown. A date sequence inconsistent with ex-date ≤ record-date ≤ payable-date is retained with `inconsistent_dates`; even an ordered sequence is labeled `observed_cycle_unverified`, because the contract does not establish that all values describe the same current event/cycle. It creates no dividend credit or corporate-action adjustment.

The tools supply no precise publication/snapshot timestamp for these reference facts. Source time remains null; `market_date` stays a date rather than becoming invented midnight/current time. Each read independently retains provider, retrieval time, completeness, reviewed contract fingerprint, fields and errors in the immutable encrypted paper input JSON. The containing record retains local recording time. Optional read failures do not masquerade as a failure of structurally complete quote/identity reads.

`ReferenceDataCoverage` derives a stock/ETF observation only with matching symbol/instrument identity, correct source/contract and fresh retrieval evidence. It never sets `eligible=true`, `leveraged=false`, `inverse=false` or `corporate_actions_clear=true`. A null/empty dividend response, past payment, malformed schedule or complete subset response cannot establish absence of other actions. Readiness shows the observed type, dividend subset/warnings and remaining leverage/inverse/action gaps. Extra model/research/broker prose or asserted clearance fields do not override them.

## Actual verification

Using development gateway classes in one temporary process with the existing runtime application's own OAuth context, normal capability discovery and four bounded reads succeeded:

| Symbol/read | UTC retrieval | Result |
|---|---|---|
| AAPL classification | October 19, 00:26:08 | `stock`; precise source time unknown |
| AAPL dividends | October 19, 00:26:09 | October 16 market date; `observed_cycle_unverified` |
| VTI classification | October 19, 00:26:11 | `etf`; precise source time unknown |
| VTI dividends | October 19, 00:26:12 | October 16 market date; `inconsistent_dates` |

These were **October 18, 19:26:08–12 Chicago**, on Sunday; no regular-session validation is claimed. The VTI response's date inconsistency confirms why these fields cannot be treated as authoritative complete current action clearance. Raw results are encrypted at `/private/tmp/m5-reference-gateway-verified.encrypted`, mode 0600, outside Git. Earlier bounded raw specification/sample reviews are likewise private. No private values from those responses are copied into fixtures.

Runtime `.env` and cached configuration hashes were unchanged, the source was not deployed, and no persistent reference/paper/AI/live gate, policy or ledger was changed. Normal capability publication/OAuth renewal is a read-path side effect; no captured response was inserted as a production paper input. HTTP `/ready` returned ready afterward. The 44-symbol portfolio was not silently reclassified or activated from two connectivity checks.

## Verification and migration impact

Full SQLite suite: **326 passed / 2,325 assertions, 24 PostgreSQL-only skips**. Full PostgreSQL suite: **349 passed / 2,526 assertions, 1 existing PHP-FPM environment skip** (350 total each). Focused reference/readiness tests also passed: **13 / 129 assertions**. All automated HTTP/MCP clients were fake with unexpected requests rejected. Tests use only the guarded test database, including existing paper concurrency/recovery tests.

Tests cover the new schema fingerprints/gates, exact fixed arguments, type distinctions without guessed eligibility, unknown types, wrong symbols/IDs, truncation/duplicates, schema drift, precise dividend decimals, malformed/future/inconsistent/missing dates, failed independent reads, encryption, freshness, contract provenance, explicit remaining readiness gaps and refusal to infer clearance from extra payload text.

Composer validation, Pint and diff checks passed. No dependency or frontend change was made in this increment; the previous paper UI build remains applicable. No migration/backfill: new evidence is additive encrypted JSON in the existing immutable inputs. Existing rows and applied settings are preserved.

## Precise remaining acceptance

Stock-versus-ETF observation now has a reviewed Robinhood read path. **Complete classification is still missing authoritative leveraged/inverse exclusion and precise source freshness. Corporate-action clearance is still missing a complete, timestamped event source and coverage interval across splits, mergers, spin-offs, dividends/distributions, symbol changes and relevant delistings.** No tool found in the current authorized catalog supplies that contract. Dividend data is a useful subset, not a replacement.

Completing that acceptance requires an authoritative source exposed through Robinhood or an explicitly selected additional dataset; it cannot be implemented truthfully by guessing fields or clearing an empty response. No additional provider has been selected or funded. Next stays within M5: establish that source contract, then validate current regular-session inputs, held-portfolio opening baselines and action adjustments. Fractional limits remain independently unverified. Runtime paper fills remain disabled; M6 live execution remains unauthorized.

## Subsequent owner-selected provider

The owner subsequently configured Alpaca development keys. The [read-only action adapter](M5-ALPACA-DATA-EVIDENCE.md) now has successful real access, exact-decimal event parsing and encrypted query coverage. This supersedes the earlier provider-unselected checkpoint above. It does not complete action adjustments, late-event policy or leveraged/inverse classification, and runtime fills remain disabled.
