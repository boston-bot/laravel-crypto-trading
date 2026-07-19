from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from enum import Enum


class PositionState(str, Enum):
    FLAT = "flat"
    ENTRY_CONFIRMING = "entry_confirming"
    OPEN = "open"
    EXIT_CONFIRMING = "exit_confirming"
    COOLDOWN = "cooldown"


@dataclass(frozen=True)
class StrategyState:
    state: PositionState = PositionState.FLAT
    confirmation_bars: int = 0
    cooldown_bars_remaining: int = 0
    opened_at: datetime | None = None

    def advance(self, entry: bool, exit: bool, at: datetime, *, entry_bars: int = 2, exit_bars: int = 1, cooldown_bars: int = 2) -> "StrategyState":
        if at.tzinfo is None:
            at = at.replace(tzinfo=timezone.utc)
        if self.state is PositionState.COOLDOWN:
            remaining = max(0, self.cooldown_bars_remaining - 1)
            return StrategyState(PositionState.COOLDOWN if remaining else PositionState.FLAT, cooldown_bars_remaining=remaining)
        if self.state is PositionState.FLAT:
            return StrategyState(PositionState.ENTRY_CONFIRMING, 1) if entry else self
        if self.state is PositionState.ENTRY_CONFIRMING:
            if not entry:
                return StrategyState()
            count = self.confirmation_bars + 1
            return StrategyState(PositionState.OPEN, opened_at=at) if count >= entry_bars else StrategyState(PositionState.ENTRY_CONFIRMING, count)
        if self.state is PositionState.OPEN:
            return StrategyState(PositionState.EXIT_CONFIRMING, 1, opened_at=self.opened_at) if exit else self
        if self.state is PositionState.EXIT_CONFIRMING:
            if not exit:
                return StrategyState(PositionState.OPEN, opened_at=self.opened_at)
            count = self.confirmation_bars + 1
            return StrategyState(PositionState.COOLDOWN, cooldown_bars_remaining=cooldown_bars) if count >= exit_bars else StrategyState(PositionState.EXIT_CONFIRMING, count, opened_at=self.opened_at)
        return self
