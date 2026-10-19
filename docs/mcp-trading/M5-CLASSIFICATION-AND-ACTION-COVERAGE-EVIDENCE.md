# M5 leveraged/inverse classification and broader corporate-action coverage

October 19, 2026. Source increment, local and uncommitted. It is not deployed or activated. **M5 remains partial; M6 remains unstarted.** No brokerage mutation, AI request, runtime migration or permanent gate change was made.

## Why SEC filings

The reviewed Robinhood catalog has no leveraged/inverse field ([reference coverage](M5-REFERENCE-COVERAGE-EVIDENCE.md)). Alpaca supplies corporate actions only. Product names, margin settings and absent attributes are not proof. The authoritative per-fund statement is the fund's own SEC filing:

- **Form N-CEN Item C.3.c** asks whether a fund "seeks to achieve performance results that are a multiple of an index or other benchmark, the inverse of an index or other benchmark, or a multiple of the inverse of an index or other benchmark." The [N-CEN 3.1 XML schema](https://www.sec.gov/submit-filings/technical-specifications) (`eis_NCEN_Filer.xsd`, `FUND_TYPE`) encodes this one checkbox as `Inverse of a benchmark`. The SEC [N-CEN data-set readme](https://www.sec.gov/files/ncen_readme.pdf) maps the same value to `IS_MULTI_INVERSE_INDEX` (C.3.c). The schema has no separate leveraged value, so this one flag covers 2x/3x long funds as well as inverse funds.
- **Item C.7.n.ii** asks whether the fund is a leveraged/inverse fund excepted by rule 18f-4(c)(5) from the 18f-4(c)(2) leverage-risk limit. The schema encodes it as `Rule 18f-4(c)(2) (17 CFR 270.18f-4(c)(2))`; the readme maps it to `IS_RELYON_RULE_18F_4C2`.
- SEC's public [ticker files](https://www.sec.gov/files/company_tickers_mf.json) map a fund ticker to CIK/series/class. The [submissions API](https://data.sec.gov/submissions/CIK0000320193.json) lists each registrant's N-CEN filings with report period, acceptance time and size.

A real SEC response showed that ticker mappings alone cannot clear a stock. Bank of Montreal's operating registrant lists 45 tickers, including leveraged MicroSectors ETNs such as FNGU and BNKU.

SEC's fair-access policy rejects undeclared automated tools (HTTP 403 observed on `www.sec.gov` Archives). Reads therefore require an operator-declared `SEC_EDGAR_USER_AGENT` that includes a contact address. No key is needed and nothing is purchased.

## Implemented behavior

**Adapter.** `App\Services\MarketData\Sec\EdgarClassifications` sends GET requests only, to four fixed SEC locations: two ticker files, submissions JSON, and `primary_doc.xml` under Archives. It has no configurable host, broker account, order method or credential header. Limits cover response size (8 MB mappings; 40 MB per N-CEN, checked before download from the declared filing size and again on the body), up to 8 N-CEN documents, 2 older submission pages and 150 ms between requests. Failures persist only safe codes such as `access_denied`, `rate_limited`, `response_limit`, `filing_limit` and `invalid_or_unreviewed_response`, never provider bodies. A failure is never reported as absence.

For a fund ticker, the adapter:

1. Requires an `investment` registrant.
2. Considers N-CEN and N-CEN/A filings whose report period is within 455 days (12 months, the 75-day filing deadline and slack), newest period first and then latest acceptance.
3. Streams each XML with XMLReader and rejects DOCTYPE/entities, a wrong namespace, wrong form, CIK or period, and duplicate series.
4. Requires the exact class ID **and** ticker inside the filing's own `sharesOutstanding` list.
5. Treats any fund-type or rule value outside the reviewed schema enumeration as `unreviewed_ncen_value`.

Other series, fund names and service providers are discarded.

For an operating-company ticker, it requires an `operating` registrant whose only SEC-listed ticker is exactly the symbol, with a single ticker-file row. Multi-ticker issuers stay unclassified with `issuer_has_multiple_listed_securities`; this covers ETN shelves, dual share classes and listed preferreds.

**Storage.** Additive migration `2026_10_19_000016` creates `mcp_paper_instrument_classifications`: immutable, account-scoped, encrypted payload, unique request ID, and a PostgreSQL CHECK that a complete row has a basis. `PaperInstrumentClassifications` classifies only symbols in the applied execution allowlist, after operator, connection, binding and authorization-generation checks. Network reads run outside the database transaction; each result is recorded under a fresh lock and authorization check. A repeated request ID returns the stored rows without network calls. A later failed read does not erase the last complete evidence.

**Policy.** `InstrumentEligibility` (gate `MCP_PAPER_CLASSIFICATION_ENABLED=false`) derives quote eligibility from two independent sources:

| Robinhood type (fresh, instrument-ID bound) | Stored SEC evidence (≤ 7 days old, recorded by the cutoff) | Result |
|---|---|---|
| `etf` | Ticker confirmed in an N-CEN no older than 455 days; neither C.3.c nor C.7.n.ii | eligible, `leveraged=false`, `inverse=false` |
| `etf` | C.3.c or C.7.n.ii present | **ineligible**; leverage/inversion individually unknown |
| `stock` | Sole listed ticker of an operating registrant | eligible, `leveraged=false`, `inverse=false` |
| any | Sources disagree, stale, missing, unclassified, after the cutoff or outside the allowlist | unknown (or ineligible for allowlist exclusion) |

When the policy is enabled it replaces the quote-level `eligible`, `asset_type`, `leveraged` and `inverse` values in `PaperMarketInputs::executionReason`, `BrokerSimulationQuote` and readiness. Payload claims cannot override it. A definitive exclusion returns `instrument_ineligible`.

**Readiness.** Readiness is now computed instead of fixed at zero. A symbol reports `data_ready_whole_shares` only when the same stored-input execution gate used by fills passes, fresh quote/book share units match, and broker tradability is observed. New checks: `instrument_eligibility`, derived `leverage_and_inverse`, `execution_gate`. Fractional quantities remain unsupported, and readiness enables nothing.

**Operations.** `php artisan mcp:instrument-classification --account=<local-id> [--symbol=…]` records and prints a redacted summary: basis, reason, C.3.c result, report period and accession. `--scheduled` refreshes every bound operator connection idempotently once per New York day. The schedule (06:20 New York) is registered only when both `SEC_EDGAR_ENABLED` and the existing market-collection switch are on.

## Broader corporate-action coverage

- **Unexposed events reconcile.** An event is unexposed when its required terms are complete, its last reported date is on or before the cutoff, and that date is no later than the symbol's first paper fill (or the symbol was never filled). Any such event, of any type, records an immutable `no_effect` application (`settled_before_paper_exposure`) and releases open orders for the symbol. Corrected or vanished versions of such events no longer freeze the symbol. Previously, every unsupported or corrected event froze the symbol permanently, even with no holdings.
- **Pending unsupported events still block.** While a merger, spin-off, name change or other unsupported event is pending, clearance returns `action_terms_unverified`, so no new exposure can be opened into it. After it settles, processing reconciles it. A reviewed pending split or ordinary dividend does not block an unheld symbol; buyers before its boundary are handled by the existing typed accounting.
- **Special cash dividends** on the regular ex ≤ record ≤ payable calendar, with no due-bill dates and not foreign, now use the ordinary entitlement/payment accounting (`special` recorded on the application). Distributions of 25% or more move the ex-date after payment and use due bills, so they remain frozen for review, as do foreign dividends.
- **Reviewed event terms.** Required terms now follow Alpaca's [official event models](https://github.com/alpacahq/alpaca-py/blob/master/alpaca/data/models/corporate_actions.py) for unit splits, rights distributions, redemptions, name changes (old/new symbol), worthless removals and stock-and-cash mergers (cash rate). Unlisted groups keep `unreviewed_adjustment_contract`. Event fingerprints are unchanged.
- `PaperCorporateActions::supported/settledAt/firstFill/beforeExposure/unexposed` are shared by processing and point-in-time clearance, so the two cannot drift.

Events held across their boundary are unchanged: stock dividends, spin-offs, mergers, name changes, foreign/due-bill dividends, fractional splits and late or corrected events still freeze the symbol for review.

## Verification

Full SQLite: **398 tests / 2,655 assertions, 26 PostgreSQL-only skips**. Full PostgreSQL: **398 tests / 2,873 assertions, 1 existing PHP-FPM environment skip**. New tests:

- SEC adapter (7 tests / 70 assertions): latest-amendment selection, C.3.c and 18f-4(c)(2) marking, the limited-derivatives-user exception staying false, fallback when the newest report omits the series, out-of-window reports never fetched, class/ticker confirmation, unknown enum values, DOCTYPE, wrong namespace, form/CIK/period mismatch, duplicate series, truncated XML, the sole-ticker rule with bank-shelf/ambiguous/non-operating/unmapped cases, disabled and undeclared identity, 403/429/500 without bodies, declared and actual size limits, filing and history-page limits, and older-page reads.
- Policy/collection (5 / 47): ETF and stock eligibility, source disagreement, instrument-ID mismatch, 7-day staleness, point-in-time evidence, leveraged fund overriding payload claims (including `BrokerSimulationQuote`), allowlist refusal, actor/gate/idempotency/encryption/immutability, last-complete evidence after a failed refresh, readiness, CLI, dashboard and the idempotent daily refresh.
- Corporate actions (21 / 125, including 6 new): regular-calendar special dividend paid; foreign, due-bill and merger terms frozen when held; an unheld merger blocks until settled, then reconciles, releases the pending order, stays point in time and survives later purchases; corrected or vanished pre-exposure events do not freeze.
- Alpaca terms (+1): reviewed required fields per event type.
- PostgreSQL race: three concurrent worker processes with one request ID record exactly one immutable classification row in schema `mcp_trading`; the CHECK constraint rejects a complete row without a basis.

All HTTP is faked with stray requests rejected; only the guarded test database is used. Pint passed on all changed files; `composer validate` passed. Only Blade wording changed in views, so no frontend build was needed and no browser visual review was done.

**Real-data checks performed (public data, no account data):**

- The parser read SEC's official N-CEN 3.1 sample filing correctly: period, CIK, series, class/ticker, fund types including the index and master-feeder attributes, and rules.
- Real `data.sec.gov` submissions JSON parsed correctly: ProShares Trust's N-CEN for period 2026-05-31 (4.03 MB, accepted 2026-08-14), BMO's 45-ticker operating registrant, Apple's single ticker, and ProShares' older submissions page, including an N-CEN/A.

**Not performed:** a live `www.sec.gov` N-CEN or ticker-file download, and the end-to-end adapter run. Both need the owner's declared identity. No real symbol was classified, and no runtime eligibility changed.

## Migration and deployment impact

One additive table plus its constraint and indexes; no backfill, and existing rows are untouched. Deploy with the normal reviewed sequence: backup, owner-role migrate, restricted-role grants check. Then add the new private settings and leave both new gates off until reviewed:

```sh
SEC_EDGAR_ENABLED=false
SEC_EDGAR_USER_AGENT="<tool name> <operator contact address>"   # owner's choice; required by SEC fair access
MCP_PAPER_CLASSIFICATION_ENABLED=false
```

Recommended order once deployed: enable `SEC_EDGAR_ENABLED`, run `mcp:instrument-classification --account=<id>` and review all 44 rows, then enable `MCP_PAPER_CLASSIFICATION_ENABLED` only after reviewing them. A populated backup/restore of the new table is untested.

## Known limits and remaining M5 work

- **Remain unknown, so ineligible:** unit-investment-trust ETFs such as SPY, QQQ and DIA (N-CEN Part F has no C.3); ETPs outside the 1940 Act (commodity pools, grantor trusts, ETNs); issuers with more than one listed ticker, such as dual-class shares or listed preferreds. A future reviewed rule could add UIT structure or 10-K cover-page class titles.
- **Residual stock-leg risk:** if Robinhood typed a leveraged product as `STOCK` *and* its registrant listed only that ticker, it could pass. Two independent sources must both be wrong; this is not a regulatory leverage flag for stocks.
- The regulatory definition does not detect embedded leverage that does not seek a benchmark multiple, such as "return-stacked" funds. N-CEN is annual and self-reported, so a strategy change can appear up to roughly 15 months late.
- Exposure-free periods after a first fill are not yet distinguished. A symbol bought and fully sold before an unsupported event still freezes. There is no review/unblock workflow, and held-share stock dividends, spin-offs, mergers, cash-in-lieu and long-history backfills still need typed accounting.
- Still required for M5 acceptance: fractional-limit contract, a regular-session validation run, held-portfolio baselines and performance reports.

Next: the owner chooses `SEC_EDGAR_USER_AGENT`. Then a bounded live read verifies the adapter against real N-CEN filings for the 44 applied symbols, and the result determines how many become eligible before deployment.
