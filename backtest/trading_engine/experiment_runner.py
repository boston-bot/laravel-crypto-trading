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
