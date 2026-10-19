# M5 owner mandate intake

**Direction change, October 18:** the owner adopted the strategy/execution split in [AGENTS.md](../../AGENTS.md#project-direction) and the [revised M5 design](M5-DESIGN.md). ChatGPT tasks maintain a dedicated Robinhood watchlist every 30 minutes, and deterministic rules in the app make paper decisions on a 5-minute cycle. The owner made the strategy decisions below the same day. The table shows which intake choices still apply; the rest of this file is the unchanged historical record.

| Intake choice | Status after October 18 |
|---|---|
| $500 cash-only start, 100% active allocation, active sleeve | Applies. The active sleeve now holds rule-based positions. |
| 90% maximum position | Replaced by about 20% of paper equity, with risk-based sizing of 1% per trade (starting value) |
| 20% daily loss pause | Applies |
| Allowed instruments: 44 "On Watch" symbols | Replaced by eligibility rules in code applied to the dedicated watchlist, capped at 75 symbols |
| 1% fee per fill | Replaced by realistic costs: the broker's actual commission, sell-side regulatory fees, bid/ask fills and slippage |
| 1 bp slippage, 5 bp limit offset, 20-second quote age | Applies |
| Fractional permissions | Applies; fractional shares chosen for sizing, subject to verified instrument and limit-order support |
| AI entry / exit / sizing permissions | Superseded. Entries and exits come from deterministic playbooks; size comes from the risk-based rule. |
| AI review every 5 minutes, news polling every 5 minutes | Superseded. No in-app AI; research and news analysis belong to the ChatGPT strategy tasks. |
| Local scan every 60 seconds | Replaced by the 5-minute evaluation cycle |
| $100/month AI-and-data envelope | Now covers data only; no paid LLM API runs in the app |
| Symbol removed from the watchlist | New decision: no new entries; a held position keeps running its exit rules |

Applied execution v2 still stores the 44-symbol allowlist, `fee_bps = "100"`, `max_position_pct = "90"` and `allow_ai_sizing = true`. They have no effect while paper decisions and handoff are disabled. The owner applies replacement versions through the Control Center once the code supports the new settings. Details: [M5 design](M5-DESIGN.md).

**Applied October 18:** the normal authenticated Control Center forms saved mandate v1, applied paper policy revision 1 and applied execution v2. Read-only PostgreSQL verification confirmed exact prepared values, 44 symbols, one $500 opening cash entry, $0 reserved and no orders/positions. AI/execution gates remain disabled. This supersedes the intake-only/unapplied statements below; see [current application evidence](M5-DEPLOYMENT-EVIDENCE.md#applied-owner-settings-verification). Populated-record backup/restore subsequently passed: 66 tables / 434 rows, all 12 paper tables and 6 paper rows matched, encrypted settings readable and the $500 ledger intact. Scratch cleanup and disabled execution were verified; see the same evidence document.

October 18, 2026. Owner answers and subsequent delegation of remaining choices recorded from this development conversation. This is an intake record, not an applied PostgreSQL policy version. No runtime settings, balances, schedules or execution gates were changed. M5 remains partial.

Deployment follow-up: the completed source and five additive migrations are now deployed as **`b9b5146`**. The review bundle passes the deployed validators, but settings remain **unapplied**: a saved browser permission/managed policy denied Control Center access despite owner approval/reset. Prepared private form values were handed off for normal authenticated application. [Deployment evidence and next step](M5-DEPLOYMENT-EVIDENCE.md). Historical intake details below remain unchanged.

## Supplied choices

| Setting | Owner choice | Application representation |
|---|---|---|
| Virtual starting cash | $500 | `initial_cash = "500.00"` |
| Starting holdings | Entirely cash | `initial_holdings = cash_only` |
| Allocation | “active”; owner delegated remaining judgment | `long_term_pct = "0"`, `active_pct = "100"`, `cash_pct = "0"` |
| AI decision sleeve | Active | `decision_sleeve = active` |
| Maximum position | 90% of total paper equity | `max_position_pct = "90"` |
| Daily loss pause | 20% | `daily_loss_pause_pct = "20"` |
| Allowed instruments | Robinhood “On Watch” watchlist | 44 instruments read through the existing app-owned MCP gateway at 2026-10-18 20:15:57 UTC; exact symbols included in the private review bundle, no policy applied |
| AI entry / exit / sizing | “yes” to the grouped question | Read as all three permissions true |
| Fractional shares / fractional limit orders | “yes” to the grouped question | Read as both permissions true, subject to verified instrument eligibility |
| Simulated fee | “1%”; owner delegated remaining judgment | 1% of each fill’s dollar value on buys and sells: `fee_bps = "100"`, `fee_per_share = "0"`; percentage-fee support added |
| Adverse slippage | 1 basis point | `slippage_bps = "1"` |
| Limit-price offset | 5 basis points | `limit_offset_bps = "5"` |
| Maximum quote age | 20 seconds | `quote_max_age_seconds = 20` |
| Proposed cadence | Accepted | Local scan 60 seconds; AI review 5 minutes; news polling 5 minutes |

Previously confirmed direction remains multi-day/week positions in US stocks/ETFs, no options/shorts/borrowing/leveraged or inverse products/crypto, $100 monthly AI-and-data envelope (maximum planned envelope $150), and $0 fixed monthly subscriptions. These are planning choices, not verified provider coverage or activation.

## Resolved choices under owner delegation

The owner confirmed the list is on Robinhood and instructed “for the rest, use your best judgement.” Use 100% active, 0% long-term and 0% reserved-cash allocation. This is a permitted allocation target, not an instruction to invest every dollar. The portfolio begins entirely in virtual cash and may remain in cash when no acceptable proposal exists.

Preserve the supplied 1% as a fee on each simulated fill's dollar value, charged on buys and sells, with no additional per-share fee. This is an owner-selected simulation assumption, not a claim about Robinhood’s actual fees. Fees are separate from the 1 bp adverse-slippage assumption. Percentage fees use exact decimal arithmetic and round down to 18 ledger decimal places; buy reservations round upward and partial fills release the difference to the remaining commitment.

Use the implementation's regular-session DAY limit orders. Fractional permissions require independently verified instrument support. Keep the owner's 90% position and 20% daily-pause values unchanged.

The owner requested the same integration used for account information. The reviewed `get_watchlists` and `get_watchlist_items` schemas were obtained from the existing encrypted capability catalog. A bounded PHP gateway read resolved the unique “On Watch” name, read 44 items, and rechecked its identity/count. All 44 report `object_type=instrument` and supply a symbol; this is not proof of stock/ETF eligibility or fractional support. Membership is a snapshot, not a permanently self-updating grant of permissions. No option, crypto or other membership is silently converted into an equity.

The complete mandate and execution objects were validated with application validators and saved with the source evidence in a temporary, private, encrypted review bundle (`/private/tmp/m5-owner-settings-review.encrypted`, mode 0600), using the app's existing encryption context. The exact symbol list and broker object IDs are not committed to Git. This temporary artifact is not an authoritative PostgreSQL policy version or a durable backup.

## Application and remaining verification

The complete review bundle is prepared. Apply versioned drafts through the authenticated Control Center after deploying the M5 source/migrations; the running application has not been upgraded by this task. Do not manufacture an authenticated actor from this chat. PostgreSQL remains authoritative; this file does not replace immutable application versions.

Accepted five-minute AI cadence still conflicts with the reported 50-request daily quota (78 routine windows in a full regular session before research/events). Local scan/news interval consumption, provider coverage, real-provider fills and real model compatibility remain unfinished. Recording cadence does not prove it is attainable or enable calls. No AI API credits or model requests were used. The owner-authorized broker reads used the existing application connection; normal catalog persistence and OAuth renewal behavior remain in that gateway.

Verification and remaining source/deployment limitations are recorded in [watchlist and percentage-fee evidence](M5-WATCHLIST-EVIDENCE.md). No migration or runtime activation was performed. Next work remains M5: deploy/apply versioned settings and complete verified real-data coverage. M6/live trading remains unauthorized.
