# M5 deterministic eligibility source increment

October 19, 2026. Builds on watchlist intake and external-order review. **Source implementation with fail-closed provider gaps; full eligibility acceptance remains incomplete.** No deployment or runtime activation occurred.

## Owner policy and universe

The authenticated Control Center can explicitly apply an immutable, account-scoped eligibility policy separately from the existing execution version. It records the applying operator, original execution-version context, request ID, version, rule version and encrypted thresholds. The starting values are a $5 minimum bid, $10 million minimum 20-session average dollar volume and maximum spread of 0.5% of midpoint. Code bounds are $1–$1,000, $1 million–$100 billion and 0.01%–1%, respectively. Prices/volume/spread cross interfaces as quoted decimal strings. The policy fixes required history at 252 sessions and the volume window at 20 sessions; watchlist cap, risk, cash, costs and operating flags cannot be changed through these fields.

Applying this policy explicitly selects the watchlist rule universe in place of the legacy execution allowlist. Existing execution rows are not rewritten. Without an applied rule policy, legacy source behavior remains unchanged. After selection, disabling `MCP_RULE_ELIGIBILITY_ENABLED` cannot restore legacy entry permission. This flag independently defaults to false and is never enabled by applying settings.

Read-only symbol collection, queued collection validation, SEC classification collection and readiness use the selected universe. In rule mode this is fresh watchlist membership plus paper holdings and the latest known broker holdings. Entry pauses do not remove symbols from the collection universe. Removed/expired membership prevents new entries; holdings retain their data reads and exits even when rule evaluation is disabled. The current bounded five-symbol collector cadence is unchanged: this does **not** claim full-watchlist five-minute market coverage.

The superseded model handoff refuses new processing in rule mode. Its output is not adapted into rule signals or new symbols. No paid model call or strategy engine was added.

## Deterministic checks

Every assessment records separate statuses/reasons for:

- Current selected rule policy, scoped broker-input provenance/auth generation, membership and external-order entry safety.
- Existing Robinhood stock/ETF type plus SEC leverage/inverse evidence, independent of the old allowlist in rule mode.
- Fresh account-bound tradability with the reviewed schema fingerprint; a known non-tradable symbol fails.
- Existing bounded corporate-action reconciliation/clearance, retaining its provider publication-delay limitations.
- Fresh individually timestamped bid/ask prices, minimum bid and maximum spread.
- Explicit US exchange-listing evidence, required history and 20-session average dollar volume.

Spread uses exact cross multiplication, `(ask - bid) * 200 <= maximum_spread_pct * (ask + bid)`, avoiding binary floats and rounding at the threshold. Known failed checks produce `ineligible`; unknown checks produce `unavailable` unless another check is already known to fail. All checks and reasons remain stored together, including unknowns alongside failures.

**Provider gaps remain explicit:** the current reviewed classification records do not explicitly prove US exchange listing, and there is no reviewed split-adjusted daily-bar adapter providing 252 sessions or a computed 20-session dollar-volume average. Those checks remain unavailable. The pure threshold evaluator has a reserved verified-history fact contract tested with synthetic facts, but no production path supplies it. Extra broker payload fields, watchlist fields, model text or owner-supplied thresholds cannot fill these gaps. Consequently no production symbol can currently pass the full rule set. There is no manual evidence-import endpoint.

New collected broker inputs retain their authorization generation. Old inputs without that metadata are unknown to the new evaluator and need a fresh read; there is no inferred backfill. Classifications, quotes and other inputs after the cutoff are excluded. Later execution settings are not borrowed for an earlier cutoff. Current authorization and external-order safety are still rechecked, so these assessments are not a historical strategy replay engine.

## Persistence, command and paper boundary

Additive migration `2026_10_19_000019_create_mcp_rule_eligibility.php` creates two tables:

| Table | Purpose |
|---|---|
| `mcp_eligibility_policies` | Immutable encrypted owner-applied rule parameters, actor, application context, version and request identity |
| `mcp_eligibility_assessments` | Immutable per-symbol results/checks, rule and parameter-policy version, execution version, scope/auth generation, watchlist revision, market input, classification evidence, cutoff and input fingerprint |

Unique indexes enforce policy/request versions and per-symbol logical assessment requests; PostgreSQL also checks positive policy versions and the three permitted result states. Policy application and assessments serialize on the connection row. No backfill. Migration testing used only the guarded `mcp_trading_test` database and verified the `mcp_trading` schema.

`php artisan mcp:eligibility --account=<local-id> [--symbol=DEMO] [--request=<uuid>] [--cutoff=<ISO-time>]` records outcomes from stored inputs only. Omit symbols to inspect the selected read universe. Repeating a request reuses its original cutoff unless one is explicitly supplied; a conflicting cutoff is rejected. The Control Center also records the current universe using a pinned form cutoff and request ID. There is no new schedule, collection request, model request, fill or broker write in either assessment action.

In the existing real-data paper simulator, selected-rule buys require the full current assessment. The initial assessment reference is included when the immutable intent is created, before reservations or fills. Unavailable/ineligible buys are rejected with an audited reason. A rule policy selected after a buy was accepted invalidates that pending buy and releases its cash. Every fill rechecks current rules. Held-position sells bypass entry membership/history checks and retain existing exit/freshness/action/risk checks, including after removal, disabled rule evaluation or a replaced legacy allowlist. There is no signal-based handoff or live execution path yet.

## Verification and limits

Tests cover exact decimal boundaries; invalid/float facts; unknown history and listing; ignored untrusted history claims; verified classification/tradability/action sources; known price/spread/tradability failures; stale quotes and authorization evidence; immutable encrypted policies/results; request idempotency and conflicts; historical cutoffs; version/bounds/field validation; rollback/retry; future/foreign requests; authenticated Control Center apply/assess; CLI behavior; new-watchlist-symbol collection outside the old allowlist; removal/expiry and held-symbol retention; rejected paper buys and reservation release; held sells after selection/removal/disablement/replaced allowlist; and model-handoff refusal. PostgreSQL races run three duplicate policy workers and three duplicate assessment workers, then check schema/result constraints.

Full PostgreSQL: **454 passed / 3,294 assertions, 1 existing PHP-FPM environment skip**. Full SQLite: **426 passed / 3,024 assertions, 29 PostgreSQL-only skips** (455 total each). Final focused PostgreSQL: **29 tests / 223 assertions**, after the explicit listing guard and pinned-cutoff HTTP retry checks. Composer validation, changed-file Pint, frontend build and diff checks accompany the increment. An early paper-boundary test caught an attempt to append evidence after immutable intent creation; the assessment reference is now included at creation. The synthetic disabled-model fixture also needed its actual guarded ID/deduplication schema. Both were corrected before the passing suite runs.

No runtime migration, owner-policy application, deployment, enablement, live broker/provider read, actual model request, brokerage mutation, credentials change or commit. No Hjarni access. Browser visual review and populated backup/restore of the new tables remain untested; the existing schema-wide backup includes them after deployment. Existing unrelated work was preserved.

Next within M5: review and implement a daily-history/liquidity and US-listing source (using existing provider access where possible), then earnings/calendar coverage, daily profiles and deterministic signals. Do not mark full eligibility or M5 complete until those facts are actually available and verified. Existing fee, slippage, virtual-cash and risk-policy versions remain unchanged; realistic costs/fractional sizing remain separate work. M6 remains unstarted and unauthorized.
