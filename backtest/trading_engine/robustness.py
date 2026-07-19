from __future__ import annotations

from typing import Any


DEFAULT_GATES = {"min_oos_trades": 100, "min_assets": 3, "min_sharpe": .8, "min_profit_factor": 1.2, "max_drawdown_pct": 8.0, "max_asset_profit_contribution_pct": 50.0, "minimum_complete_folds": 3}


def assess_evidence(normal: dict[str, Any], stressed: dict[str, Any], *, fold_count: int, asset_count: int, max_asset_contribution_pct: float, thresholds: dict[str, Any] | None = None) -> dict[str, Any]:
    gate = DEFAULT_GATES | (thresholds or {})
    checks = {
        "min_oos_trades": int(normal.get("trade_count", 0)) >= int(gate["min_oos_trades"]),
        "min_assets": asset_count >= int(gate["min_assets"]),
        "positive_normal_expectancy": float(normal.get("net_expectancy", 0)) > 0,
        "positive_stressed_expectancy": float(stressed.get("net_expectancy", 0)) > 0,
        "min_sharpe": float(normal.get("sharpe", 0)) >= float(gate["min_sharpe"]),
        "min_profit_factor": float(normal.get("profit_factor", 0)) >= float(gate["min_profit_factor"]),
        "max_drawdown": float(normal.get("max_drawdown_pct", 100)) <= float(gate["max_drawdown_pct"]),
        "asset_concentration": max_asset_contribution_pct <= float(gate["max_asset_profit_contribution_pct"]),
    }
    if fold_count < int(gate["minimum_complete_folds"]):
        status, reason = "inconclusive", "insufficient_complete_folds"
    elif all(checks.values()):
        status, reason = "passed", None
    else:
        status, reason = "failed", "one_or_more_evidence_gates_failed"
    return {"status": status, "reason": reason, "checks": checks, "gate_passed": status == "passed", "fold_count": fold_count}
