from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import datetime
from typing import Any, Dict, List, Optional


@dataclass(frozen=True)
class Proposal:
    asset_id: int
    asset: str
    action: str
    score: float
    calibrated_probability: float
    expected_value_bps: Optional[float]
    factor_attribution: Dict[str, float]
    warnings: List[str] = field(default_factory=list)
    signal: Dict[str, Any] = field(default_factory=dict)
    resolution: str = "hold"
    decision_trace: Dict[str, Any] = field(default_factory=dict)
    portfolio_target: Dict[str, Any] = field(default_factory=dict)
    order_intent: Optional[Dict[str, Any]] = None


@dataclass(frozen=True)
class EvaluationResult:
    engine_version: str
    schema_version: str
    job_id: str
    as_of: datetime
    valid_until: Optional[datetime]
    manifest_hash: str
    proposals: List[Proposal]
    diagnostics: Dict[str, Any] = field(default_factory=dict)
    strategy_family: Optional[str] = None
    strategy_definition_version: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        value = asdict(self)
        value["as_of"] = self.as_of.isoformat()
        value["valid_until"] = self.valid_until.isoformat() if self.valid_until else None
        return value


@dataclass(frozen=True)
class BacktestSpec:
    strategy_version: str
    universe_version: str
    start: datetime
    end: datetime
    initial_capital: float = 100_000.0
    random_seed: int = 7
    train_months: int = 24
    validation_months: int = 6
    test_months: int = 6
    step_months: int = 6
    embargo_days: int = 30
    holdout_months: int = 12
    fee_bps: float = 60.0
    spread_bps: float = 10.0
    slippage_bps: float = 8.0


@dataclass(frozen=True)
class Fold:
    fold: int
    train_start: datetime
    train_end: datetime
    validation_start: datetime
    validation_end: datetime
    test_start: datetime
    test_end: datetime


@dataclass(frozen=True)
class Fill:
    timestamp: datetime
    asset: str
    side: str
    requested_quantity: float
    filled_quantity: float
    reference_price: float
    fill_price: float
    fee: float
    slippage_bps: float
    status: str
    reason: Optional[str] = None
    observation_id: Optional[str] = None
    observation_time: Optional[datetime] = None


@dataclass(frozen=True)
class ClosedTrade:
    asset: str
    entry_time: datetime
    exit_time: datetime
    quantity: float
    entry_price: float
    exit_price: float
    fees: float
    pnl: float
    return_pct: float
    hold_hours: float


@dataclass(frozen=True)
class BacktestResult:
    engine_version: str
    schema_version: str
    job_id: str
    manifest_hash: str
    fold_metrics: List[Dict[str, Any]]
    trades: List[Dict[str, Any]]
    fills: List[Dict[str, Any]]
    equity: List[Dict[str, Any]]
    calibration: Optional[Dict[str, Any]]
    benchmarks: Dict[str, Any]
    cost_attribution: Dict[str, Any]
    gate: Dict[str, bool]
