from __future__ import annotations

from datetime import datetime
from typing import List

import pandas as pd

from .contracts import BacktestSpec, Fold


def anchored_folds(spec: BacktestSpec) -> List[Fold]:
    start = pd.Timestamp(spec.start)
    end = pd.Timestamp(spec.end)
    if start.tzinfo is None:
        start = start.tz_localize("UTC")
    if end.tzinfo is None:
        end = end.tz_localize("UTC")
    holdout_start = end - pd.DateOffset(months=spec.holdout_months)
    train_start = start
    train_end = start + pd.DateOffset(months=spec.train_months)
    rows: List[Fold] = []
    number = 1
    embargo = pd.Timedelta(days=spec.embargo_days)
    while True:
        validation_start = train_end + embargo
        validation_end = validation_start + pd.DateOffset(months=spec.validation_months)
        test_start = validation_end + embargo
        test_end = test_start + pd.DateOffset(months=spec.test_months)
        if test_end > holdout_start:
            break
        rows.append(Fold(
            fold=number,
            train_start=train_start.to_pydatetime(), train_end=train_end.to_pydatetime(),
            validation_start=validation_start.to_pydatetime(), validation_end=validation_end.to_pydatetime(),
            test_start=test_start.to_pydatetime(), test_end=test_end.to_pydatetime(),
        ))
        train_end = train_end + pd.DateOffset(months=spec.step_months)
        number += 1
    return rows
