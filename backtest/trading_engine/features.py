from __future__ import annotations

from datetime import datetime, timezone
from typing import Dict

import numpy as np
import pandas as pd


REQUIRED_OHLCV = {"open", "high", "low", "close", "volume"}


def ensure_utc_index(frame: pd.DataFrame, timestamp_column: str = "candle_open_time") -> pd.DataFrame:
    result = frame.copy()
    if timestamp_column in result.columns:
        result[timestamp_column] = pd.to_datetime(result[timestamp_column], utc=True)
        result = result.set_index(timestamp_column)
    elif not isinstance(result.index, pd.DatetimeIndex):
        raise ValueError("Candles require a UTC DatetimeIndex or candle_open_time column")
    if result.index.tz is None:
        result.index = result.index.tz_localize("UTC")
    else:
        result.index = result.index.tz_convert("UTC")
    if not result.index.is_monotonic_increasing:
        result = result.sort_index()
    if result.index.has_duplicates:
        raise ValueError("Duplicate candle open times are not allowed")
    missing = REQUIRED_OHLCV.difference(result.columns)
    if missing:
        raise ValueError("Missing OHLCV fields: " + ", ".join(sorted(missing)))
    for column in REQUIRED_OHLCV:
        result[column] = pd.to_numeric(result[column], errors="coerce")
    return result


def point_in_time_slice(frame: pd.DataFrame, as_of: datetime) -> pd.DataFrame:
    """Return only final rows known by as_of; never infer availability from candle time."""
    if as_of.tzinfo is None:
        as_of = as_of.replace(tzinfo=timezone.utc)
    cutoff = pd.Timestamp(as_of).tz_convert("UTC")
    result = frame.copy()
    if "available_at" not in result.columns:
        raise ValueError("Point-in-time research requires available_at")
    available = pd.to_datetime(result["available_at"], utc=True)
    is_final = result["is_final"].fillna(False).astype(bool) if "is_final" in result else False
    result = result.loc[(available <= cutoff) & is_final]
    return ensure_utc_index(result)


def aggregate_hourly(frame: pd.DataFrame, timeframe: str) -> pd.DataFrame:
    hourly = ensure_utc_index(frame)
    rules = {"4h": "4h", "1d": "1D"}
    if timeframe not in rules:
        raise ValueError("Only 4h and 1d may be derived from canonical 1h bars")
    grouped = hourly.resample(rules[timeframe], origin="epoch", label="left", closed="left")
    result = grouped.agg(
        open=("open", "first"),
        high=("high", "max"),
        low=("low", "min"),
        close=("close", "last"),
        volume=("volume", "sum"),
    ).dropna(subset=["open", "high", "low", "close"])
    expected = 4 if timeframe == "4h" else 24
    counts = grouped["close"].count().reindex(result.index).fillna(0)
    result["is_final"] = counts.eq(expected)
    delta = pd.Timedelta(hours=4 if timeframe == "4h" else 24)
    result["candle_close_time"] = result.index + delta
    if "available_at" in hourly:
        result["available_at"] = grouped["available_at"].max().reindex(result.index)
    else:
        result["available_at"] = result["candle_close_time"]
    return result


def _rsi(close: pd.Series, period: int = 14) -> pd.Series:
    delta = close.diff()
    gain = delta.clip(lower=0).ewm(alpha=1 / period, adjust=False).mean()
    loss = (-delta.clip(upper=0)).ewm(alpha=1 / period, adjust=False).mean()
    rs = gain / loss.replace(0, np.nan)
    return (100 - (100 / (1 + rs))).fillna(50.0)


def _atr(frame: pd.DataFrame, period: int = 14) -> pd.Series:
    previous = frame["close"].shift(1)
    true_range = pd.concat(
        [frame["high"] - frame["low"], (frame["high"] - previous).abs(), (frame["low"] - previous).abs()],
        axis=1,
    ).max(axis=1)
    return true_range.ewm(alpha=1 / period, adjust=False).mean()


