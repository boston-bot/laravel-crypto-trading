from trading_engine.attribution import trade_attribution


def test_attribution_reconciles_dimensions_and_costs():
    trades = [{"asset":"BTC","strategy_family":"trend","regime":"risk_on","exit_reason":"rule","pnl":10}, {"asset":"ETH","strategy_family":"trend","regime":"risk_on","exit_reason":"time","pnl":-2}]
    fills = [{"fee":1,"filled_quantity":1,"fill_price":101,"reference_price":100}]
    result = trade_attribution(trades, fills)
    assert sum(result["pnl"]["asset"].values()) == result["net_pnl"] == 8
    assert result["costs"]["total"] == 2
