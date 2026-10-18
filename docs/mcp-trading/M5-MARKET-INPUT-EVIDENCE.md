# M5 broker execution-input evidence

October 18, 2026. Source increment after deployed `b9b5146`. **M5 remains partial: real-data paper fills remain blocked.** This increment implements the independently available read contracts and records exact coverage gaps; it does not manufacture the missing eligibility/action facts or relabel broker data as synthetic. Prior deployment/settings/watchlist work is recorded separately in [deployment evidence](M5-DEPLOYMENT-EVIDENCE.md).

## Reviewed broker reads

Inspected the complete stored app-owned capability catalog and the functional schemas for quotes, symbol search, fundamentals, account tradability and price books. The catalog advertises account tradability and price books; its reviewed output fields do not establish stock-versus-ETF classification, leveraged/inverse exclusions, corporate-action clearance or regular-session fractional-limit eligibility. Names, descriptions, search matches and a generic tradeable flag cannot establish those facts.

Added two closed gateway operations using the existing PHP MCP SDK, fixed-origin transport, encrypted application OAuth connection, schema validation and fingerprints:

| Operation | Tool | Fingerprint |
|---|---|---|
| `tradability` | `get_equity_tradability` | `ed19983123824023cb2476f6b344dc234f9d8da6f20c2cc391b40fd31dee8fa7` |
| `price_book` | `get_equity_price_book` | `87c85f4d4e7b8bf62bbcc20d48e2706ce3f0ec145ea99ce010846405bc5268e4` |

Both are separately disabled by **`ROBINHOOD_MCP_EXECUTION_INPUTS_ENABLED=false`**. The SDK's allowlist excludes them while disabled; disabled profiles do not invalidate existing account/watchlist discovery. No order preview, review, placement, cancellation, account setting or generic remote-tool capability was added.

Tradability requires the current bound account as its server-side argument, exact requested symbol, complete output and unchanged authorization/binding. Output scope is labeled `request_binding`, since the broker does not echo the account number. Preserve the actual boolean tradeable flag, raw fractional-tradability state, extended-hours fractional flag and halt reason. Tradability has no supplied source timestamp, so that remains null; retrieval time is separate. Broker tradeability is not strategy eligibility.

Price books preserve the broker's `updated_at` timestamp and exact-symbol identity. The normalizer checks every displayed level, requires quoted bounded positive decimal prices and nonnegative integer quantities, and selects the highest bid/lowest ask without relying on source ordering. Quantity becomes an exact decimal string, without float conversion. Null/empty sides remain unknown, crossed books are rejected, and repeated numerically equal price levels are refused because the schema lacks venue/order identifiers that would prove distinct liquidity. It does not sum duplicate levels.

The functional schema calls the size field `quantity` without establishing whether its unit is shares, lots or something else. Preserve it as **`broker_quantity_unverified`**, not executable share liquidity. Likewise `updated_at` is the broker book timestamp, not proof of both quote components' venue timestamps or current regular-session fill availability.

## Persistent evidence and application behavior

When both market collection and the new execution-input read gate are explicitly enabled, `PaperMarketInputs` retains each supplemental read in the existing immutable encrypted payload. It records independent completeness, typed sanitized errors, provider, retrieval/source times and the reviewed normalized fields. Account identifiers are excluded from supplemental fields. Supplemental failures do not erase base observations or masquerade as successful fallback data. Final retrieval includes the latest successful read retrieval, while quote freshness still uses the quote's own retrieval and component source times; local availability cutoffs remain in force.

`payload.complete` still describes the original quote/identity reads. Each new read has its own completeness flag; neither that flag nor a true broker-tradeable field fills `eligible`, `asset_type`, `fractional`, `leveraged`, `inverse`, `corporate_actions_clear`, `bid_size` or `ask_size`. A base read may be complete while execution coverage remains incomplete. Existing audit details expose these separately recorded facts. The connection page offers the two symbol-read forms only when their gate is enabled, with the existing authenticated operator routing and private/masked response handling.

There is **no migration or backfill**: these are additive fields in existing encrypted JSON. Existing applied rules, $500 opening cash and runtime policy versions are untouched. No new schedule or worker was added. The synthetic simulator and the real-provider refusal in `PaperOrderHandoff` are unchanged.

## Bounded real verification

A one-off PHP process bootstrapped the deployed application's own OAuth/configuration context, loaded the reviewed gateway classes/mappings from development and enabled the new reads **only in that process**. It read one already-allowlisted public symbol, AAPL, through each of the two tools. No `.env`, config cache, deployed source or persistent service flag changed. Normal gateway catalog publication/OAuth renewal remain part of that path.

- Tradability: complete, one exact record, retrieved **23:17:27 UTC / 18:17:27 Chicago**; `broker_tradeable=true`, raw fractional state `tradable`, source time **unknown**.
- Price book: complete, one exact record, broker `updated_at` and retrieval **23:17:29 UTC / 18:17:29 Chicago**; quantities remain explicitly unverified units.
- This occurred on Sunday, October 18, not during a verified regular trading session. No regular-session fill, price realism or investment eligibility is claimed.

Private normalized read evidence is encrypted at `/private/tmp/m5-new-market-reads.encrypted` (0600), using the existing app encryption context. No account identifiers or real prices/quantities were copied into fixtures. The new fixture contains only sanitized functional schemas. No AI call or broker mutation occurred.

## Verification

Installed lockfile versions retained: Laravel 12.70.0, Brick Math 0.14.8, MCP SDK 0.8.1, PHPUnit 11.5.55 and Vite 7.3.7.

| Check | Result |
|---|---|
| Composer validation | Passed |
| Full SQLite PHPUnit before final duplicate-level guard | **297 passed / 2,114 assertions**, 23 PostgreSQL-only skips (320 total) |
| Full PostgreSQL PHPUnit before final duplicate-level guard | **319 passed / 2,305 assertions**, 1 existing PHP-FPM environment skip (320 total) |
| Final focused SQLite and PostgreSQL suites | **16 tests / 102 assertions passed on each**, including the added duplicate-level regression and persistence tests |
| Targeted Pint | Passed |
| Frontend build | Passed |
| Diff whitespace check | Passed |

Tests use fake external clients and reject unexpected requests. New acceptance covers pinned schemas, actual SDK transport with fakes, account scoping/rebinding, exact symbols, disabled gates, schema drift, null/empty/crossed/duplicate books, strict numeric types, independent supplemental failures, encrypted persistence, source/retrieval provenance, account-ID exclusion and continued unknown execution eligibility/liquidity despite positive broker tradability. PostgreSQL persistence tests use the guarded `mcp_trading_test` database; no runtime DDL ran. The real read above was a separate bounded operator check.

## Precise remaining block

A verified real-data paper fill still requires an authoritative source/adapter for stock/ETF and leveraged/inverse classification, corporate-action coverage, fractional-limit permissions, confirmed book quantity units and suitable point-in-time marks/session baselines. The current MCP contracts do not supply this complete set. No other market-data service or licensed coverage is configured. The owner was asked whether an existing provider can supply those gaps; no credentials were requested in chat or new subscription created.

Real-provider paper order/fill acceptance remains unverified and blocked; do not enable the runner/handoff/simulator to claim readiness. After reviewing the missing source contracts, implement an explicit real-observation fill adapter with preserved provenance, later-observation timing, reservations, deduplication and failure/concurrency tests. Do not simply widen `SimulationQuote` to accept relabeled broker records. Source deployment and recurring collection remain separate operator steps. Next work stays within M5; M6/live actions remain unauthorized.
