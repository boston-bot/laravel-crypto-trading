from __future__ import annotations

from dataclasses import dataclass
from typing import Dict, List

import numpy as np
from sklearn.isotonic import IsotonicRegression


@dataclass
class MonotonicCalibrator:
    out_of_bounds: str = "clip"

    def __post_init__(self) -> None:
        self.model = IsotonicRegression(out_of_bounds=self.out_of_bounds, y_min=0.0, y_max=1.0)
        self.fitted = False

    def fit(self, scores: np.ndarray, outcomes: np.ndarray) -> "MonotonicCalibrator":
        scores = np.asarray(scores, dtype=float)
        outcomes = np.asarray(outcomes, dtype=float)
        if scores.size < 20 or np.unique(outcomes).size < 2:
            raise ValueError("Calibration needs at least 20 samples with both outcomes")
        self.model.fit(scores, outcomes)
        self.fitted = True
        return self

    def predict(self, scores: np.ndarray) -> np.ndarray:
        if not self.fitted:
            raise RuntimeError("Calibrator has not been fitted")
        return np.asarray(self.model.predict(np.asarray(scores, dtype=float)), dtype=float)


def calibration_report(probabilities: np.ndarray, outcomes: np.ndarray, buckets: int = 10) -> Dict[str, object]:
    probability = np.clip(np.asarray(probabilities, dtype=float), 0, 1)
    outcome = np.asarray(outcomes, dtype=float)
    if probability.size != outcome.size or probability.size == 0:
        raise ValueError("Probability and outcome arrays must be non-empty and equal length")
    brier = float(np.mean((probability - outcome) ** 2))
    boundaries = np.linspace(0, 1, buckets + 1)
    rows: List[Dict[str, float]] = []
    expected_error = 0.0
    for index in range(buckets):
        lower, upper = boundaries[index], boundaries[index + 1]
        mask = (probability >= lower) & (probability <= upper if index == buckets - 1 else probability < upper)
        if not mask.any():
            continue
        predicted = float(probability[mask].mean())
        observed = float(outcome[mask].mean())
        count = int(mask.sum())
        expected_error += (count / probability.size) * abs(predicted - observed)
        rows.append({"lower": float(lower), "upper": float(upper), "count": count, "predicted": predicted, "observed": observed})
    return {"brier_score": brier, "expected_calibration_error": float(expected_error), "reliability_buckets": rows}
