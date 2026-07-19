from datetime import datetime, timezone
import pandas as pd
from trading_engine.evaluator import PortfolioEvaluator
from trading_engine.strategy_definition import default_definition
from trading_engine.strategy_state import PositionState, StrategyState


def frame(score=1.0):
    index = pd.date_range("2026-01-01", periods=2, freq="4h", tz="UTC")
    return pd.DataFrame({"trend": [.4, .4], "momentum": [.4, .4], "relative_strength": [.4, .4], "rsi": [50, 50], "atr_pct": [.02, .02], "participation": [.3, .3], "execution_quality": [1, 1], "regime": [1, 1]}, index=index)


def test_evaluator_traces_every_member_and_caps_portfolio():
    assets = [{"id": i, "symbol": symbol, "correlation_group": "majors" if i < 3 else symbol} for i, symbol in enumerate(["BTC", "ETH", "SOL", "LINK"], 1)]
    context = {"valuation_at": "2026-01-01T04:00:00+00:00", "context_hash": "a"*64, "positions": []}
    result = PortfolioEvaluator(default_definition()).evaluate({a["symbol"]: frame() for a in assets}, assets, datetime(2026, 1, 1, 4, tzinfo=timezone.utc), context, "b"*64, {a["symbol"]: StrategyState(PositionState.ENTRY_CONFIRMING, 1) for a in assets})
    assert len(result["proposals"]) == 4
    target = result["proposals"][0]["portfolio_target"]
    assert len(target["positions"]) <= 3
    assert all(position["target_weight"] <= .4 for position in target["positions"])
    assert target["cash_weight"] + sum(p["target_weight"] for p in target["positions"]) == 1
    assert all(proposal["decision_trace"]["counterfactual"] for proposal in result["proposals"])


def test_future_rows_do_not_change_current_evaluation():
    assets = [{"id": 1, "symbol": "BTC"}]; context = {"valuation_at": "2026-01-01T00:00:00Z", "context_hash": "a"*64, "positions": []}
    base = frame(); future = base.copy(); future.loc[pd.Timestamp("2026-01-02", tz="UTC")] = [-1,-1,-1,90,.2,-1,0,-1]
    evaluator = PortfolioEvaluator(default_definition())
    first = evaluator.evaluate({"BTC": base}, assets, datetime(2026,1,1,4,tzinfo=timezone.utc), context, "b"*64)
    second = evaluator.evaluate({"BTC": future}, assets, datetime(2026,1,1,4,tzinfo=timezone.utc), context, "b"*64)
    assert first["proposals"] == second["proposals"]
