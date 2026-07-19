from __future__ import annotations

import json
import logging
import os
import socket
import time
import threading
from datetime import datetime, timezone
from typing import Any, Dict

import pandas as pd

from . import ENGINE_VERSION
from .database import EngineRepository, canonical_hash, connect
from .features import compute_features, score_latest
from .backfill import execute_backfill
from .backtest_runner import execute_backtest
from .sentiment import refresh_sentiment

LOGGER = logging.getLogger(__name__)


def _heartbeat_loop(database_url: str, worker_id: str, job_id: str, lease_seconds: int, stopped: threading.Event) -> None:
    interval = max(5.0, lease_seconds / 3)
    try:
        with connect(database_url) as heartbeat_connection:
            heartbeat_repository = EngineRepository(heartbeat_connection, worker_id, lease_seconds)
            while not stopped.wait(interval):
                if not heartbeat_repository.heartbeat(job_id):
                    LOGGER.warning("Lost lease for engine job %s", job_id)
                    return
    except Exception:
        LOGGER.exception("Heartbeat loop failed for engine job %s", job_id)


def evaluate_job(repository: EngineRepository, job: Dict[str, Any]) -> Dict[str, Any]:
    payload = job["payload_json"] if isinstance(job["payload_json"], dict) else json.loads(job["payload_json"])
    evidence_cutoff_value = payload.get("evidence_cutoff")
    evidence_cutoff = (
        datetime.fromisoformat(str(evidence_cutoff_value).replace("Z", "+00:00"))
        if evidence_cutoff_value else job["as_of"]
    )
    proposals = []
    for asset in payload.get("assets", []):
        rows = repository.candles(int(asset["id"]), job["as_of"], "4h", evidence_cutoff)
        warnings = []
        if not rows:
            proposals.append({
                "asset_id": int(asset["id"]), "asset": asset["symbol"], "action": "HOLD", "score": 0,
                "calibrated_probability": 0.5, "expected_value_bps": None, "factor_attribution": {},
                "warnings": ["missing_point_in_time_candles"],
            })
            continue
        frame = pd.DataFrame(rows)
        features = compute_features(frame)
        scored = score_latest(features)
        reference_price = float(frame.iloc[-1]["close"])
        probability = min(0.99, max(0.01, 0.5 + float(scored["score"]) * 0.35))
        warnings.extend(scored["warnings"])
        warnings.append("uncalibrated_until_fold_training")
        proposals.append({
            "asset_id": int(asset["id"]), "asset": asset["symbol"], "action": scored["action"],
            "score": scored["score"], "calibrated_probability": probability, "expected_value_bps": None,
            "factor_attribution": scored["factors"], "warnings": warnings,
            "signal": {
                "decision": scored["action"], "side": "buy" if scored["action"] == "ENTER" else ("sell" if scored["action"] == "EXIT" else None),
                "score": scored["score"], "confidence": probability,
                "signal_context": {"scoring": {"probability": probability, "components": scored["factors"]}},
                "market_context": {"as_of": str(job["as_of"]), "point_in_time": True, "reference_price": reference_price},
            },
        })
    return {"proposals": proposals, "diagnostics": {"engine": ENGINE_VERSION, "point_in_time": True}}


def run_worker(database_url: str, once: bool = False, poll_seconds: float = 2.0) -> int:
    worker_id = os.getenv("ENGINE_WORKER_ID", f"{socket.gethostname()}:{os.getpid()}")
    with connect(database_url) as connection:
        lease_seconds = int(os.getenv("ENGINE_LEASE_SECONDS", "300"))
        repository = EngineRepository(connection, worker_id, lease_seconds)
        while True:
            job = repository.claim()
            if job is None:
                if once:
                    return 0
                time.sleep(poll_seconds)
                continue
            stopped = threading.Event()
            heartbeat = threading.Thread(
                target=_heartbeat_loop,
                args=(database_url, worker_id, str(job["id"]), lease_seconds, stopped),
                daemon=True,
            )
            heartbeat.start()
            try:
                if job["kind"] == "evaluate":
                    result = evaluate_job(repository, job)
                elif job["kind"] == "backfill":
                    payload = job["payload_json"] if isinstance(job["payload_json"], dict) else json.loads(job["payload_json"])
                    result = execute_backfill(connection, payload)
                elif job["kind"] == "backtest":
                    payload = job["payload_json"] if isinstance(job["payload_json"], dict) else json.loads(job["payload_json"])
                    result = execute_backtest(connection, payload)
                elif job["kind"] == "sentiment_refresh":
                    payload = job["payload_json"] if isinstance(job["payload_json"], dict) else json.loads(job["payload_json"])
                    result = refresh_sentiment(connection, payload.get("url", "https://api.alternative.me/fng/"), int(payload.get("limit", 90)))
                else:
                    raise NotImplementedError(f"Unsupported engine job kind: {job['kind']}")
                repository.complete(job, result, canonical_hash({"job": job["id"], "as_of": job["as_of"], "payload": job["payload_json"]}), ENGINE_VERSION)
            except Exception as exc:  # worker boundary deliberately records bounded failures
                LOGGER.exception("Engine job %s failed", job["id"])
                repository.fail(job["id"], str(exc))
            finally:
                stopped.set()
                heartbeat.join(timeout=2)
            if once:
                return 0
