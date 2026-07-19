from __future__ import annotations

from typing import Any, Callable, Iterable
import pandas as pd

from .strategy_definition import canonical_hash


def run_nested_experiment(folds: Iterable[Any], candidate_definitions: list[dict[str, Any]], evaluate: Callable[[dict[str, Any], str, Any], dict[str, Any]]) -> list[dict[str, Any]]:
    if not candidate_definitions:
        raise ValueError("nested experiment requires candidate definitions")
    output = []
    for fold in folds:
        validation = [(candidate, evaluate(candidate, "validation", fold)) for candidate in candidate_definitions]
        selected, validation_result = max(validation, key=lambda item: float(item[1].get("selection_score", float("-inf"))))
        child_hash = canonical_hash(selected)
        test_result = evaluate(selected, "test", fold)
        output.append({"fold": fold.fold, "child_definition_hash": child_hash, "selected_definition": selected, "validation": validation_result, "test": test_result})
    return output


def link_fold_nav(fold_curves: Iterable[pd.Series]) -> pd.Series:
    linked: list[pd.Series] = []; multiplier = 1.0; previous_end = None
    for curve in fold_curves:
        clean = curve.astype(float).dropna().sort_index()
        if clean.empty: continue
        if previous_end is not None and clean.index[0] < previous_end:
            raise ValueError("out-of-sample fold curves overlap")
        normalized = clean / clean.iloc[0] * multiplier
        if linked and normalized.index[0] == linked[-1].index[-1]: normalized = normalized.iloc[1:]
        linked.append(normalized); multiplier = float(normalized.iloc[-1]); previous_end = clean.index[-1]
    return pd.concat(linked) if linked else pd.Series(dtype=float)


def evaluate_holdout(evidence: dict[str, Any]) -> dict[str, Any]:
    """Apply the frozen holdout gates without exposing data-dependent tuning hooks."""
    initial_state = {"nav": 1.0, "cash_weight": 1.0, "positions": {}, "asset_states": "flat"}
    counts_pass = int(evidence.get("trade_count", 0)) >= 10 and int(evidence.get("asset_count", 0)) >= 2
    integrity_checks = {
        "complete": bool(evidence.get("complete", False)),
        "reconciled": bool(evidence.get("reconciled", False)),
        "data_quality": bool(evidence.get("data_quality_passed", False)),
        "execution": bool(evidence.get("execution_passed", False)),
        "concentration": bool(evidence.get("concentration_passed", False)),
    }
    performance_checks = {
        "positive_normal_return": float(evidence.get("normal_return_pct", 0.0)) > 0.0,
        "positive_stressed_return": float(evidence.get("stressed_return_pct", 0.0)) > 0.0,
        "normal_drawdown_ceiling": float(evidence.get("normal_max_drawdown_pct", 100.0)) <= 15.0,
        "stressed_drawdown_ceiling": float(evidence.get("stressed_max_drawdown_pct", 100.0)) <= 15.0,
    }
    if (not counts_pass or not integrity_checks["complete"]) and all(
        value for key, value in integrity_checks.items() if key != "complete"
    ) and all(performance_checks.values()):
        status = "inconclusive"
        reason = "insufficient_or_incomplete_evidence"
    elif all(integrity_checks.values()) and all(performance_checks.values()) and counts_pass:
        status = "passed"
        reason = None
    else:
        status = "failed"
        reason = "one_or_more_frozen_holdout_gates_failed"
    return {
        "status": status,
        "reason": reason,
        "checks": integrity_checks | performance_checks | {"minimum_evidence_counts": counts_pass},
        "initial_state": initial_state,
    }
