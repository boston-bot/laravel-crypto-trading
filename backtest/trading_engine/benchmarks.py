from __future__ import annotations

from typing import Any
import pandas as pd


def benchmark_curves(prices: pd.DataFrame, initial_nav: float = 1.0, rebalance_cost_bps: float = 0.0) -> dict[str, pd.Series]:
    clean = prices.astype(float).sort_index().ffill()
    if clean.empty:
        empty = pd.Series([initial_nav], index=[pd.Timestamp.now(tz="UTC")], dtype=float)
        return {"equal_weight": empty, "btc": empty.copy(), "cash": empty.copy()}
    returns = clean.pct_change().fillna(0.0)
    active = clean.notna().astype(float)
    weights = active.div(active.sum(axis=1).replace(0, 1), axis=0)
    equal_returns = (returns * weights.shift(1).fillna(weights)).sum(axis=1)
    months = pd.Series(clean.index.strftime("%Y-%m"), index=clean.index)
    rebalance = months.ne(months.shift()).astype(float)
    equal_returns -= rebalance * rebalance_cost_bps / 10000
    equal = initial_nav * (1 + equal_returns).cumprod()
    btc_returns = returns["BTC"] if "BTC" in returns else pd.Series(0.0, index=clean.index)
    btc = initial_nav * (1 + btc_returns).cumprod()
    cash = pd.Series(initial_nav, index=clean.index, dtype=float)
    return {"equal_weight": equal, "btc": btc, "cash": cash}


def summarize_benchmarks(curves: dict[str, pd.Series]) -> dict[str, Any]:
    return {name: {"start_nav": float(series.iloc[0]), "end_nav": float(series.iloc[-1]), "return_pct": float((series.iloc[-1] / series.iloc[0] - 1) * 100)} for name, series in curves.items()}
