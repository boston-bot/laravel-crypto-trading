from __future__ import annotations

from collections import defaultdict
from typing import Any, Iterable


def trade_attribution(trades: Iterable[dict[str, Any]], fills: Iterable[dict[str, Any]]) -> dict[str, Any]:
    dimensions: dict[str, dict[str, float]] = {name: defaultdict(float) for name in ("asset", "strategy_family", "regime", "exit_reason")}
    gross = 0.0
    for trade in trades:
        pnl = float(trade.get("pnl", 0.0)); gross += pnl
        for name in dimensions:
            dimensions[name][str(trade.get(name, "unknown"))] += pnl
    fees = sum(float(fill.get("fee", 0.0)) for fill in fills)
    slippage = sum(abs(float(fill.get("filled_quantity", 0.0))) * abs(float(fill.get("fill_price", 0.0)) - float(fill.get("reference_price", 0.0))) for fill in fills)
    return {"pnl": {name: dict(values) for name, values in dimensions.items()}, "costs": {"fees": fees, "spread_and_slippage": slippage, "total": fees + slippage}, "net_pnl": gross}
