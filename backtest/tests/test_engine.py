from __future__ import annotations

import unittest
import json
from unittest.mock import patch
from pathlib import Path
from datetime import datetime, timedelta, timezone

import numpy as np
import pandas as pd

from trading_engine.books import L2Book, SequenceGap
from trading_engine.collector import kraken_checksum
from trading_engine.calibration import MonotonicCalibrator, calibration_report
from trading_engine.contracts import BacktestSpec
from trading_engine.features import aggregate_hourly, compute_features, point_in_time_slice
from trading_engine.database import connect
from trading_engine.simulation import CostScenario, PortfolioSimulator
from trading_engine.sentiment import sentiment_features
from trading_engine.spreads import evaluate_spread
from trading_engine.walk_forward import anchored_folds


def hourly(periods: int = 240, start: str = "2024-01-01") -> pd.DataFrame:
    index = pd.date_range(start, periods=periods, freq="h", tz="UTC")
    close = np.linspace(100, 120, periods)
    return pd.DataFrame({
        "open": close - 0.1, "high": close + 1, "low": close - 1, "close": close,
        "volume": np.full(periods, 1000.0), "available_at": index + pd.Timedelta(hours=1), "is_final": True,
    }, index=index)


class PointInTimeTests(unittest.TestCase):
    def test_database_connections_set_utc_before_use(self) -> None:
        class Cursor:
            def __init__(self) -> None:
                self.statements = []

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return None

            def execute(self, statement: str) -> None:
                self.statements.append(statement)

        class Connection:
            def __init__(self) -> None:
                self.cursor_instance = Cursor()
                self.committed = False
                self.closed = False

            def cursor(self):
                return self.cursor_instance

            def commit(self) -> None:
                self.committed = True

            def close(self) -> None:
                self.closed = True

        connection = Connection()
        with patch("psycopg.connect", return_value=connection):
            with connect("postgresql://test") as opened:
                self.assertIs(opened, connection)

        self.assertEqual(connection.cursor_instance.statements, ["SET TIME ZONE 'UTC'"])
        self.assertTrue(connection.committed)
        self.assertTrue(connection.closed)

    def test_filters_revisions_not_known_at_as_of(self) -> None:
        frame = hourly(10)
        frame.iloc[4, frame.columns.get_loc("available_at")] = frame.index[8]
        sliced = point_in_time_slice(frame.reset_index(names="candle_open_time"), frame.index[6].to_pydatetime())
        self.assertNotIn(frame.index[4], sliced.index)
        self.assertTrue((pd.to_datetime(sliced["available_at"], utc=True) <= frame.index[6]).all())

    def test_utc_aggregation_only_marks_complete_buckets_final(self) -> None:
        frame = hourly(5)
        aggregate = aggregate_hourly(frame, "4h")
        self.assertTrue(bool(aggregate.iloc[0]["is_final"]))
        self.assertFalse(bool(aggregate.iloc[1]["is_final"]))
        self.assertEqual(aggregate.index[0].hour, 0)

    def test_features_do_not_change_past_when_future_is_appended(self) -> None:
        frame = hourly(120)
        first = compute_features(frame.iloc[:100])
        second = compute_features(frame)
        pd.testing.assert_series_equal(first.iloc[-1], second.loc[first.index[-1]], check_names=False)


class CalibrationTests(unittest.TestCase):
    def test_monotonic_calibration_and_report(self) -> None:
        scores = np.linspace(-1, 1, 100)
        outcomes = (scores + np.sin(np.arange(100)) * 0.1 > 0).astype(float)
        calibrator = MonotonicCalibrator().fit(scores, outcomes)
        predicted = calibrator.predict(scores)
        self.assertTrue(np.all(np.diff(predicted) >= -1e-12))
        report = calibration_report(predicted, outcomes)
        self.assertIn("brier_score", report)
        self.assertLessEqual(report["brier_score"], 0.25)

    def test_sentiment_is_normalized_but_remains_a_separate_ablation_feature(self) -> None:
        features = sentiment_features([49, 48, 47, 46, 45, 44, 43, 42, 41, 40], 60)
        self.assertAlmostEqual(features["normalized_score"], 0.2)
        self.assertEqual(features["change_1d"], 11)
        self.assertIsNotNone(features["zscore_30d"])


