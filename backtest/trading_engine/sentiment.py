from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Dict, Iterable, List, Optional

import numpy as np
import requests


def sentiment_features(values_newest_first: Iterable[float], current: float) -> Dict[str, Optional[float]]:
    history = np.asarray(list(values_newest_first), dtype=float)

    def change(days: int) -> Optional[float]:
        return float(current - history[days - 1]) if history.size >= days else None

    def zscore(days: int) -> Optional[float]:
        window = history[:days]
        if window.size < 10:
            return None
        deviation = float(window.std(ddof=0))
        return float((current - window.mean()) / deviation) if deviation > 0 else 0.0

    return {
        "normalized_score": float((current - 50) / 50),
        "change_1d": change(1), "change_7d": change(7),
        "zscore_30d": zscore(30), "zscore_90d": zscore(90),
    }


def refresh_sentiment(connection: Any, url: str = "https://api.alternative.me/fng/", limit: int = 90) -> Dict[str, Any]:
    from psycopg.types.json import Jsonb

    response = requests.get(url, params={"limit": limit, "format": "json"}, timeout=20)
    response.raise_for_status()
    rows: List[Dict[str, Any]] = sorted(response.json().get("data", []), key=lambda row: int(row["timestamp"]))
    stored = 0
    for row in rows:
        published = datetime.fromtimestamp(int(row["timestamp"]), tz=timezone.utc)
        value = max(0, min(100, int(row["value"])))
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT raw_value FROM sentiment_observations WHERE source='alternative_me' AND published_at < %s ORDER BY published_at DESC LIMIT 90",
                (published,),
            )
            history = [float(item[0]) for item in cursor.fetchall()]
        features = sentiment_features(history, value)
        with connection.transaction():
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT INTO sentiment_observations
                        (source,published_at,first_seen_at,raw_value,normalized_score,change_1d,change_7d,
                         zscore_30d,zscore_90d,classification,raw_json,created_at,updated_at)
                    VALUES ('alternative_me',%s,now(),%s,%s,%s,%s,%s,%s,%s,%s,now(),now())
                    ON CONFLICT (source,published_at) DO UPDATE SET
                        raw_value=EXCLUDED.raw_value,normalized_score=EXCLUDED.normalized_score,
                        change_1d=EXCLUDED.change_1d,change_7d=EXCLUDED.change_7d,
                        zscore_30d=EXCLUDED.zscore_30d,zscore_90d=EXCLUDED.zscore_90d,
                        classification=EXCLUDED.classification,raw_json=EXCLUDED.raw_json,updated_at=now()
                    """,
                    (published, value, features["normalized_score"], features["change_1d"], features["change_7d"],
                     features["zscore_30d"], features["zscore_90d"], row.get("value_classification"),
                     Jsonb({**row, "attribution": "Alternative.me Crypto Fear & Greed Index"})),
                )
        stored += 1
    return {"source": "alternative_me", "stored": stored, "attribution": "Alternative.me Crypto Fear & Greed Index", "max_forward_fill_hours": 36}
