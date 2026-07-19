from trading_engine.experiment_runner import evaluate_holdout


def evidence(**overrides):
    base = {
        "complete": True,
        "reconciled": True,
        "data_quality_passed": True,
        "execution_passed": True,
        "concentration_passed": True,
        "trade_count": 12,
        "asset_count": 2,
        "normal_return_pct": 4.0,
        "stressed_return_pct": 1.0,
        "normal_max_drawdown_pct": 8.0,
        "stressed_max_drawdown_pct": 12.0,
    }
    return base | overrides


def test_holdout_starts_flat_and_passes_only_all_frozen_gates():
    result = evaluate_holdout(evidence())
    assert result["status"] == "passed"
    assert result["initial_state"] == {"nav": 1.0, "cash_weight": 1.0, "positions": {}, "asset_states": "flat"}


def test_missing_counts_are_inconclusive_but_gate_breaches_fail():
    assert evaluate_holdout(evidence(trade_count=9))["status"] == "inconclusive"
    assert evaluate_holdout(evidence(normal_max_drawdown_pct=15.01))["status"] == "failed"
    assert evaluate_holdout(evidence(stressed_return_pct=0.0))["status"] == "failed"
