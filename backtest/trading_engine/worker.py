from __future__ import annotations

import json
import logging
import os
import socket
import time
import threading
from datetime import datetime, timezone
from typing import Any, Dict

from . import ENGINE_VERSION
from .database import EngineRepository, canonical_hash, connect
from .features import frozen_multi_horizon_features
from .evaluator import PortfolioEvaluator
from .strategy_definition import StrategyDefinition
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
    raw_definition = payload.get("strategy_definition")
    if raw_definition is None and job.get("strategy_version_id"):
        raw_definition = repository.strategy_definition(int(job["strategy_version_id"]))
    if raw_definition is None:
        raise ValueError("canonical evaluation requires a pinned strategy definition")
    definition = StrategyDefinition.from_mapping(raw_definition)
    feature_frames = {}
    for asset in payload.get("assets", []):
        frames = {}
        for timeframe in ("1h", "4h", "1d"):
            rows = repository.candles(int(asset["id"]), job["as_of"], timeframe, evidence_cutoff)
            if rows:
                import pandas as pd
                frames[timeframe] = pd.DataFrame(rows)
        feature_frames[str(asset["symbol"])] = frozen_multi_horizon_features(frames, evidence_cutoff)
    evidence_hash = canonical_hash({"cutoff": evidence_cutoff, "assets": payload.get("assets", []), "manifest": payload.get("market_evidence_manifest_hash")})
    result = PortfolioEvaluator(definition).evaluate(feature_frames, list(payload.get("assets", [])), job["as_of"], dict(payload.get("portfolio_context", {})), evidence_hash)
    result["diagnostics"] |= {"engine": ENGINE_VERSION, "universe_version_id": job.get("universe_version_id"), "evidence_cutoff": str(evidence_cutoff)}
    return result


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
