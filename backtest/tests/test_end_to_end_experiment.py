from datetime import datetime, timezone

from trading_engine.contracts import BacktestSpec
from trading_engine.experiment_runner import evaluate_holdout, run_nested_experiment
from trading_engine.strategy_definition import canonical_hash, default_definition
from trading_engine.walk_forward import anchored_folds, assert_nested_boundaries


def test_synthetic_experiment_preserves_lineage_through_selection_and_holdout():
    spec = BacktestSpec(
        strategy_version="candidate-v1",
        universe_version="coinbase-v1",
        start=datetime(2019, 1, 1, tzinfo=timezone.utc),
        end=datetime(2026, 1, 1, tzinfo=timezone.utc),
    )
    folds = anchored_folds(spec)
    holdout_start = datetime(2025, 1, 1, tzinfo=timezone.utc)
    assert len(folds) >= 4
    assert_nested_boundaries(folds, holdout_start)

    candidates = [
        default_definition(family).__dict__
        for family in ["trend_rotation", "pullback_in_trend", "protected_momentum", "defensive_cash"]
    ]
    preregistered_spec = spec.__dict__ | {"start": spec.start.isoformat(), "end": spec.end.isoformat()}
    preregistration_hash = canonical_hash({"spec": preregistered_spec, "candidates": candidates})

    family_scores = {
        "trend_rotation": 4.0,
        "pullback_in_trend": 3.0,
        "protected_momentum": 2.0,
        "defensive_cash": 1.0,
    }

    def worker(candidate, stage, fold):
        base = family_scores[candidate["family"]]
        return {
            "selection_score": base - fold.fold * 0.01,
            "stage": stage,
            "fold": fold.fold,
            "candidate_hash": canonical_hash(candidate),
            "manifest_hash": canonical_hash({"fold": fold.fold, "stage": stage}),
        }

    enqueued = [
        {"job_id": f"candidate-{index}", "candidate_hash": canonical_hash(candidate), "status": "pending"}
        for index, candidate in enumerate(candidates, 1)
    ]
    results = run_nested_experiment(folds, candidates, worker)
    consumed = {row["child_definition_hash"]: row for row in results}
    champion = max(candidates, key=lambda candidate: family_scores[candidate["family"]])
    finalist_hash = canonical_hash(champion)

    assert len(enqueued) == 4
    assert all(job["candidate_hash"] for job in enqueued)
    assert finalist_hash in consumed
    assert all(row["selected_definition"]["family"] == "trend_rotation" for row in results)

    frozen = {
        "candidate_hash": finalist_hash,
        "universe_version": spec.universe_version,
        "development_manifest_hash": canonical_hash([row["test"]["manifest_hash"] for row in results]),
        "preregistration_hash": preregistration_hash,
        "status": "frozen",
    }
    authorization_hash = canonical_hash({"finalist": frozen, "purpose": "single synthetic holdout"})
    holdout = evaluate_holdout(
        {
            "complete": True,
            "reconciled": True,
            "data_quality_passed": True,
            "execution_passed": True,
            "concentration_passed": True,
            "trade_count": 15,
            "asset_count": 3,
            "normal_return_pct": 4.0,
            "stressed_return_pct": 1.0,
            "normal_max_drawdown_pct": 8.0,
            "stressed_max_drawdown_pct": 12.0,
        }
    )

    assert authorization_hash == canonical_hash({"finalist": frozen, "purpose": "single synthetic holdout"})
    assert holdout["status"] == "passed"
    assert holdout["initial_state"] == {"nav": 1.0, "cash_weight": 1.0, "positions": {}, "asset_states": "flat"}
