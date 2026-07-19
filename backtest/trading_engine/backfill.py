from __future__ import annotations

import hashlib
import time
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, Iterable, List

import pandas as pd
import requests

from .database import canonical_hash
from .features import aggregate_hourly


COINBASE_EXCHANGE_CANDLES = "https://api.exchange.coinbase.com/products/{product_id}/candles"


def fetch_hourly(product_id: str, start: datetime, end: datetime, session: requests.Session | None = None) -> pd.DataFrame:
    """Fetch public hourly bars in bounded chunks; Coinbase returns rows newest first."""
    client = session or requests.Session()
    rows: Dict[int, list[float]] = {}
    cursor = start.astimezone(timezone.utc)
    end = end.astimezone(timezone.utc)
    while cursor < end:
        chunk_end = min(end, cursor + timedelta(hours=299))
        response = client.get(
            COINBASE_EXCHANGE_CANDLES.format(product_id=product_id),
            params={"start": cursor.isoformat(), "end": chunk_end.isoformat(), "granularity": 3600},
            headers={"User-Agent": "laravel-crypto-research/0.1"}, timeout=30,
        )
        if response.status_code == 429:
            time.sleep(1.0)
            continue
        response.raise_for_status()
        for item in response.json():
            if len(item) >= 6:
                rows[int(item[0])] = [float(item[3]), float(item[2]), float(item[1]), float(item[4]), float(item[5])]
        cursor = chunk_end + timedelta(hours=1)
        time.sleep(0.08)
    if not rows:
        return pd.DataFrame(columns=["open", "high", "low", "close", "volume"])
    index = pd.to_datetime(sorted(rows), unit="s", utc=True)
    values = [rows[int(timestamp.timestamp())] for timestamp in index]
    return pd.DataFrame(values, index=index, columns=["open", "high", "low", "close", "volume"])


def store_candles(connection: Any, asset_id: int, symbol: str, timeframe: str, frame: pd.DataFrame, source: str = "coinbase_public") -> int:
    from psycopg.types.json import Jsonb

    interval = pd.Timedelta(hours=1 if timeframe == "1h" else (4 if timeframe == "4h" else 24))
    rows = []
    seen_at = datetime.now(timezone.utc)
    for timestamp, row in frame.iterrows():
        close_time = row.get("candle_close_time", timestamp + interval)
        available_at = row.get("available_at", close_time)
        final = bool(row.get("is_final", close_time <= pd.Timestamp.now(tz="UTC")))
        values = [float(row["open"]), float(row["high"]), float(row["low"]), float(row["close"]), float(row["volume"])]
        content_hash = hashlib.sha256("|".join(f"{value:.12f}" for value in values).encode()).hexdigest()
        rows.append((
            asset_id, symbol.upper(), timeframe, timestamp.to_pydatetime(), pd.Timestamp(close_time).to_pydatetime(),
            *values, float(row["volume"] * row["close"]), source, seen_at, seen_at,
            pd.Timestamp(available_at).to_pydatetime(), final, 1, content_hash, "valid", Jsonb({"public_backfill": True}), seen_at, seen_at,
        ))
    if not rows:
        return 0
    with connection.transaction():
        with connection.cursor() as cursor:
            cursor.executemany(
                """
                INSERT INTO market_candles
                    (asset_id, symbol, timeframe, candle_open_time, candle_close_time,
                     open, high, low, close, volume, turnover_usd, source, ingested_at,
                     first_seen_at, available_at, is_final, source_revision, content_hash,
                     quality_state, metadata_json, created_at, updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                ON CONFLICT (asset_id, timeframe, candle_open_time, source) DO NOTHING
                """,
                rows,
            )
            return cursor.rowcount


def execute_backfill(connection: Any, payload: Dict[str, Any]) -> Dict[str, Any]:
    start = datetime.fromisoformat(str(payload["start"]).replace("Z", "+00:00"))
    end = datetime.fromisoformat(str(payload["end"]).replace("Z", "+00:00"))
    hourly = fetch_hourly(str(payload["product_id"]), start, end)
    hourly["available_at"] = hourly.index + pd.Timedelta(hours=1)
    hourly["is_final"] = hourly.index + pd.Timedelta(hours=1) <= pd.Timestamp(end)
    counts = {"1h": store_candles(connection, int(payload["asset_id"]), str(payload["symbol"]), "1h", hourly)}
    for timeframe in payload.get("derive", ["4h", "1d"]):
        derived = aggregate_hourly(hourly, timeframe)
        counts[timeframe] = store_candles(connection, int(payload["asset_id"]), str(payload["symbol"]), timeframe, derived)
    manifest = {
        "source": "coinbase_public", "product_id": payload["product_id"], "start": start.isoformat(),
        "end": end.isoformat(), "rows_fetched": int(len(hourly)), "rows_inserted": counts,
    }
    manifest["content_hash"] = canonical_hash({"manifest": manifest, "last_rows": hourly.tail(10).to_dict(orient="split")})
    return {"manifest": manifest, "counts": counts}
