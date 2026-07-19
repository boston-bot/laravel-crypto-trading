from datetime import datetime, timezone
from trading_engine.market_evidence import canonical_versions, membership_active, terminal_market_event


def row(available, close=100):
    return {"candle_open_time": "2026-01-01T00:00:00Z", "available_at": available, "first_seen_at": available, "open": 100, "high": 110, "low": 90, "close": close, "volume": 1, "is_final": True, "quality_state": "valid"}


def test_late_revision_does_not_leak_and_invalid_numeric_version_is_skipped():
    cutoff = datetime(2026,1,1,2,tzinfo=timezone.utc)
    selected = canonical_versions([row("2026-01-01T01:00:00Z", 101), row("2026-01-02T00:00:00Z", 105), row("2026-01-01T01:30:00Z", float("nan"))], cutoff)
    assert selected[0]["close"] == 101


def test_membership_bounds_and_terminal_exit_are_explicit():
    membership = {"valid_from": "2026-01-02T00:00:00Z", "valid_to": "2026-02-01T00:00:00Z", "trading_state": "online"}
    assert not membership_active(membership, datetime(2026,1,1,tzinfo=timezone.utc))
    assert membership_active(membership, datetime(2026,1,3,tzinfo=timezone.utc))
    assert terminal_market_event({"trading_state": "delisted"}, None)["action"] == "incomplete"
    assert terminal_market_event({"trading_state": "halted"}, 95)["action"] == "forced_exit"
