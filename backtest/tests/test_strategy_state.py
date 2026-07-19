from datetime import datetime, timezone
import pytest
from trading_engine.strategy_definition import StrategyDefinition, canonical_hash, default_definition
from trading_engine.strategy_state import PositionState, StrategyState


def test_definition_rejects_unknown_family_and_hash_mismatch():
    with pytest.raises(ValueError, match="unsupported strategy family"):
        StrategyDefinition.from_mapping({"schema_version": "2.0", "version": "x", "family": "mystery", "parameters": {}})
    definition = default_definition()
    with pytest.raises(ValueError, match="hash mismatch"):
        StrategyDefinition.from_mapping({"schema_version": "2.0", "version": "x", "family": "trend_rotation", "parameters": definition.parameters, "parameter_hash": "0" * 64})


def test_entry_requires_confirmation_and_exit_enters_cooldown():
    now = datetime.now(timezone.utc)
    state = StrategyState().advance(True, False, now, entry_bars=2)
    assert state.state is PositionState.ENTRY_CONFIRMING
    state = state.advance(True, False, now, entry_bars=2)
    assert state.state is PositionState.OPEN
    state = state.advance(False, True, now, exit_bars=1)
    assert state.state is PositionState.EXIT_CONFIRMING
    state = state.advance(False, True, now, exit_bars=1, cooldown_bars=2)
    assert state.state is PositionState.COOLDOWN
