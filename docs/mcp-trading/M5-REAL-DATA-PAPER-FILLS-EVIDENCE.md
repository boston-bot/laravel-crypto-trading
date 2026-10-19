# M5 real-data paper fill path

October 18, 2026. Source implementation only; uncommitted and undeployed at this checkpoint. This increment connects stored broker observations to the existing simulated ledger and proposal handoff. **Actual portfolio readiness remains blocked by missing reviewed classification and corporate-action coverage.** It does not change the owner's applied settings, runtime gates or virtual balance, claim a regular-session market validation, or complete M5.

## Behavior

`BrokerSimulationQuote::fromStored` accepts only an immutable `broker_read` input from the existing application-owned Robinhood gateway, scoped to the selected connection/account. There is no operator JSON import, model-supplied quote, or eligibility override. It requires current normalized execution eligibility and corporate-action clearance, fresh quote components, matched book prices/share units/semantic pin, fresh positive account tradability, and source/retrieval/local recording availability at the cutoff. Optional text, instrument names and watchlist membership cannot establish those facts. The current collector leaves the unavailable reference facts null, so its actual records continue to fail closed.

The adapter uses the shared bounded decimal/quote validator internally; externally recorded observations retain `robinhood_paper_observation` provenance, provider, immutable market-input ID, quote/book/tradability times and semantic evidence. It rechecks the stored record, newest available input, authorization generation, scope and semantic pin at the locked fill boundary. A newer unusable record blocks replay of an older favorable quote.

Real-data orders require applied execution settings and owner symbol, sleeve, side and order-type permissions. Proposal handoff validates both the original decision input at its historical cutoff and current inputs for the candidate and every held symbol. The application retains its decimal sizing, verified-session/day-order checks, cash-only inception baseline, position/sleeve/cash/daily-loss rules and fee/slippage assumptions. Missing held-portfolio session-opening baselines still block new-session handoff.

The existing ledger engine now accepts either synthetic or broker observations. Orders and their marks must consistently use one observation mode, and an observation cannot switch an accepted order between modes. Separate gates control synthetic versus real-data processing. All cash, positions, events and fills remain paper records, distinct from broker-confirmed execution. UI order/event labels expose the observation mode; combined paper accounting is not a current valuation, benchmark result or proof of strategy performance.

Real-data processing currently uses **whole shares only**. Unknown fractional-limit support remains null; it is not recorded as broker denial or inferred from a generic fractional eligibility flag. Handoff rounds quantities to whole shares, and manually supplied fractional scenario quantities are rejected even when the owner's settings permit fractional orders. Supporting verified fractional limits remains later M5 work.

## Fill and recovery rules

- Acceptance reserves resources without filling. A fill needs a source observation after acceptance and the prior source watermark, within a verified regular session and the applied maximum age.
- Bid/ask prices receive the configured adverse slippage; bounded/limit orders can remain unfilled. Exact decimal fees and partial-fill accounting reuse the existing tested ledger implementation.
- The broker book's symbol and canonical snapshot timestamp identify one liquidity sample. Another poll or persisted input ID cannot replenish its size. Competing orders share the sample under the same connection/portfolio locks. Conflicting prices/sizes for one snapshot key are rejected.
- Unknown or unusable inputs append deduplicated, encrypted unfilled evidence without consuming cash or shares. Repeating a processed observation cannot create a second fill.
- Transactions roll back observations, events, positions, balances and cash entries together. Restarted workers use persisted keys and shared liquidity, not process memory.
- Expiry and policy/settings/authorization changes release reservations with their actual lifecycle reason without requiring fresh inputs. Existing cancellation/expiry operations remain available when fill modes are disabled.
- A detected corporate action blocks an existing position pending a separately reviewed adjustment; a later clear read does not remove that block. Automatic split/merger/dividend adjustments are still unimplemented.

## Explicit processing and gates

New default-off setting:

