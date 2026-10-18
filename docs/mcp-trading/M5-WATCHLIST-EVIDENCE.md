# M5 Robinhood watchlist reads and percentage fees

October 18, 2026. M5 source increment following `aa27f46`, including the owner's [actual mandate choices](M5-OWNER-MANDATE.md). No deployment, migration, recurring activation, model request or brokerage mutation. The bounded real watchlist read below used the application's existing authorized MCP/OAuth connection.

## Existing integration extended

`BrokerGateway`, the PHP MCP SDK, `SessionFactory`, the fixed-origin `RpcHttpClient`, encrypted app-owned OAuth credentials and schema fingerprint checks remain the read path. Two closed `Operation` cases now map to reviewed schemas from the runtime's stored capability catalog:

| Operation | Tool | Fingerprint |
|---|---|---|
| `watchlists` | `get_watchlists` | `b7238914b576828e632bdf7225bbc8c66b7ee1e391886405964d673ec9fa4fde` |
| `watchlist_items` | `get_watchlist_items` | `08d364ccb828f8fcfd5eafc467e21bd62663ba04828504295cfd15ebbdfbaf6e` |

`ROBINHOOD_MCP_WATCHLISTS_ENABLED=false` is a separate default-off read gate. Disabled mappings do not invalidate normal account discovery. No create/update/add/remove/follow/unfollow tool is allowed. Generic read checks accept a validated `list_id` only for the reviewed mapping; no generic remote-tool endpoint exists.

`RobinhoodWatchlists::named()` requires a bound connection, resolves one exact case-insensitive name, reads its items, checks the advertised count and rechecks list identity/count afterward. Missing/ambiguous names, null items, duplicate object IDs, count drift, partial results, schema drift and account-binding/auth-generation changes refuse a complete named result. Empty lists with verified zero counts are known empty. These separate reads do not provide an atomic broker snapshot; same-count replacements between reads cannot be excluded.

Watchlists are user-level data, not agentic-account holdings. The output explicitly labels that scope and the item list's request binding. It preserves object type and missing symbols; crypto/options/other items are not silently labeled equities. Source timestamps are unknown/null because the reviewed contracts supply none. Retrieval times, per-read provenance and completeness are included. Watchlist membership never proves instrument eligibility and never automatically changes an applied symbol allowlist.

The authenticated operator connection page offers a named read, disabled until configured. `GET /mcp/robinhood/watchlists/read?name=On%20Watch` uses existing session/operator authorization, response privacy, per-user connection selection and a five-per-minute throttle. It performs reads only; no policy write. The command equivalent is:

```sh
php artisan mcp:watchlist --account=<local-connection-id> --name='On Watch'
```

The command refuses absent or non-operator-owned connection selections, emits typed sanitized failures, and does not enable schedules. Reading does not persist a paper policy or populate the independent local research watchlist. There is no scheduled watchlist polling in this increment.

## Actual read evidence

The development database has no authorized connection; the existing deployed app holds the original connection. A bounded one-off PHP process bootstrapped that app's own configuration and OAuth service, loaded the reviewed gateway classes/mappings from development and enabled watchlist reads **only in that process**. No `.env`, config cache, deployed code or running-service setting was edited. Normal gateway catalog publication and any necessary OAuth renewal remain part of this path. No credentials were copied from a browser, chat or connector.

The owner-requested “On Watch” read succeeded at **2026-10-18 20:15:57 UTC** (15:15:57 Chicago): **44 items**, all reporting `object_type=instrument` with symbols; the subsequent list read confirmed the same ID/name/count. Source times remain unknown. No broker objects were created, modified, followed or deleted. The exact returned object IDs and symbols were retained only in a private encrypted temporary snapshot (`/private/tmp/m5-on-watch.encrypted`, 0600), not in Git or test fixtures. This snapshot is temporary review evidence, not authoritative application policy.

An attempted browser visit before the owner clarified the desired integration was rejected by the browser approval gate. It was abandoned. The owner then explicitly requested the existing agentic-account integration; the successful verification above uses that app-owned route.

## Percentage-fee implementation

Execution settings and simulation assumptions now accept a quoted decimal `fee_bps` (0–10000 basis points), additive to `fee_per_share`. The owner's supplied 1% maps to `fee_bps="100"`, `fee_per_share="0"`. This is a simulation choice, not a broker fee claim.

Fees use actual fill notional on buys and sells. Buy commitments reserve against the limit-price bound plus both fee components. Fees round down at 18 ledger decimals; reservations round upward. Partial fills release the difference between the previous reserve and the remaining commitment, preventing accumulated rounding dust or negative reservations. Fees enter buy cost basis, sell proceeds, realized P&L and existing fee reports. Proposal handoff carries the immutable applied rate; submission refuses a mismatched rate.

Legacy execution drafts lacking `fee_bps` retain their per-share-only semantics as zero percentage fee. Explicitly unspecified new fee fields still block complete application. Legacy standalone assumption serialization omits absent `fee_bps`, preserving prior idempotency hashes. Existing encrypted JSON fields carry the additive setting; **no migration or backfill is required**.

The owner delegated the remaining choices: 100% active allocation, regular-session DAY limits and the supplied 1% interpreted per fill value. A complete $500 mandate and execution review bundle with the 44-symbol snapshot passed the application validators and was encrypted privately at `/private/tmp/m5-owner-settings-review.encrypted` (0600). No actor was impersonated and no runtime policy/application/ledger entry was created. M5 source/migrations still need deployment and settings application through the normal operator workflow.

## Verification

| Check | Result |
|---|---|
| Composer validation | Passed; lockfiles unchanged |
| Full SQLite PHPUnit | 311 total, **288 passed / 2,047 assertions**, 23 PostgreSQL-only skips |
| Full PostgreSQL PHPUnit | 310 total, **309 passed / 2,228 assertions**, 1 existing PHP-FPM environment skip; before the final rounding regression was added |
| Final PostgreSQL offline-simulator tests | **22 passed / 162 assertions**, including the added fractional rounding regression |
| Targeted Pint | Passed |
| Frontend build | Passed, locked Vite 7.3.7 |
| Diff whitespace check | Passed |

New tests exercise pinned schemas, the real SDK with fake HTTP, default-off reads, unknown/empty/ambiguous/duplicate/drifting membership, authorization/rebinding, command selection, and continued blocking of watchlist mutations. Percentage-fee tests cover strict decimal/bound validation, encrypted settings, proposal-to-order propagation, partial BUY/SELL accounting, duplicate observation recovery and sub-ledger fee rounding/reservation release. All automated external requests are faked; the real read was a separate owner-authorized operator check.

An initial full SQLite run correctly rejected the new read form until its route was added to the reviewed console action list. An initial new fee-report assertion omitted the required paper-state argument; the test was corrected. Final checks above pass. No dependency upgrade or production DDL occurred.

Next work remains **M5**: deploy/apply reviewed settings, complete real instrument eligibility/liquidity/corporate-action/fractional coverage and real-data fill processing, resolve quota admission versus five-minute cadence, and verify model generation after credits are available. M6/live execution remains unauthorized.
