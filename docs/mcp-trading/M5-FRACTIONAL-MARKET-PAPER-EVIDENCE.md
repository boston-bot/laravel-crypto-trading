# M5 fractional forward-paper path

October 22, 2026. The owner chose fractional shares on October 21. Robinhood's reviewed order contract allows fractional quantities only on regular-hours market orders ([contract review](M5-REPLAY-FRACTIONAL-CADENCE-EVIDENCE.md#fractional-order-contract)), so the forward paper path gains guarded market orders. This increment is source and tests only:
- no deployment, migration, runtime setting or flag change
- no paper-ledger write outside the test database
- no broker order or order review
- no model call

## Behavior

**Owner settings decide the order type.** Two versions, both applied by the owner:
- **Execution version:** `order_type` may now be `market` as well as `limit`, with `session=regular`, `time_in_force=day`, and fractional shares allowed. Fractional limit orders remain refused.
- **Signal settings:**
  - `allow_fractional=true` is accepted only when the applied execution version has market orders and fractional shares allowed.
  - With market orders, slippage must be at least 5 basis points (`SignalOrderPrice::MARKET_SLIPPAGE_FLOOR_BPS`), because market fills give up price control.
  - Risk limits are unchanged (1% risk per trade, 20% maximum position, 8 positions).

Both Control Center forms gain the choices. A version with limit orders behaves exactly as before.

**Per-symbol fractional eligibility.** It comes from Robinhood's own `fractional_tradability` field in the account-scoped `get_equity_tradability` read. The collector already stores that read with every observation when execution inputs are enabled, and the stored contract fingerprint pins it. `BrokerSimulationQuote` maps it as follows:

| Robinhood value | Quote's `fractional` flag |
|---|---|
| `tradable` | true |
| any other reported value | false |
| missing | unknown (whole shares) |

The read must be as fresh as the quote it accompanies; the existing check, at most the quote-age limit, still applies. The execution-data readiness report shows the flag per symbol.

**Market entries, guarded at submission.** `SignalPaperHandoff` takes the order type from the applied execution version. For a market buy:
- **Quantity scale:** 6 decimals when the owner allows fractional shares and Robinhood reports the symbol `tradable`, otherwise whole shares (`SignalOrderPrice::scale`).
- **Sizing:** `RiskSizer` rounds every cap down at that scale and refuses fractional sizes under the $1.00 minimum order value.
- **Price bound:** the highest acceptable fill, which is the profile's maximum entry plus slippage. The order fills only while the ask is at or below the maximum entry. Sizing and the cash reservation use this bound, so planned risk is computed at the worst acceptable fill.
- **Rechecks at submission (`SignalOrderAdmission`), every one of which can reject the order:**
  - the applied eligibility rules, including the 0.5% spread limit
  - the entry rule on the fresh ask (maximum chase)
  - the explicit guard that the ask is at or below the stored maximum entry (`market_price_guard_failed`)
  - the session baseline, the daily loss pause, the external-order pause, position limits, risk size and cash

**Immediate execution.** A market signal order executes on acceptance, against the same verified quote that passed the guard:
- **Fill price:** the ask plus slippage.
- **Size:** within the displayed size, at most 6 decimals.
- **Costs:** the reviewed cost schedule.

A remainder that cannot fill then (for example, displayed size exhausted) waits for later observations under the same bound and expires at the close. Limit orders still fill only from an observation after acceptance.

**Market exits.** Exits have no price floor (`SignalOrderPrice::MARKET_SELL_FLOOR`), because exits never wait for a better price. They fill immediately at the bid minus slippage. A fractional position sells in full while Robinhood reports the symbol fractional-tradable. If it no longer does, the whole shares are sold and the leftover fraction is recorded in the handoff evidence (`unsold_fraction`). A fraction-only position records `fractional_exit_unavailable`.

**Simulator rules for broker-data orders (`OfflineSimulator`):** fractional quantities only on market orders, at most 6 decimals, and a $1 minimum for purchases. Synthetic scenarios keep their existing 8-decimal precision.

## Verification

| Check | Result |
|---|---|
| Full SQLite | **599 passed / 4,101 assertions, 35 PostgreSQL-only skips** (634 total) |
| Full PostgreSQL | **633 passed / 4,429 assertions, 1 existing PHP-FPM environment skip** (634 total) |
| `composer validate`, Pint, `git diff --check` | Passed |

All existing paper-trading feature tests (293) passed before the new tests were added, so limit-order behavior is unchanged.

New `FractionalMarketPaperTest` (10 tests):
- **Fractional buy:** fills immediately at the ask plus 5 bp (101.60 → 101.6508), with a 6-decimal quantity matching the recorded sizing. Cash decreases by exactly the notional plus fee, the reservation is released, and repeated handoffs or fill passes add nothing. No network request is made.
- **Small position limit:** a 0.5% limit (like a $500 account) buys a fraction under one share. With `untradable` the same signal sizes to zero whole shares and places nothing. With the flag missing, a whole-share market order fills.
- **Rejections at submission, with no fill and no cash or reservation change:** a moved quote (entry rule), an ask above a lowered stored maximum entry (price guard), and a wide spread (eligibility).
- **Exits:** a fractional exit sells the full fraction at the bid minus 5 bp, with no floor. After eligibility is lost, the whole shares sell and the fraction is recorded.
- **Settings:** fractional permission is refused with a limit execution version, with market orders but fractional shares disallowed, and with slippage under 5 bp. The owner form records a boolean, and the Control Center shows the new choices.

`InstrumentEligibilityTest` now also covers the readiness flag and an unknown `fractional` value.

## Expected collection gap to measure first

The market-data cycle and the signal paper cycle are separate five-minute jobs, and the scheduler runs them one after the other.
- **The numbers:** on October 20 the batch collector took 48.9–54.8 seconds to read 32 symbols. The applied quote-age limit is 20 seconds. Symbols read early in a cycle may therefore already be too old when their signals are evaluated, and they record "unavailable" rather than trading.
- **How to measure:** the completed-session `mcp:cadence-report` with signals enabled reports quote age at evaluation and `within_quote_max_age_pct`.
- **Likely fix if confirmed:** evaluate each collected batch immediately after it is read. Raising the quote-age limit would loosen a safeguard.

## Activation (owner, runtime)

No migration. After deployment:
1. **Execution version.** In Control Center → Paper execution settings, save and apply a new execution version: order type "Market orders with a submission price guard", fractional shares allowed, fractional limit orders disallowed, other values unchanged.
2. **Signal settings.** In Signal paper risk and costs, apply new settings: slippage of at least 5 bp, and fractional shares "Fractional on market orders".
3. **Runtime flags.** Confirm or set:
   - `ROBINHOOD_MCP_EXECUTION_INPUTS_ENABLED=true` (tradability and price book with each observation)
   - `MCP_STRATEGY_SIGNALS_ENABLED=true`
   - `MCP_SIGNAL_PAPER_HANDOFF_ENABLED=true`
   - `MCP_PAPER_REAL_DATA_FILLS_ENABLED=true`
   - `MCP_EXTERNAL_ORDER_DETECTION_ENABLED=true` (an order the app did not create pauses new paper entries)

   Then refresh the cached configuration and restart the queue workers.
4. **Measure.** After the session closes, run `mcp:cadence-report`.

These are paper simulations, kept separate from broker-confirmed activity. Nothing here sends a broker order.
