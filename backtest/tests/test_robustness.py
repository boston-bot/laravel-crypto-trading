from trading_engine.robustness import assess_evidence


def metrics(**overrides):
    return {"trade_count":120,"net_expectancy":2,"sharpe":1,"profit_factor":1.4,"max_drawdown_pct":6} | overrides


def test_insufficient_folds_are_inconclusive_and_breaches_fail():
    assert assess_evidence(metrics(), metrics(), fold_count=2, asset_count=4, max_asset_contribution_pct=30)["status"] == "inconclusive"
    assert assess_evidence(metrics(max_drawdown_pct=9), metrics(), fold_count=3, asset_count=4, max_asset_contribution_pct=30)["status"] == "failed"
    assert assess_evidence(metrics(), metrics(), fold_count=3, asset_count=4, max_asset_contribution_pct=30)["status"] == "passed"
