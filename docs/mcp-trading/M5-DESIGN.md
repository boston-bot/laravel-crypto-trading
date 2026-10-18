# M5 design: frequent discovery and AI paper decisions

Started October 17, 2026 at the owner's request. This specification describes planned M5 behavior; the first implemented control-center slice and its limits are recorded in [M5 control-center evidence](M5-CONTROL-CENTER-EVIDENCE.md). On October 18 the owner waived the seven-session requirement and accepted M4 with that period unverified (0/7 completed), directing continued M5 work. That does not authorize live trading or service activation. Continue documenting and verifying deployments affecting monitoring.

## Owner-confirmed direction

- The AI selects investment opportunities and proposes actions and sizing within an owner-defined mandate. Laravel supplies reliable data, validates decisions, enforces limits and manages the simulated lifecycle.
- Target holding period: **multi-day to multi-week positions only**. Frequent evaluation does not require frequent trading or intraday liquidation.
- Discovery scope: **a broad US stock and ETF universe**, requiring a screening/data-provider step. Exact instrument eligibility remains to be reviewed; retain the existing exclusions for options, borrowing, shorts, leveraged/inverse products and crypto. Unknown product classification is ineligible for simulated execution until resolved.
- AI and data together retain the **$100/month target and $150/month maximum planned envelope**, including fixed subscriptions and in-flight usage. No subscription purchase is authorized by this design.
- Collect and research much more frequently than the former 30-minute ChatGPT task. The application owns durable state; model requests contain bounded evidence rather than a growing chat history.
- Use a browser **Control Center** to inspect and edit the investment mandate, allocations, risk allowance, AI guidance and collection/research frequency, with version history and restore. Browse stored PostgreSQL evidence/results without manual queries. ChatGPT-proposed settings changes must return as reviewable proposals, not silently replace application policy.

## Browser control center and rule delivery

The browser is the operator's place to manage settings; PostgreSQL is authoritative. Distinguish editable drafts, validated/applied versions and the version actually used by each decision. AI strategy guidance and numeric policy are separate: guidance explains investment preferences; application code independently enforces the allowed universe, capital, exposure, loss/pause and cost limits. Free text cannot activate trading or bypass those limits.

The first source implementation at `/mcp/dashboard/control` supports encrypted, append-only **paper drafts**, allocations totaling exactly 100% when specified, risk/capital fields, cadence fields, AI guidance, reasons for changes, history and restore-as-new-version. It displays current code-defined research instructions/configuration and paginated stored research runs/source evidence. A copied planning JSON object can be discussed in ChatGPT; a returned partial JSON proposal is validated and loaded into the editor for review without saving. It requires a separate save. There is no automatic connection from ChatGPT conversation memory to these settings.

**October 18 increment:** a complete saved draft can be explicitly applied to a separate virtual portfolio, with an immutable rules snapshot and opening cash entry. Policy changes preserve its cash/reservations; changing initial capital through a policy is refused. See [paper-policy evidence](M5-PAPER-POLICY-EVIDENCE.md).

**October 18 runner increment:** the separately disabled paper proposal runner consumes applied `decision_minutes`, policy/guidance and ledger versions, retains encrypted request/result/cost evidence, and exposes configured versus observed attempts in the Control Center. Current-window deduplication, shared budget reservations and crash/late-result handling are implemented with fakes. See [decision-runner evidence](M5-DECISION-RUNNER-EVIDENCE.md). Applying local paper rules does not enable it. Default evidence is a bounded local research subset with unknown eligibility/positions, not the planned broad screening universe.

**October 18 offline increment:** a separately disabled synthetic simulator now supports decimal cash/share reservations, bounded market/limit DAY orders, later-observation partial fills, immutable intent/fill evidence, conservation and scoped accounting reports. No model-to-order handoff or real provider adapter. See [offline simulation evidence](M5-OFFLINE-SIMULATION-EVIDENCE.md).

**Not yet implemented:** real-provider paper execution/proposal handoff, `scan_seconds`/`news_minutes` consumption by new collectors, material-event decision triggers, broad market/news adapters and automatic proposal return through an MCP tool. Real model compatibility, recurring cadence/cost and strategy performance remain unverified. Current research uses M3's fixed reviewed instructions/configuration. Historical M3 runs retain hashes/versions/results rather than full request transcripts; the current prompt preview is not a reconstructed old request.

The M5 runner uses the atomically applied complete paper version, effective time and actor, and includes its policy/guidance snapshot in each bounded model request. Cadence and budget are enforced locally; a model need not receive scheduler or billing implementation details. Encrypted request/policy snapshots show the version used, model output and validation together. A concurrent policy or ledger change supersedes the pending proposal. Frequency changes use the existing scheduler without replaying old windows. Restore produces another version and affects future work; it never rewrites old decisions or reverses fills. Submission-time gates remain work for the future simulated execution boundary.

