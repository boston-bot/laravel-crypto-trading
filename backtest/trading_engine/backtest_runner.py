from __future__ import annotations

from dataclasses import asdict
from datetime import datetime, timezone
from typing import Any, Dict, List, Tuple

import numpy as np
import pandas as pd

from .calibration import MonotonicCalibrator, calibration_report
from .contracts import BacktestSpec
from .database import canonical_hash
from .features import compute_features
from .simulation import CostScenario, PortfolioSimulator
from .walk_forward import anchored_folds


def _candles(connection: Any, asset_id: int, timeframe: str, start: datetime, end: datetime) -> pd.DataFrame:
    with connection.cursor() as cursor:
        cursor.execute(
            """
            WITH versions AS (
                SELECT c.id AS candle_id, c.candle_open_time, c.candle_close_time,
                       c.open, c.high, c.low, c.close, c.volume, c.available_at,
                       c.is_final, c.quality_state
                FROM market_candles c
                WHERE c.asset_id=%s AND c.timeframe=%s
                  AND c.candle_open_time >= %s AND c.candle_open_time < %s
                UNION ALL
                SELECT c.id, c.candle_open_time, c.candle_close_time,
                       (r.values_json->>'open')::numeric, (r.values_json->>'high')::numeric,
                       (r.values_json->>'low')::numeric, (r.values_json->>'close')::numeric,
                       (r.values_json->>'volume')::numeric, r.available_at,
                       COALESCE((r.values_json->>'is_final')::boolean, true),
                       COALESCE(r.values_json->>'quality_state', 'valid')
                FROM market_candle_revisions r
                JOIN market_candles c ON c.id=r.market_candle_id
                WHERE c.asset_id=%s AND c.timeframe=%s
                  AND c.candle_open_time >= %s AND c.candle_open_time < %s
            )
            SELECT candle_open_time, open, high, low, close, volume, available_at, is_final
            FROM (
                SELECT DISTINCT ON (candle_open_time) *
                FROM versions
                WHERE is_final=true AND quality_state='valid'
                  AND available_at <= candle_close_time + interval '5 minutes'
                ORDER BY candle_open_time, available_at DESC
            ) point_in_time
            ORDER BY candle_open_time
            """,
            (asset_id, timeframe, start, end, asset_id, timeframe, start, end),
        )
        columns = [item.name for item in cursor.description]
        return pd.DataFrame([dict(zip(columns, row)) for row in cursor.fetchall()])


def _signal_frame(features: pd.DataFrame, symbol: str) -> pd.DataFrame:
    factors = pd.DataFrame(index=features.index)
    factors["score"] = (
        features["trend"] * 0.30 + features["relative_strength"] * 0.25 + features["momentum"] * 0.20
        + (1 - features["atr_pct"] * 20).clip(-1, 1) * 0.15
        + features["participation"] * 0.05 + features["execution_quality"] * 0.05
    ).clip(-1, 1)
    factors["asset"] = symbol
    factors["action"] = np.where(
        (factors["score"] >= 0.18) & (features["regime"] >= 0) & (features["rsi"] <= 72), "ENTER",
        np.where((factors["score"] < -0.10) | (features["regime"] < 0) | (features["rsi"] > 78), "EXIT", "HOLD"),
    )
    factors["future_return"] = features.index.to_series().map(lambda _: np.nan)
    return factors


def _load_universe(connection: Any, assets: List[Dict[str, Any]], start: datetime, end: datetime) -> Tuple[Dict[str, pd.DataFrame], Dict[str, pd.DataFrame]]:
    hourly: Dict[str, pd.DataFrame] = {}
    four_hour: Dict[str, pd.DataFrame] = {}
    for asset in assets:
        symbol = str(asset["symbol"])
        hourly[symbol] = _candles(connection, int(asset["id"]), "1h", start, end)
        four_hour[symbol] = _candles(connection, int(asset["id"]), "4h", start, end)
    return hourly, four_hour


