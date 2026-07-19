from __future__ import annotations

from dataclasses import asdict, dataclass
from typing import Dict, Optional

from .books import BookSummary


@dataclass(frozen=True)
class SpreadObservation:
    product_id: str
    buy_venue: str
    sell_venue: str
    notional_usd: float
    classification: str
    gross_edge_bps: Optional[float]
    net_edge_bps: Optional[float]
    buy_vwap: Optional[float]
    sell_vwap: Optional[float]
    receive_delta_ms: int
    rejection_reasons: list[str]
    cost_components_bps: Dict[str, float]

    def to_dict(self) -> Dict[str, object]:
        return asdict(self)


def evaluate_spread(buy: BookSummary, sell: BookSummary, notional: float, buy_fee_bps: Optional[float], sell_fee_bps: Optional[float], impact_bps: float = 0, rebalance_reserve_bps: float = 5, safety_buffer_bps: float = 10) -> SpreadObservation:
    reasons: list[str] = []
    delta_ms = int(abs((buy.received_at - sell.received_at).total_seconds()) * 1000)
    bucket = str(int(notional))
    buy_vwap = buy.depth.get(bucket, {}).get("buy_vwap")
    sell_vwap = sell.depth.get(bucket, {}).get("sell_vwap")
    classification = "executable"
    if not buy.is_valid or not sell.is_valid:
        classification, reasons = "invalid-book", [buy.invalid_reason or "buy_invalid", sell.invalid_reason or "sell_invalid"]
    elif buy.book_age_ms >= 1000 or sell.book_age_ms >= 1000 or delta_ms > 500:
        classification, reasons = "stale", ["books_not_time_comparable"]
    elif buy_fee_bps is None or sell_fee_bps is None:
        classification, reasons = "fee-uncertain", ["missing_or_stale_fee"]
    elif buy_vwap is None or sell_vwap is None:
        classification, reasons = "insufficient-depth", ["notional_not_executable"]
    gross = ((sell_vwap - buy_vwap) / buy_vwap * 10_000) if buy_vwap and sell_vwap else None
    costs = {
        "buy_fee": float(buy_fee_bps or 0), "sell_fee": float(sell_fee_bps or 0),
        "impact": float(impact_bps), "rebalance_reserve": float(rebalance_reserve_bps), "safety_buffer": float(safety_buffer_bps),
    }
    net = gross - sum(costs.values()) if gross is not None and buy_fee_bps is not None and sell_fee_bps is not None else None
    if classification == "executable" and (net is None or net <= 0):
        classification, reasons = "negative-after-costs", ["costs_exceed_gross_edge"]
    return SpreadObservation(buy.product_id, buy.venue, sell.venue, notional, classification, gross, net, buy_vwap, sell_vwap, delta_ms, reasons, costs)
