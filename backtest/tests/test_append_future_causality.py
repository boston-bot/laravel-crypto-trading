from datetime import datetime, timezone

import numpy as np
import pandas as pd

from trading_engine.evaluator import PortfolioEvaluator
from trading_engine.simulation import CostScenario, PortfolioSimulator
from trading_engine.strategy_definition import canonical_hash, default_definition
from trading_engine.strategy_state import PositionState, StrategyState


def feature_frame(periods: int = 4) -> pd.DataFrame:
    index = pd.date_range("2026-01-01", periods=periods, freq="4h", tz="UTC")
    return pd.DataFrame(
        {
            "trend": np.linspace(0.35, 0.5, periods),
            "momentum": np.linspace(0.30, 0.45, periods),
            "relative_strength": np.linspace(0.25, 0.4, periods),
            "rsi": np.full(periods, 52.0),
            "atr_pct": np.full(periods, 0.02),
            "participation": np.full(periods, 0.4),
            "execution_quality": np.full(periods, 0.95),
            "regime": np.ones(periods),
            "reference_price": np.linspace(100, 106, periods),
        },
        index=index,
    )


def hourly_prices(periods: int) -> pd.DataFrame:
    index = pd.date_range("2026-01-01", periods=periods, freq="h", tz="UTC")
    close = np.linspace(100, 112, periods)
    return pd.DataFrame(
        {
            "open": close - 0.1,
            "high": close + 0.5,
            "low": close - 0.5,
            "close": close,
            "volume": np.full(periods, 10_000.0),
            "atr_pct": np.full(periods, 0.02),
            "liquidity_score": np.full(periods, 0.9),
        },
        index=index,
    )


def test_appended_future_cannot_change_past_decision_state_target_or_intent():
    cutoff = datetime(2026, 1, 1, 12, tzinfo=timezone.utc)
    assets = [
        {"id": 1, "symbol": "BTC", "correlation_group": "major", "base_increment": "0.00000001"},
        {"id": 2, "symbol": "ETH", "correlation_group": "major", "base_increment": "0.00000001"},
        {"id": 3, "symbol": "SOL", "correlation_group": "alt", "base_increment": "0.00000001"},
    ]
    base = {asset["symbol"]: feature_frame() for asset in assets}
    appended = {}
    for symbol, frame in base.items():
        future = frame.copy()
        future.loc[pd.Timestamp("2026-01-02", tz="UTC")] = [-1, -1, -1, 95, 0.5, -1, 0, -1, 1]
        appended[symbol] = future
    context = {
        "valuation_at": cutoff.isoformat(),
        "context_hash": "a" * 64,
        "equity": 10_000,
        "cash": 10_000,
        "positions": [],
    }
    states = {asset["symbol"]: StrategyState(PositionState.ENTRY_CONFIRMING, 1) for asset in assets}
    evaluator = PortfolioEvaluator(default_definition())

    first = evaluator.evaluate(base, assets, cutoff, context, "b" * 64, states)
    second = evaluator.evaluate(appended, assets, cutoff, context, "b" * 64, states)

    assert canonical_hash(first) == canonical_hash(second)
    for before, after in zip(first["proposals"], second["proposals"]):
        assert before["decision_trace"]["factor_contributions"] == after["decision_trace"]["factor_contributions"]
        assert before["decision_trace"]["rank"] == after["decision_trace"]["rank"]
        assert before["decision_trace"]["portfolio_state"] == after["decision_trace"]["portfolio_state"]
        assert before["portfolio_target"] == after["portfolio_target"]
        assert before["order_intent"] == after["order_intent"]


def test_appended_future_cannot_change_past_fills_or_equity():
    base_prices = hourly_prices(16)
    extra = base_prices.iloc[-8:].copy()
    extra.index = pd.date_range(base_prices.index[-1] + pd.Timedelta(hours=1), periods=8, freq="h", tz="UTC")
    extra[["open", "high", "low", "close"]] *= 4
    future_prices = pd.concat([base_prices, extra])
    signals = pd.DataFrame(
        [{"asset": "BTC", "action": "ENTER"}, {"asset": "BTC", "action": "EXIT"}],
        index=[base_prices.index[1], base_prices.index[8]],
    )

    before = PortfolioSimulator(10_000, CostScenario(), seed=7).run({"BTC": base_prices}, signals)
    after = PortfolioSimulator(10_000, CostScenario(), seed=7).run({"BTC": future_prices}, signals)

    assert before["fills"] == after["fills"]
    assert before["trades"] == after["trades"]
    pd.testing.assert_series_equal(
        before["equity"],
        after["equity"].loc[after["equity"].index <= before["equity"].index[-1]],
    )