def execute_backtest(connection: Any, payload: Dict[str, Any]) -> Dict[str, Any]:
    raw = payload["spec"]
    start = datetime.fromisoformat(str(raw["start"]).replace("Z", "+00:00"))
    end = datetime.fromisoformat(str(raw["end"]).replace("Z", "+00:00"))
    spec = BacktestSpec(
        strategy_version=str(raw["strategy_version"]), universe_version=str(raw["universe_version"]),
        start=start, end=end, initial_capital=float(raw.get("initial_capital", 100_000)),
        random_seed=int(raw.get("random_seed", 7)), train_months=int(raw.get("train_months", 24)),
        validation_months=int(raw.get("validation_months", 6)), test_months=int(raw.get("test_months", 6)),
        step_months=int(raw.get("step_months", 6)), embargo_days=int(raw.get("embargo_days", 30)),
        holdout_months=int(raw.get("holdout_months", 12)), fee_bps=float(raw.get("fee_bps", 60)),
        spread_bps=float(raw.get("spread_bps", 10)), slippage_bps=float(raw.get("slippage_bps", 8)),
    )
    hourly, four_hour = _load_universe(connection, list(payload["assets"]), start, end)
    feature_frames: Dict[str, pd.DataFrame] = {}
    signal_frames: Dict[str, pd.DataFrame] = {}
    benchmark = None
    if not four_hour.get("BTC", pd.DataFrame()).empty:
        benchmark_frame = four_hour["BTC"].set_index(pd.to_datetime(four_hour["BTC"]["candle_open_time"], utc=True))
        benchmark = pd.to_numeric(benchmark_frame["close"])
    for symbol, frame in four_hour.items():
        if frame.empty:
            continue
        features = compute_features(frame, benchmark)
        feature_frames[symbol] = features
        signals = _signal_frame(features, symbol)
        prices = pd.to_numeric(frame.set_index(pd.to_datetime(frame["candle_open_time"], utc=True))["close"])
        signals["future_return"] = prices.shift(-6) / prices - 1
        signal_frames[symbol] = signals

    folds = anchored_folds(spec)
    fold_results = []
    all_probabilities: List[float] = []
    all_outcomes: List[float] = []
    all_trade_pnls: List[float] = []
    all_trades: List[Dict[str, Any]] = []
    all_fills: List[Dict[str, Any]] = []
    all_equity: List[Dict[str, Any]] = []
    total_fees = 0.0
    for fold in folds:
        train = pd.concat([
            signals.loc[(signals.index >= fold.train_start) & (signals.index < fold.train_end), ["score", "future_return"]]
            for signals in signal_frames.values()
        ]).dropna()
        test = pd.concat([
            signals.loc[(signals.index >= fold.test_start) & (signals.index < fold.test_end)]
            for signals in signal_frames.values()
        ]).sort_index()
        calibrator = None
        if len(train) >= 20 and (train["future_return"] > 0).nunique() > 1:
            calibrator = MonotonicCalibrator().fit(train["score"].to_numpy(), (train["future_return"] > 0).astype(float).to_numpy())
        actionable = test.loc[test["action"] != "HOLD"].copy()
        entries = actionable.loc[actionable["action"] == "ENTER"]
        probabilities = calibrator.predict(entries["score"].to_numpy()) if calibrator and not entries.empty else np.clip(0.5 + entries["score"].to_numpy() * 0.35, 0.01, 0.99)
        outcomes = (entries["future_return"].fillna(0) > 0).astype(float).to_numpy()
        all_probabilities.extend(probabilities.tolist())
        all_outcomes.extend(outcomes.tolist())
        price_frames = {
            symbol: frame.loc[
                (pd.to_datetime(frame["candle_open_time"], utc=True) >= pd.Timestamp(fold.test_start))
                & (pd.to_datetime(frame["candle_open_time"], utc=True) < pd.Timestamp(fold.test_end))
            ]
            for symbol, frame in hourly.items() if not frame.empty
        }
        simulator = PortfolioSimulator(
            spec.initial_capital,
            CostScenario(taker_fee_bps=spec.fee_bps, spread_bps=spec.spread_bps, base_slippage_bps=spec.slippage_bps),
            seed=spec.random_seed + fold.fold,
        )
        replay = simulator.run(price_frames, actionable[["asset", "action"]])
        stressed = PortfolioSimulator(
            spec.initial_capital,
            CostScenario(taker_fee_bps=spec.fee_bps * 1.5, spread_bps=spec.spread_bps * 2, base_slippage_bps=spec.slippage_bps * 2),
            seed=spec.random_seed + fold.fold,
        ).run(price_frames, actionable[["asset", "action"]])
        trades = [asdict(item) for item in replay["trades"]]
        fills = [asdict(item) for item in replay["fills"]]
        for item in trades:
            item["entry_time"] = item["entry_time"].isoformat(); item["exit_time"] = item["exit_time"].isoformat()
        for item in fills:
            item["timestamp"] = item["timestamp"].isoformat()
        all_trades.extend(trades); all_fills.extend(fills)
        all_trade_pnls.extend([float(item.pnl) for item in replay["trades"]])
        total_fees += sum(float(item.fee) for item in replay["fills"])
        all_equity.extend([{"timestamp": timestamp.isoformat(), "equity": float(value), "fold": fold.fold} for timestamp, value in replay["equity"].items()])
        fold_results.append({
            "fold": fold.fold, "windows": {key: value.isoformat() for key, value in asdict(fold).items() if key != "fold"},
            "normal": replay["metrics"], "stressed": stressed["metrics"], "actionable_signals": int(len(actionable)),
        })

    calibration = calibration_report(np.asarray(all_probabilities), np.asarray(all_outcomes)) if all_probabilities else None
    metric_keys = ["sharpe", "sortino", "max_drawdown_pct", "profit_factor", "net_expectancy", "trade_count"]
    aggregate = {
        key: float(np.median([fold["normal"][key] for fold in fold_results])) if fold_results else 0.0
        for key in metric_keys
    }
    stressed_aggregate = {
        key: float(np.median([fold["stressed"][key] for fold in fold_results])) if fold_results else 0.0
        for key in metric_keys
    }
    contributions: Dict[str, float] = {}
    total_positive = sum(max(0, float(item["pnl"])) for item in all_trades)
    for item in all_trades:
        contributions[item["asset"]] = contributions.get(item["asset"], 0.0) + max(0, float(item["pnl"]))
    contribution_pct = {asset: value / total_positive * 100 if total_positive else 0.0 for asset, value in contributions.items()}
    gate = {
        "min_oos_trades": len(all_trades) >= 100,
        "min_assets": len({item["asset"] for item in all_trades}) >= 3,
        "positive_normal_expectancy": aggregate["net_expectancy"] > 0,
        "positive_stressed_expectancy": stressed_aggregate["net_expectancy"] > 0,
        "min_sharpe": aggregate["sharpe"] >= 0.8,
        "min_profit_factor": aggregate["profit_factor"] >= 1.2,
        "max_drawdown": aggregate["max_drawdown_pct"] <= 8,
        "asset_concentration": max(contribution_pct.values(), default=0) <= 50,
    }
    manifest = {
        "start": start.isoformat(), "end": end.isoformat(), "assets": payload["assets"],
        "rows": {symbol: {"1h": len(hourly.get(symbol, [])), "4h": len(four_hour.get(symbol, []))} for symbol in hourly},
        "locked_holdout_start": (pd.Timestamp(end) - pd.DateOffset(months=spec.holdout_months)).isoformat(),
    }
    return {
        "spec": asdict(spec) | {"start": start.isoformat(), "end": end.isoformat()},
        "manifest": manifest, "manifest_hash": canonical_hash(manifest), "folds": fold_results,
        "aggregate_metrics": aggregate, "stressed_metrics": stressed_aggregate,
        "calibration": calibration,
        "sentiment_ablation": {"retained": False, "reason": "Sentiment cannot be retained until identical point-in-time folds improve median OOS expectancy and calibration without material drawdown degradation."},
        "trades": all_trades, "fills": all_fills, "equity": all_equity,
        "benchmarks": {}, "cost_attribution": {"fees_usd": total_fees, "spread_and_slippage_in_fill_prices": True},
        "asset_profit_contribution_pct": contribution_pct, "gate": gate, "gate_passed": all(gate.values()),
    }