The console must show configured versus observed frequency, next eligible review, last successful collection/model call, coverage/source age, budget reservations/spend and deferrals. Browser visibility must label unavailable observations honestly. Initial M5 controls remain paper-scoped; credentials, broker authorization and M6 live activation stay outside this editor.

## Proposed cadence, subject to coverage and measured cost

These are engineering targets for a paper experiment, not activated schedules or provider capability claims. The disabled proposal scheduler now consumes the applied decision interval; other ingestion/event targets remain planned. A scheduled evaluation can produce WAIT, REJECTED or UNAVAILABLE; it need not produce an order.

| Activity | Starting target | Work and limits |
|---|---|---|
| Local opportunity scan | Every minute during a verified regular session | Evaluate the latest ingested universe data; no model call. Record the actual source age. Scanning cached data every minute does not make it one-minute market data. |
| Market-data ingestion | Streaming or incremental updates where licensed; otherwise provider-supported polling | Select after verifying quote/bar coverage, timestamps, batch sizes, limits and cost. Do not poll every symbol individually through Robinhood MCP each minute. |
| News/event ingestion | Incremental feed or 1–5 minute polling where supported | Deduplicate stories, retain publication and local availability times, map affected symbols, and record coverage gaps. Provider-specific capability is still unverified. |
| AI opportunity/portfolio review | One bounded batch every five minutes during the regular session | Review a capped shortlist, existing paper exposure and relevant evidence. Exact batch size and token allowance follow a measured provider/model trial. |
| Material-event AI review | Queue on ingestion, between scheduled reviews | Coalesce related events; one active decision run per paper portfolio. Budget and priority controls still apply. “Immediate” means eligible for dispatch, not guaranteed completion latency. |
| Deeper thesis research | New candidate, material evidence, invalidation or thesis expiry | Persist reusable evidence and thesis versions. Do not rerun a full thesis analysis on every quote update. |
| Outside the regular session | Continue provider-supported news collection; pre-open/post-close research | Initial paper fills are regular-session only. Overnight proposals must be revalidated after a fresh session/account/market view; no stale backlog trading. |

The scheduler remains Laravel's existing single scheduler, with bounded dispatch and separate workers. New collection, AI decisions and paper simulation remain disabled by default. Workload must not starve monitoring, reconciliation or operational health jobs.

## Broad discovery with bounded model context

Maintain an explicit, versioned universe with instrument identifiers, eligibility/classification provenance and effective times. Screening can use verified liquidity, spread, completed-bar price/volume changes, upcoming events and news availability. These prioritize research; they do not replace the AI's investment judgment. Any numeric filter must be visible and versioned rather than copied from legacy crypto strategies.

Build a rotating shortlist with slots for existing positions, new candidates and previously under-reviewed eligible symbols. Cap its size and record selection/exclusion reasons. Preserve the universe version, coverage ratio, last successful scan, age of each input and time since last review. A broad configured universe must not be displayed as fully researched when only a small subset has usable data. No requirement to fill the shortlist when evidence is weak.

Separate reusable thesis research from short-lived decisions. M3's 24-hour research cache intentionally ignores ordinary quote changes; it cannot serve as the decision cache. A decision fingerprint must include the paper ledger/open-order version, policy and universe versions, shortlist, decision window, thesis versions and material market/evidence inputs. An exact duplicate can reuse the recorded decision; changed exposure, invalidation or material evidence requires re-evaluation. A cache hit is displayed as reused, never counted as a new AI assessment.

## Paper decision contract and validation

Add a separate, versioned paper-decision schema; retain M3's non-executable research schema and its restrictions. The model receives only bounded, timestamped context with source IDs, normalized paper exposure/available allocation and allowed instruments. Credentials and brokerage identifiers are excluded. Prefer weights or fractions of available paper capital so deterministic decimal code can calculate quantities locally.

Each decision records BUY, SELL, HOLD or WAIT; instrument; proposed target weight or sizing fraction; cited rationale; multi-day/week horizon; invalidation; review time; and expiry. Required sizing bounds and accounting rules come from an immutable owner-approved paper policy. The model cannot change that policy. Preserve prompt/model version, input fingerprint, all evidence references, actual model usage, estimated cost, timings and validation outcome. No tool in the model request can mutate a brokerage account.

Application checks cover instrument eligibility, evidence validity, source/availability times, quote freshness, session, paper cash and positions, pending commitments, portfolio/position limits, proposal expiry and concurrent ledger changes. Unsupported data produces an explicit unavailable/rejected outcome; it is never treated as zero. Record the proposed and accepted/rejected decision separately. An AI HOLD/WAIT is distinct from “no model call”, a cache hit, budget deferral or model failure.

## Simulation and evaluation

Use a dedicated paper portfolio/ledger keyed to the local authorized connection and policy version, without writing simulated orders/fills into broker-confirmed ledgers. Starting capital, initial holdings and any sleeve allocation must be explicit. A hypothetical starting portfolio must never be described as actual broker holdings or spendable funds.

