import pandas as pd
from trading_engine.strategy_families import evaluate_family


def test_all_approved_families_return_a_bounded_score_and_rules():
    row = {"trend": .4, "momentum": .3, "relative_strength": .2, "rsi": 50, "atr_pct": .02, "participation": .1, "execution_quality": .9, "regime": 1}
    for family in ["trend_rotation", "pullback_in_trend", "protected_momentum", "defensive_cash"]:
        result = evaluate_family(family, row)
        assert -1 <= result.score <= 1
        assert result.rules


def test_protected_momentum_has_a_no_chase_rule():
    result = evaluate_family("protected_momentum", {"trend": .4, "momentum": .4, "relative_strength": .2, "rsi": 80, "atr_pct": .02, "execution_quality": 1})
    assert not next(rule for rule in result.rules if rule["rule"] == "no_chase")["passed"]
