from __future__ import annotations

import hashlib
import json
import math
from pathlib import Path
from typing import Any


FIXTURES = Path(__file__).resolve().parents[2] / "contracts" / "fixtures"
HASH_FIELDS = {
    "portfolio-context-paper-v1.json": "context_hash",
    "portfolio-target-v1.json": "target_hash",
    "order-intent-v1.json": "intent_hash",
}


def canonical_hash(payload: dict[str, Any], hash_field: str) -> str:
    content = {key: value for key, value in payload.items() if key != hash_field}
    canonical = json.dumps(
        content,
        allow_nan=False,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    )

    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def assert_finite_numbers(value: Any) -> None:
    if isinstance(value, bool) or value is None:
        return
    if isinstance(value, (int, float)):
        assert math.isfinite(value)
        return
    if isinstance(value, dict):
        for child in value.values():
            assert_finite_numbers(child)
        return
    if isinstance(value, list):
        for child in value:
            assert_finite_numbers(child)


def test_shared_contract_fixtures_have_deterministic_hashes_and_finite_numbers() -> None:
    for filename, hash_field in HASH_FIELDS.items():
        payload = json.loads((FIXTURES / filename).read_text(encoding="utf-8"))

        assert payload["schema_version"] == "1.0"
        assert payload[hash_field] == canonical_hash(payload, hash_field)
        assert canonical_hash(payload, hash_field) == canonical_hash(payload, hash_field)
        assert_finite_numbers(payload)


def test_paper_context_is_funded_and_order_intent_is_coinbase_normalized() -> None:
    context = json.loads(
        (FIXTURES / "portfolio-context-paper-v1.json").read_text(encoding="utf-8")
    )
    target = json.loads(
        (FIXTURES / "portfolio-target-v1.json").read_text(encoding="utf-8")
    )
    intent = json.loads(
        (FIXTURES / "order-intent-v1.json").read_text(encoding="utf-8")
    )

    assert context["mode"] == "paper"
    assert context["cash"] > 0
    assert context["equity"] > 0
    assert target["cash_weight"] + sum(
        position["target_weight"] for position in target["positions"]
    ) == 1.0
    assert intent["venue"] == "coinbase"
    assert intent["normalized_base_quantity"] > 0
    assert intent["idempotency_key"]