```dotenv
MCP_PAPER_REAL_DATA_FILLS_ENABLED=false
```

Collection, broker execution-input reads, proposal decisions and handoff retain their own independent gates. Enabling one does not enable the others, research, legacy schedules or live writes. Both test configurations force the new environment gate off; tests opt in locally with fake data.

Once source coverage is established, the explicit local processing command is:

```sh
php artisan mcp:paper-fills --account=<local-connection-id> --order=<existing-real-data-paper-order-id>
```

It derives the actor from an allowed operator-owned bound connection and processes one scoped existing order. It accepts no quantity, executable broker parameters or free-form market evidence. The command performs no network call, AI call or data collection and installs no schedule. Successful processing may leave an order open/partial; inspect its immutable unfilled/fill evidence in the Control Center. Missing arguments or a disabled gate return failure. The CLI does not create an order or grant permissions.

`mcp:paper-readiness` continues to report the actual source gaps. Its adapter check now reports implemented/disabled rather than claiming that no adapter exists. The report still conservatively returns zero ready symbols while reviewed reference coverage is absent.

## Verification

All automated external clients were faked with unexpected HTTP rejected. Positive tests use an explicitly hypothetical future reference-data contract in `BrokerInputFixture`; **they do not establish that Robinhood currently supplies classification or complete action clearance**. No real broker or model call, subscription, credential import or production ledger mutation occurred.

Full suite before the final timestamp/supersession hardening: SQLite **316 passed / 2,249 assertions, 24 PostgreSQL-only skips**; PostgreSQL **339 passed / 2,450 assertions, 1 existing PHP-FPM environment skip** (340 total each). Final targeted checks after that hardening and its additional regression: SQLite **29 passed / 140 assertions**, PostgreSQL **33 passed / 183 assertions**, including actual competing/duplicate worker processes against the guarded `mcp_trading_test` database. Those final subsets include handoff and the existing synthetic concurrency regressions.

Coverage includes proposal → real-data paper order → later simulated fill, partial buy/sell accounting and retained provenance, same-snapshot re-polls, competing orders and duplicate workers, no fill at acceptance time, unmarketable limits, missing/stale/mismatched/semantic-drift inputs, unknown corporate actions, malformed tradability time, permanent action blocks, changed settings/authorization, account rebinding, foreign actors, disabled gates, rollback/retry, unavailable historical inputs and rejection of superseded favorable evidence. One early test expectation named the wrong cutoff rejection; the implementation correctly returned `market_input_after_cutoff`, and the assertion was corrected.

Composer validation, Pint, frontend build (locked Vite 7.3.7) and diff checks passed. Existing locked Laravel 12.70.0 / Brick Math 0.14.8 / PHPUnit 11.5.55 remain unchanged. HTTP feature tests cover rendered paper provenance labels; no deployed browser acceptance is claimed.

## Migration impact and remaining acceptance

No migration or backfill: existing immutable encrypted input/order/observation/event JSON carries the new provenance and keys. Existing synthetic order identity remains compatible; real-data identities include their observation mode. No existing runtime rows are relabeled.

Next remains **M5**: finish reviewing Robinhood reference/action coverage through the existing architecture, implement any substantiated normalized fields with source/coverage boundaries, validate a fresh regular-session observation, and add verified held-portfolio opening baselines/corporate-action adjustments. Fractional limits remain blocked until their broker contract is established. Deployment and recurring paper processing remain separate operator actions. M6 live execution is unstarted and unauthorized.

## Subsequent reference-data increment

[Robinhood stock/ETF and dividend-subset reads](M5-REFERENCE-COVERAGE-EVIDENCE.md) now provide observed type values and dividend date warnings through the same application-owned gateway. They do not establish leveraged/inverse exclusion, precise source freshness or complete corporate-action coverage. Runtime fills remain blocked/disabled; the earlier unavailable-classification checkpoint above is historical.
