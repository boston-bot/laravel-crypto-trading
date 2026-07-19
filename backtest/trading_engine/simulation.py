from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from typing import Dict, List, Tuple

import numpy as np
import pandas as pd

from .contracts import ClosedTrade, Fill
from .features import ensure_utc_index
from .metrics import performance_metrics


@dataclass(frozen=True)
class CostScenario:
    taker_fee_bps: float = 60.0
    spread_bps: float = 10.0
    base_slippage_bps: float = 8.0
    volatility_multiplier: float = 25.0
    participation_limit: float = 0.01


@dataclass
class Position:
    quantity: float
    entry_price: float
    entry_time: datetime
    entry_fee: float


class PortfolioSimulator:
    """Long/flat replay that fills at the next eligible hourly bar, never the signal close."""

    def __init__(self, initial_capital: float, costs: CostScenario, max_positions: int = 2, max_asset_pct: float = 0.20, max_gross_pct: float = 0.40, seed: int = 7):
        self.initial_capital = float(initial_capital)
        self.costs = costs
        self.max_positions = max_positions
        self.max_asset_pct = max_asset_pct
        self.max_gross_pct = max_gross_pct
        self.random = np.random.default_rng(seed)

    def run(self, candles: Dict[str, pd.DataFrame], signals: pd.DataFrame, approval_delay_hours: int = 0) -> Dict[str, object]:
        frames = {symbol: ensure_utc_index(frame) for symbol, frame in candles.items()}
        cash = self.initial_capital
        positions: Dict[str, Position] = {}
        fills: List[Fill] = []
        trades: List[ClosedTrade] = []
        equity_points: List[Tuple[pd.Timestamp, float]] = []
        latest_prices: Dict[str, float] = {}

        ordered = signals.copy()
        ordered.index = pd.to_datetime(ordered.index, utc=True)
        ordered = ordered.sort_index()
        for signal_time, signal in ordered.iterrows():
            symbol = str(signal["asset"])
            action = str(signal["action"]).upper()
            frame = frames.get(symbol)
            if frame is None:
                fills.append(self._rejection(signal_time, symbol, action, "product_unavailable"))
                continue
            eligible = frame.loc[frame.index > signal_time + pd.Timedelta(hours=approval_delay_hours)]
            if eligible.empty:
                fills.append(self._rejection(signal_time, symbol, action, "no_next_eligible_price"))
                continue
            timestamp = eligible.index[0]
            bar = eligible.iloc[0]
            reference = float(bar["open"])
            if not np.isfinite(reference) or reference <= 0:
                fills.append(self._rejection(timestamp, symbol, action, "invalid_price"))
                continue
            latest_prices[symbol] = reference
            equity = cash + sum(position.quantity * latest_prices.get(asset, position.entry_price) for asset, position in positions.items())

            if action == "ENTER":
                if symbol in positions or len(positions) >= self.max_positions:
                    fills.append(self._rejection(timestamp, symbol, action, "position_limit"))
                    continue
                gross = sum(position.quantity * latest_prices.get(asset, position.entry_price) for asset, position in positions.items())
                target = min(equity * self.max_asset_pct, max(0.0, equity * self.max_gross_pct - gross), cash)
                fill, quantity, fee = self._fill(timestamp, symbol, "BUY", target / reference, reference, bar)
                fills.append(fill)
                actual_cost = quantity * fill.fill_price + fee
                if fill.status in {"filled", "partial"} and quantity > 0 and actual_cost <= cash + 1e-8:
                    cash -= actual_cost
                    positions[symbol] = Position(quantity, fill.fill_price, timestamp.to_pydatetime(), fee)
            elif action == "EXIT":
                position = positions.get(symbol)
                if position is None:
                    fills.append(self._rejection(timestamp, symbol, action, "no_position"))
                    continue
                fill, quantity, fee = self._fill(timestamp, symbol, "SELL", position.quantity, reference, bar)
                fills.append(fill)
                if fill.status in {"filled", "partial"} and quantity > 0:
                    proceeds = quantity * fill.fill_price - fee
                    cash += proceeds
                    allocated_entry_fee = position.entry_fee * (quantity / position.quantity)
                    pnl = proceeds - quantity * position.entry_price - allocated_entry_fee
                    trades.append(ClosedTrade(
                        asset=symbol, entry_time=position.entry_time, exit_time=timestamp.to_pydatetime(), quantity=quantity,
                        entry_price=position.entry_price, exit_price=fill.fill_price, fees=allocated_entry_fee + fee,
                        pnl=pnl, return_pct=(fill.fill_price / position.entry_price - 1) * 100,
                        hold_hours=(timestamp.to_pydatetime() - position.entry_time).total_seconds() / 3600,
                    ))
                    remaining = position.quantity - quantity
                    if remaining <= 1e-12:
                        del positions[symbol]
                    else:
                        position.quantity = remaining
                        position.entry_fee -= allocated_entry_fee
            equity = cash + sum(position.quantity * latest_prices.get(asset, position.entry_price) for asset, position in positions.items())
            equity_points.append((timestamp, equity))

        equity_series = pd.Series({timestamp: value for timestamp, value in equity_points}, dtype=float).sort_index()
        if equity_series.empty:
            equity_series = pd.Series([self.initial_capital], index=[pd.Timestamp.now(tz="UTC")], dtype=float)
        return {
            "fills": fills,
            "trades": trades,
            "equity": equity_series,
            "open_positions": positions,
            "cash": cash,
            "metrics": performance_metrics(equity_series, [trade.pnl for trade in trades]),
        }

    def _fill(self, timestamp: pd.Timestamp, symbol: str, side: str, requested_quantity: float, reference: float, bar: pd.Series) -> Tuple[Fill, float, float]:
        turnover = max(0.0, float(bar.get("volume", 0))) * reference
        max_notional = turnover * self.costs.participation_limit
        requested_notional = requested_quantity * reference
        filled_notional = min(requested_notional, max_notional) if max_notional > 0 else 0.0
        quantity = filled_notional / reference if reference > 0 else 0.0
        status = "filled" if quantity >= requested_quantity * 0.999 else ("partial" if quantity > 0 else "rejected")
        volatility = max(0.0, float(bar.get("atr_pct", 0.03)))
        size_ratio = requested_notional / max(turnover, requested_notional, 1.0)
        slippage = self.costs.base_slippage_bps + volatility * self.costs.volatility_multiplier * 100 + np.sqrt(size_ratio) * 5
        all_in_bps = self.costs.spread_bps / 2 + slippage
        direction = 1 if side == "BUY" else -1
        fill_price = reference * (1 + direction * all_in_bps / 10_000)
        fee = quantity * fill_price * self.costs.taker_fee_bps / 10_000
        return Fill(timestamp.to_pydatetime(), symbol, side, requested_quantity, quantity, reference, fill_price, fee, float(slippage), status, None if quantity else "insufficient_liquidity"), quantity, fee

    def _rejection(self, timestamp: pd.Timestamp, symbol: str, action: str, reason: str) -> Fill:
        side = "BUY" if action == "ENTER" else "SELL"
        return Fill(pd.Timestamp(timestamp).to_pydatetime(), symbol, side, 0, 0, 0, 0, 0, 0, "rejected", reason)
