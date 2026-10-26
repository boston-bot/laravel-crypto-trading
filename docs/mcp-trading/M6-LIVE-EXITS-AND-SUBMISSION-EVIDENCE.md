# M6 live exits and final submission checks

October 26, 2026. Owner requested implementing the three remaining engineering priorities: revalidate all gates after preview, obtain fresh account trading eligibility, and derive exits from live holdings. This builds on the existing, uncommitted execution gateway/dispatcher without deploying or enabling it. All new behavior is source only, tested with fakes and a dedicated database. No broker/provider request, live policy application, runtime flag change or real order was made.

## Final submission checks

`ExecutionJournal::beforeSend()` now reruns `SubmissionChecks::reason()` after broker preview, using current stored facts while holding the connection/account locks. In addition to the existing write flag, emergency disable, authorization and deadline checks, it revalidates policy fingerprint, buy pause, account eligibility, broker approval mode, market session, quote/book freshness, current entry/exit trigger and provenance, holdings, buying power, risk/position/sleeve limits, daily loss and the entry session-order cap. A denial is recorded as a withdrawal; there was no place request. Calling this boundary inside an outer transaction is refused so the decision commits before the future external call.

The order under review is excluded from its own per-session claim count. Earlier claims still consume the cap, including withdrawn claims. The existing one-open-order-per-account serialization remains. This fixes the otherwise false refusal of the first order when a one-order session cap is rechecked after claiming it.

The check uses stored local reads, not network operations under database locks. No previous preview or approval exempts an order from the final checks. There is necessarily a time interval between releasing local locks and the broker accepting a request; this increment does not claim a distributed transaction with Robinhood.

## Fresh account eligibility

`ROBINHOOD_MCP_ACCOUNT_ELIGIBILITY_ENABLED=false` is a new default-off read capability. When deliberately enabled after deployment, monitoring adds `get_accounts` to its existing shared read session and retains the result in the encrypted snapshot, with authorization generation and the configured account-tool contract fingerprint. A failed optional read does not fail monitoring; it leaves eligibility unknown and denies live entry and exit submission.

`AccountEligibility` requires complete trusted-provider evidence, no continuation, the current schema fingerprint and authorization generation, a timezone-qualified retrieval within five minutes and no later than snapshot publication, and exactly one record for the bound account. That record must report agentic eligibility, active/not deactivated, self-directed individual limited-margin metadata. The existing local `agentic_monitoring` role alone is insufficient. Account metadata has its own broker-retrieval freshness; no missing broker source timestamp is invented.

A missing, failed, partial, stale, mismatched or revoked account read denies execution. The newest snapshot's failed evidence cannot fall back to a prior successful account read.

## Live position and exit state

`LivePositionFacts` reconstructs each account/symbol episode from immutable broker-confirmed execution fills available at the cutoff. It never reads simulated fills or paper balances. Chronological fills determine quantity and the actual weighted entry price; the original deterministic entry signal pins ATR and exit parameters. Partial sells retain the episode and entry basis; a full close ends it. Entry provenance and broker-held quantity must agree, and missing/stale positions or a quantity discrepancy makes the result unavailable. Future execution/local recording times are excluded.

`StrategySignals` remains the shared strategy engine. Its new `recordLiveExits()` path uses live position facts with the existing `SignalRules::exit()` and existing trailing/time/earnings exit logic. Stops cannot decrease relative to stored live evaluations, even across restarts/history revisions. A triggered but incompletely filled exit remains latched. Live stop history is scoped by broker account and episode, separate from paper history. Live entry admission also observes the existing reentry cooldown after a broker-confirmed full close; an unexplained remaining app fill position blocks another buy.

Live exits do not require watchlist membership, buy permission or the paper signal flag. They still need fresh quotes, corporate-action clearance, broker-held shares, account eligibility and the other applicable submission checks. They sell only app-attributed shares. Paper SELL signals cannot authorize a live exit, and live signals cannot enter the paper handoff or fill admission. Paper dashboard/cadence/history consumers exclude live signals.

`MCP_LIVE_EXIT_SIGNALS_ENABLED=false` is a separate default-off gate. The new manual command is:

```text
php artisan mcp:live-exits --account=<local-connection-id>
```

It records deterministic evaluations only, from stored data. It makes no network calls and never dispatches an order. No scheduler or automatic live execution loop was added. Live signal recording reuses the existing connection's portfolio/reference context without initializing or changing virtual cash. Submission re-evaluates the live exit and requires the same episode/fill evidence as the recorded signal.

## Migration and deployment impact

Additive migration `2026_10_26_000033_add_live_position_signal_source.php` adds explicit `paper`/`live` source identity to strategy cycles and signals. Existing records receive the `paper` default; no financial values, payloads, policy versions or cash/position ledgers are backfilled. The cycle identity includes source, allowing paper and live evaluations in the same five-minute slot. A composite foreign key prevents a signal from claiming a different source than its cycle; PostgreSQL constrains live source to Robinhood agentic equities. Rollback refuses while live cycle evidence exists.

Only guarded test database `mcp_trading_test`, schema `mcp_trading`, was migrated. The operator database is unchanged. Future deployment needs the existing backup/restore and owner migration/runtime-grant procedure, with workers quiesced for the source-index change. Both new flags default off and both PHPUnit configurations force them off before individual fake tests opt in. Deployment/activation must be deliberate. No frontend assets or dependencies changed.

## Verification

Composer validation and scoped Pint passed. Focused tests cover changes during preview (policy, buy pause, stale quote, reduced buying power, broker approval mode, account revocation and failed account read), ensuring no place call. Additional tests cover malformed/stale/partial account evidence, actual weighted live entry prices, partial exits, fractional tradability, restarts, exit latching, full-close cooldown, missing/stale quotes, broker quantity disagreement, cutoff isolation, default-off behavior, manual command, source constraints and paper/live ledger isolation. The existing one-order session-cap test proves the current claim does not count against itself during final validation.

During development, an older reconciliation fixture attempted another buy while a partial live position remained. The new check correctly refused it; partial-cancel and rejection scenarios were separated. A fractional exit fixture needed an explicit broker fractional-tradability flag, and the multi-order lifecycle fixture needed sufficient synthetic session capacity to reach the cooldown assertion. These corrections change tests, not trading policy.

Final full-suite results (798 total each):

| Suite | Result |
|---|---|
| SQLite | **716 passed / 82 skips, 4,958 assertions** |
| PostgreSQL | **797 passed / one existing environment skip, 5,637 assertions** |

Composer validation, scoped Pint and whitespace checks passed. No frontend build was required because assets did not change. No unresolved test failures remain.

## Remaining M6 work

- Review and deploy the accumulated gateway plus this increment, with migration `000033`; explicitly configure the new read/evaluation flags when appropriate.
- Finish outstanding token-refresh and restored-backup/in-flight-order fault tests and release review.
- Broker cancel/approval-decline adapters remain absent. An open entry remainder blocks another claim, including an exit, until resolved; a supervised pilot needs owner-managed cancellation until that path is implemented.
- A bounded scheduled execution/reconciliation loop remains unimplemented. The manual exit evaluator and one-order dispatcher do not establish unattended trading readiness.
- Apply explicit owner live capital/risk/order/approval settings, verify the broker approval setting matches, and separately authorize live activation and a supervised pilot. No observation-period waiver, strategy edge or live-pilot success is claimed.

M6 remains partial. Live writes remain disabled at runtime; nothing was deployed or activated by this increment.