class SimulationTests(unittest.TestCase):
    def test_fills_on_next_hour_open_and_models_costs(self) -> None:
        candles = {"BTC": hourly(12)}
        signals = pd.DataFrame([
            {"asset": "BTC", "action": "ENTER"},
            {"asset": "BTC", "action": "EXIT"},
        ], index=[candles["BTC"].index[1], candles["BTC"].index[5]])
        result = PortfolioSimulator(10_000, CostScenario(taker_fee_bps=50, participation_limit=1)).run(candles, signals)
        first = result["fills"][0]
        self.assertEqual(pd.Timestamp(first.timestamp), candles["BTC"].index[2])
        self.assertGreater(first.fill_price, first.reference_price)
        self.assertEqual(len(result["trades"]), 1)
        self.assertGreater(result["trades"][0].fees, 0)

    def test_missing_future_price_rejects_without_fallback(self) -> None:
        frame = hourly(2)
        signals = pd.DataFrame([{"asset": "BTC", "action": "ENTER"}], index=[frame.index[-1]])
        result = PortfolioSimulator(10_000, CostScenario()).run({"BTC": frame}, signals)
        self.assertEqual(result["fills"][0].reason, "no_next_eligible_price")


class BookTests(unittest.TestCase):
    def test_sequence_gap_invalidates_until_snapshot(self) -> None:
        now = datetime.now(timezone.utc)
        book = L2Book("coinbase", "BTC-USD")
        book.snapshot([(100, 2)], [(101, 2)], 10, now, now)
        with self.assertRaises(SequenceGap):
            book.update([("bid", 100, 1)], 12, now, now)
        self.assertFalse(book.valid)
        book.snapshot([(100, 2)], [(101, 2)], 20, now, now)
        self.assertTrue(book.valid)

    def test_spread_requires_cost_positive_time_comparable_depth(self) -> None:
        now = datetime.now(timezone.utc)
        buy = L2Book("coinbase", "BTC-USD")
        sell = L2Book("kraken", "BTC-USD")
        buy.snapshot([(99, 10)], [(100, 10)], 1, now, now)
        sell.snapshot([(102, 10)], [(103, 10)], 1, now, now)
        observation = evaluate_spread(buy.summary([500], now), sell.summary([500], now), 500, 10, 10, safety_buffer_bps=10)
        self.assertEqual(observation.classification, "executable")
        self.assertGreater(observation.net_edge_bps, 0)
        costly = evaluate_spread(buy.summary([500], now), sell.summary([500], now), 500, 150, 150, safety_buffer_bps=10)
        self.assertEqual(costly.classification, "negative-after-costs")

    def test_kraken_checksum_is_deterministic(self) -> None:
        now = datetime.now(timezone.utc)
        book = L2Book("kraken", "BTC-USD")
        book.snapshot([(100, 2), (99, 3)], [(101, 4), (102, 5)], None, now, now)
        self.assertEqual(kraken_checksum(book), kraken_checksum(book))
        original = kraken_checksum(book)
        book.update([("bid", 100, 2.5)], None, now, now)
        self.assertNotEqual(original, kraken_checksum(book))


class WalkForwardTests(unittest.TestCase):
    def test_locked_holdout_is_never_in_a_fold(self) -> None:
        spec = BacktestSpec("v1", "core-v1", datetime(2018, 1, 1, tzinfo=timezone.utc), datetime(2026, 1, 1, tzinfo=timezone.utc))
        folds = anchored_folds(spec)
        holdout_start = pd.Timestamp(spec.end) - pd.DateOffset(months=12)
        self.assertGreaterEqual(len(folds), 3)
        self.assertTrue(all(pd.Timestamp(fold.test_end) <= holdout_start for fold in folds))
        self.assertTrue(all((fold.validation_start - fold.train_end).days >= 30 for fold in folds))

    def test_shared_evaluation_fixture_matches_contract(self) -> None:
        root = Path(__file__).resolve().parents[2]
        fixture = json.loads((root / "contracts/fixtures/evaluation-result-v1.json").read_text())
        required = {"engine_version", "schema_version", "job_id", "as_of", "valid_until", "manifest_hash", "proposals"}
        self.assertTrue(required.issubset(fixture))
        self.assertIn(fixture["proposals"][0]["action"], {"ENTER", "EXIT", "HOLD"})
        self.assertGreaterEqual(fixture["proposals"][0]["calibrated_probability"], 0)
        self.assertLessEqual(fixture["proposals"][0]["calibrated_probability"], 1)


if __name__ == "__main__":
    unittest.main()
