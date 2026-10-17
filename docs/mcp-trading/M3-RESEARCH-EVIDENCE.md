# M3 research and decision-support evidence

Implemented October 16, 2026. M3 only. No unattended deployment, AI provider call, vendor subscription or brokerage mutation occurred. Existing M0–M2 and unrelated working-tree changes were preserved. No dependency versions changed; no commit was created.

## Delivered behavior

- **Market/news adapter.** `MarketDataProvider` defines timestamped reads with a per-field coverage manifest. The only configured source, `BrokerMarketData`, reuses the reviewed read-only broker gateway for equity quotes (broker component source times; regular/extended session labeled) and exact equity-symbol lookup. Market sessions, completed daily bars, corporate actions, fundamentals, earnings dates and news are declared unsupported and raise the existing typed unsupported-capability error. The broker catalog advertises earnings tools, but no reviewed mapping or captured contract exists, so none is used. Operator-entered evidence is the only news path: untrusted excerpts with operator-stated publication time and retrieval time at entry, limited to held, locally watched or thesis symbols. Coverage is recorded in `config/research.php` and shown on the Research page.
- **Material events and cache.** Evidence, operator requests, thesis invalidation breaches, due thesis reviews and held symbols without an open thesis become deduplicated events (one row per account scope and SHA-256 key; repeats only increment occurrences). Identical evidence resolves to one stored source. Results are cached for 24 hours on material inputs only: subject, thesis version, evidence content hashes, prompt version, model and, for breaches, the breach event. A changing quote alone does not cause another model call. Pending events expire after 72 hours instead of replaying stale work.
- **Thesis targeting.** Breach, review-due and targeted operator requests record the thesis version they concern and research the latest version of that thesis; a thesis closed before processing is skipped. Symbol-level work (evidence, untargeted requests) reviews the most recently revised open thesis, so a symbol can carry separate long-term and active theses. Closed theses are never researched.
- **Per-subject serialization and recovery.** One active run per account subject is enforced by a nullable unique `active_subject` column under connection-row locks; a competing event stays pending as `subject_busy`. Runs are fenced by claim and status, not broker health. A lease that lapses without a competitor keeps its paid result. Each research cycle sweeps crashed workers' expired runs, even after an account rebinding: the run is abandoned, its event failed, and its outstanding reservation charged in full. A temporarily disconnected broker defers work as `connection_unavailable` instead of discarding it. Only a changed account binding is treated as `scope_changed`.
- **API-backed research.** `ResearchModel` is a text-in, schema-out boundary with no tools. `OpenAiResearchModel` (the provider the plan cites for structured outputs) posts to the fixed Responses endpoint with a strict JSON schema, `store: false`, no tools, bounded time and size, and no redirects. Tool-call output items are refused. Each run stores prompt version `research-v1`, a prompt/schema hash, the request context hash, model, attempts, token usage, reserved and actual cost, and cost basis. The model receives the subject, thesis version, holding weight as a percentage of observed equity exposure, and sources with opaque per-request IDs (`S1`…). It never receives account identifiers, tokens or absolute balances; holdings are `null` (unknown), not empty, when no complete snapshot exists. Untrusted data is JSON-encoded with `<`/`>` escaped inside one delimited block.
- **Application validation decides validity.** Checks cover exact key sets, enums, lengths and counts, and ISO-8601 timestamps. Every fact must cite supplied source IDs, with an as-of no later than its cited sources; the IDs map back to stored source rows. The proposal symbol must be the subject. Claims need evidence and must match whether a thesis exists. Price and close invalidations need `lte`/`gte` and a positive quoted decimal. Review times must fall within 180 days. Unicode format characters (zero-width, bidirectional overrides) are rejected. Conservative patterns, run on NFKC/space-normalized text, reject execution, tool, policy-change and injection-echo text; a false positive fails safe as a visible rejected run. Only format failures get the single repair attempt. Refusals, incomplete output, semantic rejections and timeouts fail visibly.
- **Budget.** A per-UTC-month ledger row is locked for every reservation. Committed spend is the reviewed fixed monthly AI/data subscriptions plus settled usage plus outstanding reservations. Each reservation is a conservative upper bound: input bytes plus maximum output tokens, at reviewed rates.
  - Over-envelope reservations are refused.
  - At 80%, routine events are refused while material events continue, and one incident is raised.
  - Requests the provider rejected settle at zero: 401, 403, 429, and 400/404/422 (the last also raises a configuration incident).
  - Timeouts, transport loss, missing usage and abandoned runs settle at the full reservation.
  - Missing pricing or fixed-cost configuration makes research unavailable. Envelopes above $150 are rejected.

  Monitoring never depends on research or budget state.
