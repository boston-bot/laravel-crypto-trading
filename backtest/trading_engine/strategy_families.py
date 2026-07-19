from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping


@dataclass(frozen=True)
class FamilyEvaluation:
    score: float
    factors: dict[str, float]
    rules: list[dict[str, Any]]


def _v(row: Mapping[str, Any], key: str) -> float:
    return float(row.get(key, 0.0))


def evaluate_family(family: str, row: Mapping[str, Any]) -> FamilyEvaluation:
    trend, momentum, relative = _v(row, "trend"), _v(row, "momentum"), _v(row, "relative_strength")
    rsi, atr, participation = _v(row, "rsi"), _v(row, "atr_pct"), _v(row, "participation")
    execution = _v(row, "execution_quality")
    if family == "trend_rotation":
        factors = {"trend": trend * .38, "relative_strength": relative * .32, "momentum": momentum * .20, "execution": execution * .10}
        rules = [{"rule": "positive_trend", "passed": trend > .05, "observed": trend, "required": .05}, {"rule": "no_chase", "passed": rsi <= 72, "observed": rsi, "required": 72}]
    elif family == "pullback_in_trend":
        pullback = max(-1.0, min(1.0, (55.0 - rsi) / 20.0))
        factors = {"trend": trend * .45, "pullback": pullback * .30, "participation": participation * .15, "execution": execution * .10}
        rules = [{"rule": "trend_intact", "passed": trend > .08, "observed": trend, "required": .08}, {"rule": "pullback_not_breakdown", "passed": 35 <= rsi <= 60, "observed": rsi, "required": "35..60"}]
    elif family == "protected_momentum":
        volatility_quality = max(-1.0, min(1.0, 1 - atr * 20))
        factors = {"momentum": momentum * .35, "relative_strength": relative * .25, "trend": trend * .20, "volatility_quality": volatility_quality * .15, "execution": execution * .05}
        rules = [{"rule": "positive_momentum", "passed": momentum > .05, "observed": momentum, "required": .05}, {"rule": "volatility_protected", "passed": atr <= .08, "observed": atr, "required": .08}, {"rule": "no_chase", "passed": rsi <= 70, "observed": rsi, "required": 70}]
    elif family == "defensive_cash":
        risk_off = _v(row, "regime") < 0 or trend < -.05
        factors = {"defensive_regime": 1.0 if risk_off else -0.25, "trend": trend * .25}
        rules = [{"rule": "capital_preservation", "passed": not risk_off, "observed": "risk_off" if risk_off else "investable", "required": "investable"}]
    else:
        raise ValueError(f"unsupported strategy family: {family}")
    return FamilyEvaluation(float(max(-1.0, min(1.0, sum(factors.values())))), factors, rules)
