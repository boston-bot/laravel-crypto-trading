import pandas as pd
from trading_engine.benchmarks import benchmark_curves


def test_benchmarks_include_equal_weight_btc_and_cash_with_monthly_costs():
    index = pd.date_range("2025-01-01", periods=90, freq="D", tz="UTC")
    prices = pd.DataFrame({"BTC": range(100,190), "ETH": range(50,140)}, index=index)
    curves = benchmark_curves(prices, rebalance_cost_bps=10)
    assert set(curves) == {"equal_weight", "btc", "cash"}
    assert curves["cash"].nunique() == 1
    assert curves["equal_weight"].iloc[-1] > curves["equal_weight"].iloc[0]
