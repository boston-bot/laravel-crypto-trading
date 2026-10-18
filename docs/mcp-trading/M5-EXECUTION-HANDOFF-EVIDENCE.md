# M5 execution settings, recorded inputs and paper handoff

October 18, 2026. Source development following `d3c4a26`; **M5 remains partial**. This increment makes no AI, broker or paid-provider request, changes no private configuration/credentials, and performs no runtime migration, deployment, activation or Git commit. Positive order/fill evidence below uses synthetic test data only.

## Owner settings and authorization

The Control Center now has labeled execution controls with append-only draft/application history. No owner values are invented: unspecified fields stay null, and a complete draft requires a separate authenticated, CSRF-protected apply action. Applying settings does not enable any service, initialize another cash balance or rebalance holdings.

Settings bind to the current immutable allocation-policy application and contain a selected sleeve, symbol allowlist, initial-holdings convention, order/session/duration choices, entry/exit/AI target-weight permissions, fractional permissions, per-share fees, adverse slippage, limit offset and quote-age tolerance. Protected fields, float decimal inputs, textual booleans in JSON, incomplete applications, stale revisions, changed bindings and stale allocation-policy references are rejected. Browser selects are normalized explicitly to booleans; false, zero and unspecified remain distinct. Request IDs are idempotent. Actor identity is derived from authenticated server context, not posted `actor_id`.

Current bounded support is **one selected sleeve per execution version**, up to 100 explicitly allowed symbols, cash-only initial portfolios, regular-session DAY limit orders and per-share fees. This does not implement imported initial holdings, simultaneous independent AI sleeve contracts, arbitrary fees or broad-universe screening. Existing positions/cash are preserved. Applying an allocation-policy revision requires reviewing/applying execution settings for that new policy.

The owner has been asked for actual virtual capital/starting holdings, allocations, position/loss limits, AI discretion, order assumptions and provider preference. **Those choices remain pending; no actual owner mandate or fee/slippage defaults were saved or applied.** Values in fixtures and visual previews are synthetic examples.

## Recorded market inputs and honest coverage

`PaperMarketInputs` has a separately disabled read-only collector using the existing reviewed `BrokerGateway` quote and exact-symbol instrument operations. It verifies provider identity, operation, exact symbol, complete pagination and single-record cardinality. Reads occur outside database transactions; publication rechecks authorization/binding/generation under the connection lock. Duplicate requests reuse the existing record. Incomplete reads retain a sanitized reason and unknown fields.

The current broker normalizer supplies identity, bid/ask prices and their separate source times. The new stored record preserves those component times, quote retrieval, overall retrieval, local recording time, provider, coverage and completeness. The oldest bid/ask source time is the conservative combined quote age; missing source times stay unknown. Each component must precede **its own quote retrieval**, not a later instrument read. Prices must be positive/non-crossed before sizing.

Broker identity/quote coverage does **not** establish stock/ETF eligibility, leveraged/inverse classification, fractional support, displayed bid/ask size or corporate-action clearance. Those remain null. An allowlisted symbol, model output, research text or extra provider metadata cannot fill those gaps. `complete=true` means the reviewed reads completed, not that all execution fields exist. No guessed broker fields/tools or new vendor subscription was introduced.

The collector is implemented and verified with a fake broker transport; no new live read, timestamp contract capture, provider license or live connectivity was verified during this increment. No real market observation was imported into the runtime database. A synthetic normalized input writer exists **only in the testing environment**, with no browser import endpoint; real broker observations cannot be relabeled synthetic for execution.

New input availability timestamps retain microseconds. Historical cutoffs require both retrieval and local recording no later than the cutoff; same-second late arrivals are excluded. Decision request cutoffs preserve microseconds too. Legacy research records have second-resolution availability, so execution-enabled research conservatively excludes the cutoff second. Current unknown/incomplete input does not silently fall back to an older eligible sample.

When execution settings are applied, `StoredDecisionInputs` supplies bounded adapter records, their provenance/IDs and the selected sleeve's separate paper positions. Explicit cash-only initialization establishes zero for absent **paper** holdings; broker holdings are never used as paper quantities. Without applied execution settings, the earlier unknown-position behavior remains. Subjects stay capped at five and sources at ten; this is a stored subset, not rotating broad-market discovery. Research text remains untrusted and cannot grant instrument permissions. M3 research's separate contract is unchanged.

## Proposal-to-order boundary

Decision windows capture an optional immutable execution-version ID alongside allocation policy, account authorization generation and ledger revision. Requests include those execution rules; the applied quote-age choice is used before and after the model response, at handoff and at later fills. A settings change before/during a model call supersedes the run/result. The response schema remains `paper-decision-v1`; changed instructions are versioned separately as `paper-decision-instructions-v2`. Older runs with no captured execution version cannot inherit newly applied execution permissions.

`PaperOrderHandoff` takes an existing run ID and server actor; it never accepts executable model parameters. Behind a separate gate it:

1. Locks account/run/portfolio, checks scope/operator authorization, accepted run status, expiry, current ledger, allocation and execution versions, and revalidates original recorded output against its source map/context.
2. Records WAIT/HOLD as no-order outcomes. Trade proposals need the owner's symbol, direction and AI sizing permissions, original normalized market provenance and current usable marks for all positive paper positions. Existing open orders for the symbol block another target adjustment.
3. Computes the selected sleeve's target quantity from total paper equity and the proposed target percentage. It applies the owner's limit offset to current ask/bid and rounds quantities **down**, rather than half-even, before deriving the buy/sell difference from owned shares. The existing decimal helper retains its former default rounding for other callers.
4. Calls the existing synthetic paper engine with a stable UUID derived from run/symbol, applied fill assumptions and an expiry bounded by proposal/run/session close. Cash/share/exposure/session/fractional/loss checks run again at order acceptance and later fills. Applying different execution settings invalidates linked open orders before further fills and releases remaining resources.
5. Commits all batch outcomes and reservations in one transaction. Each run/symbol has one immutable handoff outcome; duplicate workers/restarts return existing results instead of creating another intent. A crash before commit rolls back orders/reservations/outcomes together; retrying the same run needs no new model call. Internal reservations across one multi-symbol batch do not falsely invalidate that batch's original external ledger check.

