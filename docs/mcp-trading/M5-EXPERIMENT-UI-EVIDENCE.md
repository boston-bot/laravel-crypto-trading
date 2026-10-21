# M5 experiment interface evidence

October 21, 2026. Source-only UI increment over the deployed durable experiment runner; no migration or runtime activation performed by this increment. M5 remains partial and M6 unstarted.

## Behavior

The authenticated console now has an Experiments page. Operators can queue a guided specification, inspect asynchronous progress and failure history, resume eligible interrupted work, read a JSON report, and create a new comparison using a completed experiment's verified dataset. The controller calls the existing `StrategyExperiments` services. PHP rules, acquisition, checkpointing, comparisons and accounting verification remain in the worker; no LLM or shell processing is added to HTTP requests.

The form accepts the existing bounded symbols/sessions/variants contract and only the supported parameter overrides. Operator identity comes from the authenticated request. CSRF, financial authorization, account scope and authorization generation are enforced. Duplicate request UUIDs retain existing idempotency. Reports/history are scoped to the original operator and current account authorization. Reads work while research gates are disabled; mutation gates remain enforced. Stored checkpoint hashes are checked before displaying trade data. Untrusted strings are escaped.

## Tabular results

The variant comparison shows starting/final equity, return, drawdown, return difference against baseline, completed/open episodes and quote coverage. Capital differences are visible; cross-experiment comparisons retain the runner's withheld-delta rules.

Each selected variant has a paginated trade table grouped from actual simulated fills into position episodes. Quantity-weighted entry/exit prices use exact decimal arithmetic. Rows show the ticker, playbook, quantities, first entry/last partial-fill or final close time, fees, closed net or open marked P&L, entry/exit reasons and expandable fills. Open positions show recorded initial/trailing stops and the last valid historical bid mark. Pending exit intents are labeled separately from filled sales. Unfilled and expired order intents have their own table; they never become fabricated trades.

The strategy has no fixed take-profit target. No projected sell price is invented. Closed episodes do not have persisted terminal stop state, so the UI shows the actual simulated sale rather than rerunning rules to reconstruct it. Missing/stale terminal quotes leave open marked P&L unavailable. Open marked P&L includes partial sale proceeds and recorded fees, but no assumed terminal sale fees. Timestamps are Central. These are historical simulation results, visibly separate from broker-confirmed performance and forward paper fills.

`ExperimentTradeTable` is a presentation/accounting formatter outside the processing-source fingerprint directory. The release does not change frozen computation sources, rule parameters, execution permissions or the forward ledger.

## Verification

- Focused interface and decimal formatter tests: **11 tests / 116 assertions**.
- Full PostgreSQL suite: **610 total, 609 passed, 4,281 assertions, 1 existing PHP-FPM environment skip** using the dedicated guarded test database.
- Full SQLite suite: **610 total, 575 passed, 3,953 assertions, 35 expected PostgreSQL-specific skips**.
- Composer validation, changed-file Pint, Vite frontend build and Git diff whitespace checks passed. Installed lockfiles were used; no dependency upgrades.
- Synthetic desktop browser review of the built form, progress/comparison view and trade/intent tables passed. Preview data came exclusively from fake-client tests served as static local fixtures; no runtime account data or live provider/broker calls were involved. No mobile breakpoint visual review was performed.

Tests cover authentication/CSRF, foreign operator isolation, stale scope/generation, injected fields, repeat submission, unsupported overrides, gate refusal, active-lease refusal, scoped JSON privacy, XSS escaping, completed-dataset reuse without refetching, weighted partial fills, partial sales, reentry, fees, unknown marks and pending exit intents. Existing runner/concurrency/migration tests ran with the full PostgreSQL suite.

## Remaining work

Deploy the UI before using it in the running app. No new migration is required; runtime `000027`, research flags and the dedicated worker were already activated separately. No real queued experiment or regular-session endurance was exercised by this interface increment. Research limitations and forward/live acceptance criteria are unchanged. Next: submit a predefined queued experiment, inspect its evidence, and continue M5 strategy and controlled forward-paper validation. No automatic variant promotion or live trading is authorized.
