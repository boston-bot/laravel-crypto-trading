from dataclasses import dataclass
import pandas as pd
import pytest
from trading_engine.experiment_runner import link_fold_nav, run_nested_experiment


@dataclass
class Fold: fold: int


def test_validation_selects_child_and_test_only_measures_selected_definition():
    calls=[]; candidates=[{"name":"a"},{"name":"b"}]
    def evaluate(candidate, window, fold):
        calls.append((candidate["name"], window)); return {"selection_score": 2 if candidate["name"] == "b" else 1}
    result=run_nested_experiment([Fold(1)], candidates, evaluate)
    assert result[0]["selected_definition"]["name"] == "b"
    assert ("a","test") not in calls and ("b","test") in calls


def test_fold_nav_links_geometrically_without_resetting_to_one():
    first=pd.Series([1,1.1], index=pd.date_range("2025-01-01",periods=2,tz="UTC")); second=pd.Series([1,.9],index=pd.date_range("2025-02-01",periods=2,tz="UTC"))
    linked=link_fold_nav([first,second])
    assert linked.iloc[-1] == pytest.approx(.99)