- **Research/Watchlist and thesis-review UI.** The Research page shows readiness, budget and coverage. It lists validated research, showing each fact's cited evidence with source time, retrieval time and render-time quote freshness, plus events and runs, failures included. Proposals are labeled non-executable. Operators can:
  - record evidence;
  - request research, optionally for a specific open thesis;
  - keep a local watchlist (never the broker's);
  - author append-only thesis versions that cite sources;
  - adopt validated research as a new thesis version, with the actor taken from the session.

  Adoption refuses expired results, superseded or closed theses, and repeat submissions. No route creates an order intent, order review, broker request or policy change.
- **Summaries.** `mcp:summary` produces deterministic, immutable records for **closed** New York days or ISO weeks only (by default the previous day or week). Open and future periods are refused, so an incomplete record is never frozen. Each summary draws on stored observations, incidents, research runs and that period's budget, with no AI call. Performance keeps its stored unavailability reason. Benchmark comparison is explicitly unavailable. Limitations (no annualization, no calibrated probabilities, broker observations only) are stored with each summary.

## Commands, scheduling and configuration

```bash
php artisan mcp:research --once          # sweep, detect and queue on mcp_research; nothing while disabled
php artisan mcp:research --once --sync   # same, processed in the foreground (includes already-queued events)
php artisan mcp:summary --kind=daily [--account=<local-id>] [--date=YYYY-MM-DD]
php artisan mcp:summary --kind=weekly
```

`MCP_RESEARCH_ENABLED=false` by default. The research schedule registers only when research is enabled; the summary schedules (00:15 and Monday 00:30 New York) register only when monitoring is enabled. Nothing is registered at runtime. Research jobs use the separate `mcp_research` database queue connection (`retry_after=420`, job timeout 300, one try), so slow research cannot occupy monitoring workers. Queued events that may have been lost are redispatched after 15 minutes; processing is claim-protected, so duplicates are harmless.

Required owner settings:
- `MCP_RESEARCH_MODEL`;
- an application-specific `MCP_RESEARCH_API_KEY`;
- `MCP_RESEARCH_INPUT_COST_PER_MTOK` and `MCP_RESEARCH_OUTPUT_COST_PER_MTOK`;
- `MCP_RESEARCH_FIXED_MONTHLY_COSTS`;
- optionally `MCP_RESEARCH_MONTHLY_ENVELOPE` (default 100.00, ceiling 150.00).

## Verification

| Check | Result |
|---|---|
| Baseline before M3 | SQLite 93 tests / 515 assertions (4 PostgreSQL-only skips); PostgreSQL 93 / 562 |
| `vendor/bin/phpunit` | **152 tests / 843 assertions passed; 6 PostgreSQL-only skips** |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | **152 tests / 912 assertions passed** (run twice), guarded `mcp_trading_test` / `"mcp_trading"` only |
| Research-specific suites | 57 SQLite tests (feature, contract, unit) plus 2 PostgreSQL multi-process tests (the multi-process tests were stable across three consecutive runs) |
| `composer validate --no-check-publish`, `npm run build`, Pint on changed PHP, `git diff --check` | Passed |

Tests cover the acceptance criteria and failure paths:
- **Unchanged events:** repeated evidence, requests, detection and cache hits make no further model calls; only cache expiry or new evidence does.
- **Injected instructions:** article text cannot reach tools, policy, theses, the outbox or broker writes. Request bodies contain no account IDs, tokens or balances, and a closing delimiter inside an excerpt stays escaped.
- **Malformed output:** gets one repair, then a visible failure; refusal and incomplete output fail visibly without repair.
- **Budget:** exhaustion stops calls while monitoring completes. Missing or invalid cost settings block research. The 80% reduction and its incident are covered.
- **Evidence links:** invented source IDs are rejected, and stored facts map to source records shown in the UI.
- **Old quotes:** a stale quote stays labeled stale, and a "current" claim about it is rejected. The UI recomputes freshness at render time.
- **Recovery:** per-subject serialization; abandoned leases; the sweeper after a rebinding (no incident in the new scope); a lapsed lease keeping its paid result; deferral on a temporary disconnect; rebinding during a call blocking publication.
- **Thesis targeting:** with closed, long-term and active theses on one symbol, each event researches the right thesis. Adoption refuses repeat, superseded and expired results.
- **Provider and adapter:** typed timeout, 401/403/400/404/422/429/5xx and oversize failures, with unbilled settlement; strict, unstored, tool-free request shape.
- **Controls:** scheduler and queue separation, redispatch, and foreground processing; summaries for closed periods only, idempotent and immutable, with labeled limitations; CSRF, operator gate, cross-account isolation and session actor on every research route.
- **PostgreSQL multi-process:** two workers on one subject produce exactly one model call, and concurrent reservations never exceed the envelope.

An independent review of the first implementation found no overspend, double release or authorization flaw. It did confirm several defects: leaked reservations without a sweeper, wrong-thesis selection, disconnect treated as a scope change, frozen open-period summaries, lapsed-lease result loss, charges for rejected requests, unknown sent as zero, and Unicode format characters. All were fixed and regression-tested before the final runs above. No browser visual review was performed; HTML rendering is covered by HTTP tests.

## Migration impact

Two additive migrations, no existing table changed, no backfill:
- `2026_10_16_000007_create_mcp_research_tables` creates `mcp_research_sources`, `mcp_research_events`, `mcp_research_runs`, `mcp_research_budgets`, `mcp_summaries` and `mcp_watchlist_items`.
- `2026_10_16_000008_add_thesis_reference_to_mcp_research_events` adds one nullable `thesis_version_id` column.

Encrypted casts protect source payloads, research results, summaries and watchlist notes. Money columns are `numeric(38,18)`, and arithmetic uses the M0 `Decimal` boundary (a `minus()` helper was added). Both migrations define fixture/development rollbacks; no runtime rollback was run.

**Runtime:**
- `mcp:doctor` verified `postgres` / `mcp_trading`, login and owner `admin`.
- When first checked, `000007` was **already applied as batch 4**. This session's commands did not apply it. The background review agent's only migrate targeted the guarded `mcp_trading_test` fixture. The six tables were empty and pre-existing rows were unchanged (1 user, 1 connection, 2 monitoring runs). The operator should confirm whether they applied it.
- This session applied `000008` as **batch 5**, after `--pretend` showed the single `ALTER TABLE … ADD COLUMN "thesis_version_id" bigint NULL`, using `migrate --no-interaction` in the `local` environment without `--force`.
- Afterwards: the column is present, there are zero tables in `public`, and research tables are empty. `schedule:list` reports no tasks, `/ready` returns 200, and the dashboard still requires login.

Research, monitoring, legacy schedules, legacy trading and brokerage writes remain **disabled**, and no research credential is configured. No runtime broker read or AI call was made by this work.

## Remaining verification and limitations

- **No real AI call was made.** The owner has not supplied a model, application credential or reviewed pricing. The OpenAI adapter is verified only against its documented request/response shape, using fake HTTP. Before enabling research, the owner must:
  1. choose a model;
  2. configure a dedicated credential with provider-side limits;
  3. enter reviewed per-token prices and all fixed subscriptions;
  4. run a bounded manual `mcp:research --once --sync` and record the outcome.

  **Blocked acceptance item:** live structured-output compatibility of the chosen model.
- **No market or news vendor is integrated.** Coverage is broker quotes, exact symbol lookup and operator evidence. News, earnings, fundamentals, corporate actions, sessions and daily bars remain unknown. Selecting a paid source needs coverage and budget testing plus owner authorization.
- **Budget figures are estimates.** Token reservations use input bytes as a conservative bound. Provider billing can lag or differ, so the ledger is a spending control, not an invoice guarantee. Connection-level failures are conservatively charged in full, because a lost response may have been billed.
- **Instruction detection is heuristic.** The structural controls are: no tools, no execution route, non-executable stored proposals, and policy that never reads model output.
- **Evidence covers one thesis.** Symbol-level evidence reviews only the most recently revised open thesis; for any other thesis on the same symbol, request research targeted at it.
- **No instrument screening.** Leveraged and inverse products cannot be identified without instrument metadata.
- **Acknowledged incidents stay silent.** The M2 `IncidentRecorder` does not reopen acknowledged incidents, so a repeat `research_authentication_failed` or `research_budget_exhausted` after acknowledgement raises no new notification intent. Incident acknowledgement and escalation are M4 work.
- **Not yet exercised:** no research worker, scheduler or proving period was run, and no PostgreSQL multi-process test covers a sweeper racing a late settlement (the logic is covered in-process). The M0 dependency advisories remain open.

Next milestone: **M4 — unattended operations and Release 1**, not started.
