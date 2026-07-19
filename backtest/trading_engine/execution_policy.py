from __future__ import annotations

from decimal import Decimal, ROUND_DOWN
import math
from typing import Any

from .strategy_definition import canonical_hash


def normalize_quantity(quantity: float, base_increment: str) -> float:
    value, increment = Decimal(str(quantity)), Decimal(str(base_increment))
    if increment <= 0:
        raise ValueError("base increment must be positive")
    return float((value / increment).to_integral_value(rounding=ROUND_DOWN) * increment)


def build_order_intent(*, asset_id: int, asset: str, side: str, target_weight: float, delta_weight: float, equity: float, reference_price: float, earliest_execution_at: str, portfolio_context_hash: str, portfolio_target_hash: str, base_increment: str = "0.00000001", minimum_notional: float = 1.0, costs: dict[str, float] | None = None, idempotency_key: str) -> dict[str, Any] | None:
    if not all(math.isfinite(value) for value in (target_weight, delta_weight, equity, reference_price)) or reference_price <= 0:
        raise ValueError("order inputs must be finite")
    quantity = normalize_quantity(abs(delta_weight) * equity / reference_price, base_increment)
    if quantity * reference_price < minimum_notional or quantity <= 0:
        return None
    intent = {"schema_version": "1.0", "venue": "coinbase", "product_id": f"{asset.upper()}-USD", "asset_id": asset_id, "asset": asset.upper(), "side": side.lower(), "target_weight": target_weight, "delta_weight": delta_weight, "unrounded_base_quantity": abs(delta_weight) * equity / reference_price, "normalized_base_quantity": quantity, "reference_price": reference_price, "minimum_notional": minimum_notional, "base_increment": base_increment, "earliest_execution_at": earliest_execution_at, "time_in_force": "IOC", "execution_policy_version": "coinbase-ioc-v1", "expected_costs": costs or {"fee_bps": 60.0, "spread_bps": 10.0, "slippage_bps": 8.0}, "portfolio_context_hash": portfolio_context_hash, "portfolio_target_hash": portfolio_target_hash, "idempotency_key": idempotency_key}
    intent["intent_hash"] = canonical_hash(intent)
    return intent


def slippage_bps(notional: float, volatility: float, spread_bps: float, liquidity_score: float) -> float:
    size_impact = min(40.0, max(0.0, notional / 1000 * 6.5))
    volatility_impact = max(0.0, volatility * 10000 * .08)
    spread_impact = max(2.0, spread_bps / 2)
    liquidity_penalty = max(0.0, (1 - min(1.0, max(0.0, liquidity_score))) * 20)
    return round(size_impact + volatility_impact + spread_impact + liquidity_penalty, 4)


def fill_accounting(quantity: float, reference_price: float, side: str, *, fee_bps: float, spread_bps: float, volatility: float, liquidity_score: float) -> dict[str, float]:
    notional = quantity * reference_price
    slip = slippage_bps(notional, volatility, spread_bps, liquidity_score)
    direction = 1 if side.lower() == "buy" else -1
    fill_price = round(reference_price * (1 + direction * slip / 10000), 8)
    filled_notional = round(quantity * fill_price, 8)
    return {"quantity": quantity, "reference_price": reference_price, "fill_price": fill_price, "filled_notional": filled_notional, "fee": round(filled_notional * fee_bps / 10000, 8), "slippage_bps": slip}
