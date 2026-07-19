from trading_engine.execution_policy import build_order_intent, fill_accounting, normalize_quantity


def test_coinbase_quantity_is_rounded_down_and_minimum_is_enforced():
    assert normalize_quantity(.123456789, ".00001") == .12345
    assert build_order_intent(asset_id=1, asset="BTC", side="buy", target_weight=.01, delta_weight=.01, equity=10, reference_price=100, earliest_execution_at="2026-01-01T00:00:00+00:00", portfolio_context_hash="a"*64, portfolio_target_hash="b"*64, minimum_notional=2, idempotency_key="x") is None


def test_fill_accounting_matches_declared_precision():
    result = fill_accounting(.1, 100, "buy", fee_bps=60, spread_bps=100, volatility=.03, liquidity_score=.7)
    assert result == {"quantity": .1, "reference_price": 100, "fill_price": 100.80065, "filled_notional": 10.080065, "fee": .06048039, "slippage_bps": 80.065}