Handoff evidence retains original proposal, policy/execution references, market-input IDs, sizing arithmetic and baseline provenance. A prior synthetic order baseline is labeled an assumption. A cash-only portfolio can establish its unchanged cash baseline without inventing security prices. Held positions with no established session baseline remain unavailable; a mid-session marked value is not fabricated as session-open equity. There is no new real valuation/baseline provider.

**All positive handoff/fill tests are synthetic.** The actual broker path fails closed on missing eligibility/size/action coverage. Even future complete non-synthetic input requires an explicit real-provider execution adapter; this increment never converts it into a `SimulationQuote` by changing provenance. Order creation reserves resources and does not fill from the same decision observation; later synthetic observations still drive the existing fill engine. There is no new scheduled real-data fill consumer.

## Controls and browser actions

- `MCP_PAPER_MARKET_INPUTS_ENABLED=false`: permits separately enabled manual read-only collection only.
- `MCP_PAPER_HANDOFF_ENABLED=false`: separate gate for manual and queue/foreground proposal handoff.
- Existing research/decision and offline-simulation flags remain independent and disabled in development configuration. Enabling AI credits or applying settings cannot change them.

The worker and foreground decision path can hand accepted results to the same idempotent service only when handoff is explicitly enabled. The manual handoff action is shown only under that gate and requires paper confirmation. Collection is shown only under its gate. Settings save/apply, collection and handoff routes share existing authentication, operator authorization, response privacy, CSRF and throttling. There is no live-mode switch or broker mutation tool. Failed/unknown handoffs and normalized input blockers appear in scoped, paginated, escaped evidence views. An interrupted handoff can be manually retried by the same run ID until expiry; no stale historical trades are replayed automatically.

## Migration and verification

`2026_10_18_000014_create_mcp_paper_execution_inputs.php` adds three tables (`mcp_paper_execution_versions`, `mcp_paper_market_inputs`, `mcp_paper_order_handoffs`) and nullable execution-version foreign keys on existing M5 decision runs and simulation orders. No backfill, destructive migration or runtime DDL. Earlier standalone simulator request hashes remain compatible when no execution version is attached.

Before testing, read-only inspection confirmed **database `mcp_trading_test`, schema `mcp_trading`, role/schema owner `joe`**. The existing Laravel guard checks database identity/search path before migration; new integration assertions confirm placement. Only the guarded fixture database was migrated. Runtime grants, populated encrypted-record backup/restore verification and deployment of this and prior M5 migrations remain separate acceptance work.

| Check | Final result |
|---|---|
| `composer validate --no-check-publish` | Passed; locked Laravel 12.70.0 / Brick Math 0.14.8 retained |
| Full PostgreSQL PHPUnit | **296 passed / 2,102 assertions**, 1 environment skip (297 total) |
| Full SQLite PHPUnit | **274 passed / 1,911 assertions**, 23 PostgreSQL-only skips (297 total) |
| Targeted Pint | Passed |
| `npm run build` | Passed, locked Vite 7.3.7 |
| `git diff --check` | Passed |
| Synthetic component review | Labeled settings controls and coverage/outcome tables inspected at 1440/500 px; no deployed interactive acceptance claimed |

The PostgreSQL skip remains the existing missing matching PHP-FPM executable. Both new two-process integration tests ran: competing execution drafts and duplicate handoff workers. Feature tests cover authenticated explicit save/apply, encryption/idempotency/staleness, unset/false/zero preservation, protected fields, source completeness/component times/metadata rejection, same-second availability, in-flight execution changes, WAIT, stale ledger, changed inputs, full fake BUY→order→later fill→SELL→later fill, multi-symbol reservations, rollback/retry, and queue gating without another model call. Directed decimal sizing has a unit regression. HTTP is faked or stray-request-blocked; no real model/broker calls are automated tests.

Initial focused tests exposed a precision mismatch between 18-place ledger baselines and the former eight-place scenario-input validator; baseline validation now preserves the ledger's precision. A test helper also sorted random run UUIDs instead of scheduled time; it now selects the latest decision window. The initial PostgreSQL catalog attempt was sandbox-blocked; the approved rerun and test suites passed. No dependency/lockfile upgrade or unrelated framework change. Temporary preview artifacts are `/private/tmp/m5-execution-preview.html`, `/private/tmp/m5-execution-desktop.png` and `/private/tmp/m5-execution-mobile.png`.

## Remaining acceptance and next work

Actual owner settings remain unselected pending the requested answers. Real execution needs reviewed instrument eligibility/classification, liquidity size, corporate-action/fractional evidence, an appropriate market-data adapter, verified held-portfolio session baselines and broader licensed screening/news coverage. AI generation/schema/billing access is still unverified; there was no retry of the earlier 429. Provider quota admission and the reported 50-RPD versus five-minute cadence conflict remain open. General fees, imported holdings, provider-driven fills, corporate-action adjustment/unblock and forward performance/baseline/cadence evaluation remain unfinished. Next work stays in **M5**. M6/live brokerage actions remain unauthorized.
