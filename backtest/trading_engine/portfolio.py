from __future__ import annotations

from typing import Any, Iterable

from .strategy_definition import canonical_hash


def construct_target(candidates: Iterable[dict[str, Any]], portfolio_context: dict[str, Any], parameters: dict[str, Any]) -> dict[str, Any]:
    maximum = min(3, int(parameters.get("max_positions", 3)))
    cap = min(.40, float(parameters.get("max_position_weight", .40)))
    gross_cap = min(1.0, float(parameters.get("max_gross_weight", 1.0)))
    selected: list[dict[str, Any]] = []
    correlation_groups: set[str] = set()
    for candidate in sorted(candidates, key=lambda value: (-float(value["score"]), str(value["asset"]))):
        if len(selected) >= maximum:
            break
        group = str(candidate.get("correlation_group", candidate["asset"]))
        if group in correlation_groups:
            continue
        correlation_groups.add(group)
        selected.append(candidate)
    weight = min(cap, gross_cap / len(selected)) if selected else 0.0
    positions = [{"asset_id": int(item["asset_id"]), "asset": item["asset"], "side": "long", "target_weight": round(weight, 8)} for item in selected]
    target = {
        "schema_version": "1.0", "as_of": portfolio_context.get("valuation_at"),
        "portfolio_context_hash": portfolio_context.get("context_hash"),
        "cash_weight": round(1.0 - sum(position["target_weight"] for position in positions), 8), "positions": positions,
        "allocation_policy": "long-cash-v2",
    }
    target["target_hash"] = canonical_hash(target)
    return target
