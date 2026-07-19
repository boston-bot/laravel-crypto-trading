from __future__ import annotations

import hashlib
import json
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, Iterator, Optional


CLAIM_SQL = """
WITH candidate AS (
    SELECT id
    FROM engine_jobs
    WHERE status = 'pending'
      AND attempts < max_attempts
      AND (valid_until IS NULL OR valid_until > now())
    ORDER BY created_at
    FOR UPDATE SKIP LOCKED
    LIMIT 1
)
UPDATE engine_jobs AS job
SET status = 'leased', attempts = attempts + 1, lease_owner = %(worker)s,
    lease_expires_at = now() + (%(lease_seconds)s || ' seconds')::interval,
    heartbeat_at = now(), updated_at = now()
FROM candidate
WHERE job.id = candidate.id
RETURNING job.*
"""


class EngineRepository:
    def __init__(self, connection: Any, worker_id: str, lease_seconds: int = 300):
        self.connection = connection
        self.worker_id = worker_id
        self.lease_seconds = lease_seconds

    def claim(self) -> Optional[Dict[str, Any]]:
        """Claim inside a short transaction; computation happens after commit."""
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(CLAIM_SQL, {"worker": self.worker_id, "lease_seconds": self.lease_seconds})
                row = cursor.fetchone()
                if row is None:
                    return None
                columns = [item.name for item in cursor.description]
                return dict(zip(columns, row))

    def heartbeat(self, job_id: str) -> bool:
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    "UPDATE engine_jobs SET heartbeat_at=now(), lease_expires_at=now() + (%s || ' seconds')::interval WHERE id=%s AND status='leased' AND lease_owner=%s",
                    (self.lease_seconds, job_id, self.worker_id),
                )
                return cursor.rowcount == 1

    def assert_development_window_allowed(self, start: Any, end: Any) -> None:
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT 1 FROM holdout_intervals
                WHERE holdout_start < %s AND holdout_end > %s
                LIMIT 1
                """,
                (end, start),
            )
            if cursor.fetchone() is not None:
                raise PermissionError("development jobs cannot query a locked or revealed holdout interval")

    def record_holdout_access(self, job: Dict[str, Any], payload: Dict[str, Any]) -> None:
        interval_id = int(payload["holdout_interval_id"])
        lineage = dict(payload.get("lineage", {}))
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """
                    SELECT id, status, engine_job_id, strategy_experiment_id,
                           authorized_strategy_version_id, research_manifest_id,
                           candidate_hash, manifest_hash, engine_version, code_hash,
                           authorized_by, purpose, authorization_idempotency_key, revealed_at
                    FROM holdout_intervals
                    WHERE id=%s
                    FOR UPDATE
                    """,
                    (interval_id,),
                )
                row = cursor.fetchone()
                if row is None:
                    raise PermissionError("unknown holdout authorization")
                (
                    _, status, engine_job_id, experiment_id, strategy_version_id,
                    manifest_id, candidate_hash, manifest_hash, engine_version,
                    code_hash, actor, purpose, authorization_key, revealed_at,
                ) = row
                if str(engine_job_id) != str(job["id"]):
                    raise PermissionError("worker job does not match the authorized holdout job")
                expected = {
                    "strategy_hash": candidate_hash,
                    "manifest_hash": manifest_hash,
                    "engine_version": engine_version,
                    "code_hash": code_hash,
                }
                if any(str(lineage.get(key, "")) != str(value) for key, value in expected.items()):
                    raise PermissionError("holdout lineage does not match the authorization")
                if status == "authorized":
                    cursor.execute(
                        "UPDATE holdout_intervals SET status='running', revealed_at=now(), updated_at=now() WHERE id=%s",
                        (interval_id,),
                    )
                elif status != "running" or revealed_at is None:
                    raise PermissionError("holdout interval is not authorized for access")
                event_key = canonical_hash(["access", authorization_key, str(job["id"])])
                cursor.execute(
                    """
                    INSERT INTO holdout_access_events
                        (holdout_interval_id,event_type,strategy_experiment_id,strategy_version_id,
                         research_manifest_id,engine_job_id,candidate_hash,manifest_hash,engine_version,
                         code_hash,actor,purpose,idempotency_key,details_json,occurred_at,created_at,updated_at)
                    VALUES (%s,'accessed',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'{}',now(),now(),now())
                    ON CONFLICT (holdout_interval_id,event_type,idempotency_key) DO NOTHING
                    """,
                    (
                        interval_id, experiment_id, strategy_version_id, manifest_id, job["id"],
                        candidate_hash, manifest_hash, engine_version, code_hash, actor, purpose, event_key,
                    ),
                )

    def complete(self, job: Dict[str, Any], payload: Dict[str, Any], manifest_hash: str, engine_version: str) -> None:
        from psycopg.types.json import Jsonb

        job_payload = job["payload_json"] if isinstance(job["payload_json"], dict) else json.loads(job["payload_json"])
        valid_until = job["valid_until"]
        if job["kind"] == "evaluate":
            now_utc = datetime.now(timezone.utc)
            production_deadline = now_utc + timedelta(minutes=int(job_payload.get("proposal_ttl_minutes", 30)))
            logical_value = job_payload.get("logical_bar_close") or job["as_of"]
            logical_close = (
                datetime.fromisoformat(str(logical_value).replace("Z", "+00:00"))
                if not isinstance(logical_value, datetime) else logical_value
            )
            if logical_close.tzinfo is None:
                logical_close = logical_close.replace(tzinfo=timezone.utc)
            logical_deadline = logical_close + timedelta(minutes=int(job_payload.get("max_signal_age_minutes", 240)))
            valid_until = min(production_deadline, logical_deadline)

        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT INTO engine_results
                        (engine_job_id, result_kind, engine_version, schema_version, as_of, valid_until, manifest_hash, payload_json, created_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, now(), now())
                    ON CONFLICT (engine_job_id) DO NOTHING
                    """,
                    (job["id"], "evaluation" if job["kind"] == "evaluate" else job["kind"], engine_version, job["schema_version"], job["as_of"], valid_until, manifest_hash, Jsonb(payload)),
                )
                cursor.execute(
                    "UPDATE engine_jobs SET status='succeeded', completed_at=now(), lease_owner=NULL, lease_expires_at=NULL, updated_at=now() WHERE id=%s AND lease_owner=%s",
                    (job["id"], self.worker_id),
                )

    def fail(self, job_id: str, error: str) -> None:
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """
                    UPDATE engine_jobs SET
                        status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END,
                        last_error=%s, lease_owner=NULL, lease_expires_at=NULL, heartbeat_at=NULL,
                        completed_at=CASE WHEN attempts >= max_attempts THEN now() ELSE NULL END, updated_at=now()
                    WHERE id=%s AND lease_owner=%s
                    """,
                    (error[:8000], job_id, self.worker_id),
                )

    def candles(
        self,
        asset_id: int,
        logical_bar_close: datetime,
        timeframe: str = "4h",
        evidence_cutoff: datetime | None = None,
    ) -> list[Dict[str, Any]]:
        evidence_cutoff = evidence_cutoff or logical_bar_close
        with self.connection.cursor() as cursor:
            cursor.execute(
                """
                WITH versions AS (
                    SELECT c.id AS candle_id, c.candle_open_time, c.candle_close_time,
                           c.open, c.high, c.low, c.close, c.volume, c.available_at,
                           c.first_seen_at, c.is_final, c.quality_state
                    FROM market_candles c
                    WHERE c.asset_id=%s AND c.timeframe=%s AND c.source='coinbase'
                      AND c.metadata_json->>'derived_from'='1h'
                    UNION ALL
                    SELECT c.id AS candle_id, c.candle_open_time, c.candle_close_time,
                           (r.values_json->>'open')::numeric, (r.values_json->>'high')::numeric,
                           (r.values_json->>'low')::numeric, (r.values_json->>'close')::numeric,
                           (r.values_json->>'volume')::numeric, r.available_at, r.first_seen_at,
                           COALESCE((r.values_json->>'is_final')::boolean, true),
                           COALESCE(r.values_json->>'quality_state', 'valid')
                    FROM market_candle_revisions r
                    JOIN market_candles c ON c.id=r.market_candle_id
                    WHERE c.asset_id=%s AND c.timeframe=%s AND c.source='coinbase'
                      AND c.metadata_json->>'derived_from'='1h'
                )
                SELECT candle_id AS observation_id, candle_open_time, candle_close_time, open, high, low, close, volume,
                       available_at, first_seen_at, is_final, quality_state
                FROM (
                    SELECT DISTINCT ON (candle_open_time) *
                    FROM versions
                    WHERE is_final=true AND quality_state IN ('valid', 'verified')
                      AND available_at <= %s AND first_seen_at <= %s
                      AND candle_close_time <= %s
                    ORDER BY candle_open_time, available_at DESC
                ) point_in_time
                ORDER BY candle_open_time
                """,
                (
                    asset_id, timeframe, asset_id, timeframe,
                    evidence_cutoff, evidence_cutoff, logical_bar_close,
                ),
            )
            columns = [item.name for item in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def strategy_definition(self, strategy_version_id: int) -> Dict[str, Any]:
        with self.connection.cursor() as cursor:
            cursor.execute("SELECT version, schema_version, content_hash, definition_json FROM strategy_versions WHERE id=%s", (strategy_version_id,))
            row = cursor.fetchone()
            if row is None:
                raise ValueError(f"unknown strategy version: {strategy_version_id}")
            value = row[3] if isinstance(row[3], dict) else json.loads(row[3])
            return dict(value) | {"version": str(row[0]), "schema_version": str(row[1]), "content_hash": str(row[2])}


def canonical_hash(payload: Any) -> str:
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":"), default=str).encode()
    return hashlib.sha256(encoded).hexdigest()


@contextmanager
def connect(database_url: str) -> Iterator[Any]:
    try:
        import psycopg
    except ImportError as exc:
        raise RuntimeError("Install the package with its psycopg dependency to run the database worker") from exc
    connection = psycopg.connect(database_url)
    try:
        with connection.cursor() as cursor:
            cursor.execute("SET TIME ZONE 'UTC'")
        connection.commit()
        yield connection
    finally:
        connection.close()