def compute_features(frame: pd.DataFrame, benchmark_close: pd.Series | None = None) -> pd.DataFrame:
    data = ensure_utc_index(frame)
    close = data["close"].astype(float)
    returns = close.pct_change()
    ema_fast = close.ewm(span=12, adjust=False).mean()
    ema_slow = close.ewm(span=26, adjust=False).mean()
    atr_pct = _atr(data) / close.replace(0, np.nan)
    volume = data["volume"].astype(float)
    volume_mean = volume.rolling(20, min_periods=5).mean()
    volume_std = volume.rolling(20, min_periods=5).std(ddof=0).replace(0, np.nan)

    features = pd.DataFrame(index=data.index)
    features["trend"] = np.tanh(((ema_fast / ema_slow) - 1.0) * 25.0).clip(-1, 1)
    features["momentum"] = ((close / close.shift(6)) - 1.0).fillna(0).clip(-0.25, 0.25) * 4.0
    features["rsi"] = _rsi(close)
    features["volatility"] = returns.rolling(20, min_periods=5).std(ddof=0) * np.sqrt(6 * 365)
    features["atr_pct"] = atr_pct.fillna(0)
    features["participation"] = ((volume - volume_mean) / volume_std).fillna(0).clip(-3, 3) / 3
    if benchmark_close is not None:
        aligned = benchmark_close.astype(float).reindex(close.index).ffill()
        features["relative_strength"] = (
            close.pct_change(18) - aligned.pct_change(18)
        ).fillna(0).clip(-0.25, 0.25) * 4.0
    else:
        features["relative_strength"] = close.pct_change(18).fillna(0).clip(-0.25, 0.25) * 4.0
    if "spread_bps" in data:
        features["execution_quality"] = (1 - data["spread_bps"].astype(float) / 100).clip(0, 1)
    else:
        features["execution_quality"] = 0.5
    features["regime"] = np.where(
        (features["trend"] > 0.05) & (features["volatility"].fillna(0) < 1.1), 1,
        np.where(features["trend"] < -0.05, -1, 0),
    )
    return features.replace([np.inf, -np.inf], np.nan).fillna(0)


def frozen_multi_horizon_features(frames: Dict[str, pd.DataFrame], as_of: datetime, benchmark_close: pd.Series | None = None) -> pd.DataFrame:
    """Build one canonical feature frame using only observations available by the cutoff."""
    cutoff = pd.Timestamp(as_of if as_of.tzinfo else as_of.replace(tzinfo=timezone.utc)).tz_convert("UTC")
    computed: Dict[str, pd.DataFrame] = {}
    for horizon in ("1h", "4h", "1d"):
        frame = frames.get(horizon)
        if frame is None or frame.empty:
            continue
        frozen = point_in_time_slice(frame, cutoff.to_pydatetime()) if "available_at" in frame.columns else ensure_utc_index(frame).loc[:cutoff]
        if not frozen.empty:
            computed[horizon] = compute_features(frozen, benchmark_close)
    if "4h" not in computed:
        return pd.DataFrame()
    result = computed["4h"].copy()
    source = ensure_utc_index(frames["4h"])
    result["reference_price"] = source["close"].reindex(result.index)
    for horizon in ("1h", "1d"):
        if horizon not in computed:
            continue
        latest = computed[horizon].reindex(result.index, method="ffill")
        for column in ("trend", "momentum", "regime", "atr_pct"):
            result[f"{horizon}_{column}"] = latest[column]
    return result.loc[result.index <= cutoff]


def score_latest(features: pd.DataFrame, has_position: bool = False) -> Dict[str, object]:
    if features.empty:
        return {"action": "HOLD", "score": 0.0, "factors": {}, "warnings": ["missing_features"]}
    row = features.iloc[-1]
    factors = {
        "trend": float(row["trend"]),
        "relative_strength": float(row["relative_strength"]),
        "momentum": float(row["momentum"]),
        "volatility_quality": float(max(-1, min(1, 1 - row["atr_pct"] * 20))),
        "participation": float(row["participation"]),
        "execution_quality": float(row["execution_quality"]),
    }
    raw = (
        factors["trend"] * 0.30
        + factors["relative_strength"] * 0.25
        + factors["momentum"] * 0.20
        + factors["volatility_quality"] * 0.15
        + factors["participation"] * 0.05
        + factors["execution_quality"] * 0.05
    )
    score = float(max(-1, min(1, raw)))
    regime = int(row["regime"])
    action = "HOLD"
    if has_position and (score < -0.10 or row["rsi"] > 78 or regime < 0):
        action = "EXIT"
    elif not has_position and score >= 0.18 and regime >= 0 and row["rsi"] <= 72:
        action = "ENTER"
    return {"action": action, "score": score, "factors": factors, "warnings": []}
