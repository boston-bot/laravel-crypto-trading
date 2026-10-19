# M5 broker read efficiency

October 18, 2026 (Chicago; October 19 UTC). First implementation step of the [watchlist-driven M5 design](M5-DESIGN.md#implementation-order). Source and tests only: no deployment, migration, runtime configuration change, live broker read or model call. All reads remain read-only; no brokerage mutation path was added.

## Changed behavior

**Shared MCP sessions.** `BrokerGateway::within($connection, $work)` lets several reads share one MCP session and one tool-catalog check. Reads outside a scope behave as before. A shared session:
- is reopened after `session_max_seconds` (120);
- is reopened after the connection's authorization generation changes;
- is dropped after any session-level failure (timeout, transport, authentication, forbidden, rate limit, malformed or schema change). Data-level failures keep it: stale, incomplete, unsupported and disabled.

Opening a shared session requests an access token valid for at least 150 seconds, so the token cannot expire mid-session. Callers now using it:
- **Account monitor:** one session per run instead of one per read.
- **Paper market-input collector:** one session per symbol job instead of up to six.
- **Robinhood watchlist reader:** one session instead of three.

**Catalog writes.** The encrypted tool catalog is assigned only when its fingerprint differs from the stored row.

*Correction to the earlier gap analysis:* Eloquent already compared the decrypted catalog and skipped identical database writes, except when previous encryption keys are configured. The real per-read cost was re-encrypting and comparing about 83 tool schemas. Both that work and the previous-key rewrite are now gone.

**Batched quotes.** The quotes profile accepts a `symbols` list of up to `max_batch_symbols` (25). The reviewed `get_equity_quotes` schema accepts a symbol array with no stated limit, so 25 is a local bound.
- Invalid batches are refused before any network traffic: too many symbols, duplicates, malformed symbols, mixing `symbol` and `symbols`, or a profile without a reviewed batch bound.
- Rows for unrequested symbols are skipped, and duplicate rows fail the batch.
- `QuoteBatch` splits results into one single-record result per symbol. A batch refused as malformed or incomplete is retried one symbol at a time in the same session, so one bad row affects only its symbol.
- The account monitor now quotes held symbols in batches. Unquoted symbols stay explicit failures, never prices.

**Rate-limit backoff.** After a 429, `BrokerBackoff` stores a per-connection pause in the cache:
- The length is `Retry-After`, with a minimum of 30 seconds when the header is missing or shorter, capped at an hour. A later, shorter pause never shortens the current one.
- Until it expires, every read and catalog discovery for that connection fails locally with `rate_limited` and the remaining seconds, without network traffic.
- The paper collector skips dispatch during the pause, and queued collection jobs skip instead of recording avoidable failures.

**Token refresh without row locks.** The token-refresh request now runs outside any database transaction. A cache lock (`mcp-oauth-refresh:{id}`) serializes refreshes across workers, and a waiting worker re-reads the credential before refreshing. The write-back runs in a short locked transaction and applies only if the credential's refresh token and the connection's authorization generation are unchanged. A reconnect or rotation that happened during the request is therefore never overwritten or wiped. The existing rules are kept:
- revocation or a lost rotation requires reconnect
- a 429 preserves credentials
- there is no blind retry

**Throttles.** A shared `mcp-broker-read` limiter (10 per minute per user) now covers `GET /mcp/robinhood/reads/{operation}` and `POST /discover`, `/accounts` and `/bind`. These routes previously had no throttle.

## Request counts

Each read previously cost about four or five HTTP requests: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`, and a `DELETE` when the server issues a session ID.

| Work | Before | After |
|---|---|---|
| Paper collection job, all six reads enabled | 6 sessions, about 24–30 requests | 1 session, about 9–10 requests |
| Monitor run with 10 held symbols | 13 sessions, about 52–65 requests | 1 session, 7–8 requests |
| Watchlist read | 3 sessions, about 12–15 requests | 1 session, 6–7 requests |

These counts assume single-page reads. They come from the code paths and the synthetic tests, not a live measurement.

## Verification

| Check | Result |
|---|---|
| Full SQLite suite | **385 passed / 2,757 assertions, 26 PostgreSQL-only skips** |
| Full PostgreSQL suite (`phpunit.pgsql.xml`) | **410 passed / 2,975 assertions, 1 existing PHP-FPM environment skip** |
| Cross-process refresh race, run 5 times | Passed each time: two workers, one refresh call, a third process reuses stored credentials |
| `composer validate`, Pint on changed files, `git diff --check` | Passed |

New tests:
- **`SessionReuseTest`** (8 tests), against the real SDK with a synthetic server:
  - one session and one catalog check for three scoped reads
  - no catalog rewrite when unchanged
  - reopen after age, generation change and a 503
  - token refreshed for a shared session's lifetime
  - batch call shape, and invalid batches refused before network
  - fallback isolating one bad symbol
  - 429 pause and minimum backoff
- **OAuth:** no transaction during the refresh request; a reconnect during a successful or revoked refresh is preserved.
- **Monitor:** one session and one batched quote read for three holdings, with the unquoted symbol recorded as unknown.
- **Paper collector:** the rate-limit pause stops dispatch and queued reads, and collection resumes after it expires; both collection reads share one session.
- **Throttle:** per-user limit across the broker-read routes.

The cross-process refresh test now gives its workers the database cache store, matching the runtime.

`BackupCipherTest` teardown removed only top-level files, so every run left a `keys/` directory behind and reported four warnings. It now removes both directories; the suite is warning-free. Earlier runs left about 219 `mcp-cipher-*` temporary directories containing synthetic test keys in the system temp folder. They were not deleted.

## Limits and remaining work

- **No live broker check.** Unverified against Robinhood:
  - the real maximum batch size
  - what a batch returns for an unknown symbol
  - how the server expires idle sessions

  The fallback and session-drop rules are designed to fail safe for each.
- **Collection cadence is unchanged:** at most five symbols every five minutes. Collecting all watchlist symbols per cycle is step 3 of the design.
- **Research quote reads** (`BrokerMarketData`) still use single reads.
- **OAuth `complete()`** still holds the connection row lock during the one-time authorization-code exchange.
- **A single MCP 401** still forces a full reconnect; there is no refresh-and-retry-once.
- **Shared cache store required.** Refresh serialization relies on a cross-process cache store; `CACHE_STORE=database` in `.env.example`, which the existing collector lock already relies on. Without a shared store, concurrent workers may each request a refresh. The write-back check still prevents overwriting or wiping credentials, but a rotated-out refresh token could fail.
- **CSRF on `GET /reads`.** The throttle bounds how often a cross-site link can trigger these reads. It doesn't add CSRF protection.

## Migration and configuration impact

No migration. New code-level settings in `config/robinhood_mcp.php`: `session_max_seconds` (120), `rate_limit_min_backoff_seconds` (30) and the quotes profile's `max_batch_symbols` (25). No environment variables or gates changed. Deploying this source changes how existing enabled reads are performed, not what is read. Next M5 work: Robinhood watchlist intake and code eligibility rules.
