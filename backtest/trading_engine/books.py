from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Dict, Iterable, List, Optional, Tuple


class SequenceGap(RuntimeError):
    pass


@dataclass(frozen=True)
class BookSummary:
    venue: str
    product_id: str
    event_time: datetime
    received_at: datetime
    sequence: Optional[int]
    best_bid: Optional[float]
    best_ask: Optional[float]
    spread_bps: Optional[float]
    book_age_ms: int
    is_valid: bool
    invalid_reason: Optional[str]
    depth: Dict[str, Dict[str, Optional[float]]]


class L2Book:
    def __init__(self, venue: str, product_id: str):
        self.venue = venue
        self.product_id = product_id
        self.bids: Dict[float, float] = {}
        self.asks: Dict[float, float] = {}
        self.sequence: Optional[int] = None
        self.event_time: Optional[datetime] = None
        self.received_at: Optional[datetime] = None
        self.valid = False
        self.invalid_reason: Optional[str] = "awaiting_snapshot"

    def snapshot(self, bids: Iterable[Tuple[float, float]], asks: Iterable[Tuple[float, float]], sequence: Optional[int], event_time: datetime, received_at: Optional[datetime] = None) -> None:
        self.bids = {float(price): float(size) for price, size in bids if float(size) > 0}
        self.asks = {float(price): float(size) for price, size in asks if float(size) > 0}
        self.sequence = sequence
        self.event_time = _utc(event_time)
        self.received_at = _utc(received_at or datetime.now(timezone.utc))
        self._validate()

    def update(self, changes: Iterable[Tuple[str, float, float]], sequence: Optional[int], event_time: datetime, received_at: Optional[datetime] = None) -> None:
        if not self.valid:
            raise SequenceGap("Book must be resynchronized before applying updates")
        if sequence is not None and self.sequence is not None and sequence != self.sequence + 1:
            self.valid = False
            self.invalid_reason = "sequence_gap"
            raise SequenceGap(f"Expected {self.sequence + 1}, received {sequence}")
        for side, price, size in changes:
            levels = self.bids if side.lower() in {"bid", "buy"} else self.asks
            price_value, size_value = float(price), float(size)
            if size_value <= 0:
                levels.pop(price_value, None)
            else:
                levels[price_value] = size_value
        self.sequence = sequence if sequence is not None else self.sequence
        self.event_time = _utc(event_time)
        self.received_at = _utc(received_at or datetime.now(timezone.utc))
        self._validate()

    def vwap(self, side: str, notional: float) -> Optional[float]:
        levels = self.asks if side.lower() == "buy" else self.bids
        ordered = sorted(levels.items(), key=lambda item: item[0], reverse=side.lower() == "sell")
        remaining = float(notional)
        quantity = cost = 0.0
        for price, size in ordered:
            level_notional = price * size
            consumed = min(remaining, level_notional)
            quantity += consumed / price
            cost += consumed
            remaining -= consumed
            if remaining <= 1e-9:
                break
        if remaining > 1e-6 or quantity <= 0:
            return None
        return cost / quantity

    def summary(self, notionals: Iterable[float], now: Optional[datetime] = None) -> BookSummary:
        current = _utc(now or datetime.now(timezone.utc))
        age = int((current - (self.received_at or current)).total_seconds() * 1000)
        best_bid = max(self.bids) if self.bids else None
        best_ask = min(self.asks) if self.asks else None
        spread = ((best_ask - best_bid) / ((best_ask + best_bid) / 2) * 10_000) if best_bid and best_ask else None
        depth = {str(int(n)): {"buy_vwap": self.vwap("buy", n), "sell_vwap": self.vwap("sell", n)} for n in notionals}
        valid = self.valid and age < 1000
        reason = self.invalid_reason if not self.valid else ("stale" if age >= 1000 else None)
        return BookSummary(self.venue, self.product_id, self.event_time or current, self.received_at or current, self.sequence, best_bid, best_ask, spread, age, valid, reason, depth)

    def _validate(self) -> None:
        if not self.bids or not self.asks:
            self.valid, self.invalid_reason = False, "empty_book"
        elif max(self.bids) >= min(self.asks):
            self.valid, self.invalid_reason = False, "crossed_book"
        else:
            self.valid, self.invalid_reason = True, None


def _utc(value: datetime) -> datetime:
    return value.replace(tzinfo=timezone.utc) if value.tzinfo is None else value.astimezone(timezone.utc)
