from __future__ import annotations

from datetime import datetime, timezone
import math
from typing import Any, Iterable


def _utc(value: Any) -> datetime:
    result = value if isinstance(value, datetime) else datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    return result.replace(tzinfo=timezone.utc) if result.tzinfo is None else result.astimezone(timezone.utc)


def valid_candle(row: dict[str, Any]) -> bool:
    try:
        values = [float(row[key]) for key in ("open", "high", "low", "close", "volume")]
    except (KeyError, TypeError, ValueError):
        return False
    if not all(math.isfinite(value) for value in values):
        return False
    open_, high, low, close, volume = values
    return bool(row.get("is_final", True)) and row.get("quality_state", "valid") in {"valid", "verified"} and high >= max(open_, close, low) and low <= min(open_, close, high) and volume >= 0


def canonical_versions(rows: Iterable[dict[str, Any]], cutoff: datetime) -> list[dict[str, Any]]:
    at = _utc(cutoff); chosen: dict[Any, dict[str, Any]] = {}
    for row in rows:
        if _utc(row["available_at"]) > at or _utc(row.get("first_seen_at", row["available_at"])) > at or not valid_candle(row):
            continue
        key = row["candle_open_time"]
        if key not in chosen or _utc(row["available_at"]) > _utc(chosen[key]["available_at"]):
            chosen[key] = dict(row)
    return [chosen[key] for key in sorted(chosen, key=_utc)]


def membership_active(membership: dict[str, Any], at: datetime) -> bool:
    instant = _utc(at)
    listed = _utc(membership.get("listed_at", membership["valid_from"]))
    end_value = membership.get("delisted_at") or membership.get("valid_to")
    return listed <= instant and (end_value is None or instant < _utc(end_value)) and membership.get("trading_state", "online") in {"online", "limit_only"}


def terminal_market_event(membership: dict[str, Any], last_executable_price: float | None) -> dict[str, Any]:
    if membership.get("trading_state") not in {"halted", "delisted", "offline"}:
        return {"action": "none"}
    if last_executable_price is None or not math.isfinite(float(last_executable_price)) or last_executable_price <= 0:
        return {"action": "incomplete", "reason": "terminal_event_without_executable_exit_price"}
    return {"action": "forced_exit", "price": float(last_executable_price), "reason": membership.get("trading_state")}
