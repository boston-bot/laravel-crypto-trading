from __future__ import annotations

from typing import Dict, Iterable

import numpy as np
import pandas as pd


def performance_metrics(equity: pd.Series, trade_pnls: Iterable[float], periods_per_year: int = 365 * 6) -> Dict[str, float]:
    clean = equity.astype(float).dropna()
    returns = clean.pct_change().dropna()
    mean = float(returns.mean()) if not returns.empty else 0.0
    std = float(returns.std(ddof=0)) if not returns.empty else 0.0
    downside = returns[returns < 0]
    downside_std = float(downside.std(ddof=0)) if not downside.empty else 0.0
    sharpe = mean / std * np.sqrt(periods_per_year) if std > 0 else 0.0
    sortino = mean / downside_std * np.sqrt(periods_per_year) if downside_std > 0 else 0.0
    running_max = clean.cummax()
    drawdown = ((clean / running_max) - 1.0) if not clean.empty else pd.Series(dtype=float)
    max_drawdown = abs(float(drawdown.min())) if not drawdown.empty else 0.0
    pnls = np.asarray(list(trade_pnls), dtype=float)
    gross_profit = float(pnls[pnls > 0].sum()) if pnls.size else 0.0
    gross_loss = abs(float(pnls[pnls < 0].sum())) if pnls.size else 0.0
    return {
        "total_return_pct": float((clean.iloc[-1] / clean.iloc[0] - 1) * 100) if len(clean) > 1 and clean.iloc[0] else 0.0,
        "sharpe": float(sharpe),
        "sortino": float(sortino),
        "max_drawdown_pct": max_drawdown * 100,
        "profit_factor": gross_profit / gross_loss if gross_loss > 0 else (float("inf") if gross_profit > 0 else 0.0),
        "trade_count": int(pnls.size),
        "average_win": float(pnls[pnls > 0].mean()) if (pnls > 0).any() else 0.0,
        "average_loss": float(pnls[pnls < 0].mean()) if (pnls < 0).any() else 0.0,
        "net_expectancy": float(pnls.mean()) if pnls.size else 0.0,
    }