Use exact decimals, atomic reservations and unique logical decision/order/fill keys. Model submission, open, partial, filled, cancelled, rejected and expired states. Buy fills use observed ask and sells use observed bid with reviewed slippage/fees; limit prices, fractional eligibility, available volume assumptions and session rules constrain fills. Absence of usable later observations leaves an order unfilled/unknown rather than generating a synthetic price or pretending liquidity is known. Market-data granularity limits claims about fill realism. Unsupported corporate actions pause affected simulation pending explicit adjustment evidence.

Inputs must have been available locally by the decision cutoff, not merely carry an earlier publication timestamp. Evaluate hypothetical fills only on observations available after order acceptance; a bar used to choose a trade cannot also prove a later intrabar fill. Broker fills and paper fills remain separate on every report.

Report opportunity coverage, scan-to-dispatch and event-to-decision latency, queue delay, actual AI review frequency, source gaps, WAIT/rejections, turnover, holding periods, costs and simulated results. Compare the proposed five-minute experiment with a 30-minute evaluation using the same mandate, starting portfolio and point-in-time evidence. Historical comparisons must be labeled retrospective unless equivalent past-available evidence and model provenance can be established. More frequent decisions are an experiment, not evidence of better returns.

## Cost and overload controls

For an illustrative 6.5-hour session, five-minute batches mean 78 scheduled calls. At an illustrative 21 sessions that is 1,638 calls before event-driven/deep-research calls. Running a separate call for each of 20 symbols would instead mean 32,760 calls. These are workload calculations, not cost estimates or a promised monthly session count.

Measure input/output usage, latency and error rates with the selected model. Allocate the remaining envelope after fixed data costs across routine batches, event-triggered research and repair/unreconciled requests. If measured cost cannot support the proposed cadence, show the achievable cadence and tradeoff before activation; do not silently claim five-minute AI reviews while only polling locally. Prompt caching may reduce repeated input cost, but the existing ledger remains conservative until actual cache usage/pricing is reconciled. [OpenAI pricing](https://developers.openai.com/api/docs/pricing), [prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching).

Retain atomic reservations, bounded repairs and conservative timeout settlement from M3. Coalesce overlapping work, prioritize position/invalidation events and expire outdated candidates. Bound outstanding work; never catch up by issuing old paper trades after a restart. Log budget deferrals and lost coverage. Monitoring continues when AI spending stops.

## Implementation order and unresolved inputs

1. Define and version the paper mandate: virtual starting capital, initial holdings/sleeves, position/portfolio limits, loss/pause rules, allowed instruments, order/fractional assumptions and the AI's permitted sizing discretion. Horizon, broad-universe direction and monthly budget are already confirmed; no historical crypto limits are adopted.
2. Audit/introduce the separate decimal paper ledger and order lifecycle with PostgreSQL persistence, concurrency and conservation tests. The legacy `PaperExecutionEngine` uses floats, fills immediately and can fabricate a fallback reference price; do not reuse those behaviors. Existing enum/model/audit patterns may be reused where their semantics fit.
3. Add paper-decision contracts, context selection, validation, idempotency, recovery and faked model tests, reusing M3 transport/budget services where compatible. Do not loosen M3's research-only validator to accept executable instructions.
4. Compare candidate market/news providers against actual universe coverage, freshness, rights, rate limits and total cost. Select a model and configure a dedicated application credential/reviewed rates privately. No credential, paid provider, API call or recurring activation is implied by this document.
5. Complete ingestion/screening and dashboard coverage evidence alongside the disabled proposal scheduler; run bounded manual compatibility/cost checks before proposing recurring paper activation. Record deployment impact and operational checks; M4's seven-session period was waived, not passed.
6. Run forward paper evaluation, failure/restart tests and the cadence comparison. Keep M6 and all real brokerage mutations outside M5.

Required failure tests include changed portfolio/policy while the model runs; two workers reserving the same cash; duplicate event/model-result delivery; timeout after a potentially billed request; stale or missing quote/session; late-arriving news; expired/unfilled limits; partial fills and duplicate fills; unsupported corporate actions; restart with stale decisions; budget exhaustion; and strict separation from broker-confirmed state. Every blocked/model-failed/no-call outcome remains visible.

Current evidence: design, initial paper-draft control center, [policy/virtual cash](M5-PAPER-POLICY-EVIDENCE.md), [disabled decision runner](M5-DECISION-RUNNER-EVIDENCE.md) and [offline synthetic orders/fills/accounting](M5-OFFLINE-SIMULATION-EVIDENCE.md). Additive M5 migrations have run only in the guarded test database. Policy/decision cadence consumption and synthetic accounting are verified with fakes; real model compatibility, provider coverage, attainable cost/latency, real-provider paper execution and forward strategy performance remain unverified. Development model/key/rates were configured in the earlier setup follow-ups; this offline increment changes no credentials or runtime enablement.
