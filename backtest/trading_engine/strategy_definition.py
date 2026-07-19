from __future__ import annotations

from dataclasses import dataclass
import hashlib
import json
import math
from typing import Any, Mapping


SUPPORTED_SCHEMA_VERSIONS = {"2.0"}
SUPPORTED_FAMILIES = {"trend_rotation", "pullback_in_trend", "protected_momentum", "defensive_cash"}


def _canonicalize(value: Any) -> Any:
    if isinstance(value, float) and value.is_integer():
        return int(value)
    if isinstance(value, dict):
        return {key: _canonicalize(child) for key, child in value.items()}
    if isinstance(value, list):
        return [_canonicalize(child) for child in value]
    return value


def canonical_hash(value: Any) -> str:
    return hashlib.sha256(json.dumps(_canonicalize(value), allow_nan=False, separators=(",", ":"), sort_keys=True).encode()).hexdigest()


def _assert_finite(value: Any, path: str = "definition") -> None:
    if isinstance(value, bool) or value is None:
        return
    if isinstance(value, (int, float)):
        if not math.isfinite(float(value)):
            raise ValueError(f"{path} contains a non-finite number")
    elif isinstance(value, Mapping):
        for key, child in value.items():
            _assert_finite(child, f"{path}.{key}")
    elif isinstance(value, (list, tuple)):
        for index, child in enumerate(value):
            _assert_finite(child, f"{path}[{index}]")


@dataclass(frozen=True)
class StrategyDefinition:
    schema_version: str
    version: str
    family: str
    parameters: dict[str, Any]
    parameter_hash: str

    @classmethod
    def from_mapping(cls, value: Mapping[str, Any]) -> "StrategyDefinition":
        required = {"schema_version", "version", "family", "parameters"}
        missing = sorted(required.difference(value))
        if missing:
            raise ValueError("strategy definition missing: " + ", ".join(missing))
        schema = str(value["schema_version"])
        family = str(value["family"])
        if schema not in SUPPORTED_SCHEMA_VERSIONS:
            raise ValueError(f"unsupported strategy schema: {schema}")
        if family not in SUPPORTED_FAMILIES:
            raise ValueError(f"unsupported strategy family: {family}")
        parameters = dict(value["parameters"])
        _assert_finite(parameters)
        required_parameters = {"entry_score", "exit_score", "minimum_net_edge_bps", "cost_estimate_bps"}
        missing_parameters = sorted(required_parameters.difference(parameters))
        if missing_parameters:
            raise ValueError("strategy parameters missing: " + ", ".join(missing_parameters))
        calculated = canonical_hash(parameters)
        declared = str(value.get("parameter_hash", calculated))
        if declared != calculated:
            raise ValueError("strategy parameter hash mismatch")
        return cls(schema, str(value["version"]), family, parameters, calculated)


def default_definition(family: str = "trend_rotation") -> StrategyDefinition:
    parameters = {
        "entry_score": 0.25,
        "exit_score": -0.10,
        "minimum_net_edge_bps": 12.0,
        "cost_estimate_bps": 8.0,
        "max_positions": 3,
        "max_position_weight": 0.40,
        "max_gross_weight": 1.0,
        "entry_confirmation_bars": 2,
        "exit_confirmation_bars": 1,
        "cooldown_bars": 2,
        "minimum_hold_days": 2,
        "maximum_hold_days": 21,
    }
    return StrategyDefinition.from_mapping({"schema_version": "2.0", "version": "2.0.0", "family": family, "parameters": parameters})
