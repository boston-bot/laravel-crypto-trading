from __future__ import annotations

import asyncio
import json
import logging
import os
import zlib
from dataclasses import asdict
from datetime import datetime, timezone
from typing import Any, Dict, Iterable, Optional

import websockets

from .books import BookSummary, L2Book, SequenceGap
from .spreads import evaluate_spread

LOGGER = logging.getLogger(__name__)
NOTIONALS = (50, 100, 250, 500)


class MarketEventSink:
    def __init__(self, connection: Any):
        self.connection = connection
        self.latest: Dict[tuple[str, str], BookSummary] = {}

    def raw(self, venue: str, product: str, payload: Dict[str, Any], received: datetime) -> None:
        from psycopg.types.json import Jsonb
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """INSERT INTO raw_market_events
                    (venue, product_id, channel, event_type, sequence, event_time, received_at, payload_json, created_at, updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,now(),now())""",
                    (venue, product, str(payload.get("channel", "book")), str(payload.get("type", "update")), payload.get("sequence_num"), _event_time(payload, received), received, Jsonb(payload)),
                )

    def summary(self, value: BookSummary) -> None:
        from psycopg.types.json import Jsonb
        bucket = value.received_at.replace(microsecond=0)
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT INTO order_book_summaries
                        (venue, product_id, bucket_time, event_time, received_at, sequence, best_bid, best_ask,
                         spread_bps, book_age_ms, is_valid, invalid_reason, depth_json, created_at, updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,now(),now())
                    ON CONFLICT (venue, product_id, bucket_time) DO UPDATE SET
                        event_time=EXCLUDED.event_time, received_at=EXCLUDED.received_at, sequence=EXCLUDED.sequence,
                        best_bid=EXCLUDED.best_bid, best_ask=EXCLUDED.best_ask, spread_bps=EXCLUDED.spread_bps,
                        book_age_ms=EXCLUDED.book_age_ms, is_valid=EXCLUDED.is_valid,
                        invalid_reason=EXCLUDED.invalid_reason, depth_json=EXCLUDED.depth_json, updated_at=now()
                    """,
                    (value.venue, value.product_id, bucket, value.event_time, value.received_at, value.sequence,
                     value.best_bid, value.best_ask, value.spread_bps, value.book_age_ms, value.is_valid,
                     value.invalid_reason, Jsonb(value.depth)),
                )
        self.latest[(value.venue, value.product_id)] = value
        self._spreads(value.product_id)

    def checkpoint(self, venue: str, product: str, book: L2Book, status: str = "healthy") -> None:
        from psycopg.types.json import Jsonb
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT INTO ingestion_checkpoints
                        (source, stream, partition_key, last_sequence, event_time, received_at, status, metadata_json, created_at, updated_at)
                    VALUES (%s,'level2',%s,%s,%s,%s,%s,%s,now(),now())
                    ON CONFLICT (source, stream, partition_key) DO UPDATE SET
                        last_sequence=EXCLUDED.last_sequence, event_time=EXCLUDED.event_time,
                        received_at=EXCLUDED.received_at, status=EXCLUDED.status,
                        metadata_json=EXCLUDED.metadata_json, updated_at=now()
                    """,
                    (venue, product, book.sequence, book.event_time, book.received_at, status, Jsonb({"valid": book.valid, "invalid_reason": book.invalid_reason})),
                )

    def incident(self, venue: str, product: str, kind: str, message: str) -> None:
        from psycopg.types.json import Jsonb
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """INSERT INTO data_quality_incidents
                    (source, stream, severity, incident_type, started_at, message, context_json, created_at, updated_at)
                    VALUES (%s,'level2','warning',%s,now(),%s,%s,now(),now())""",
                    (venue, kind, message, Jsonb({"product_id": product})),
                )

    def _spreads(self, product: str) -> None:
        coinbase = self.latest.get(("coinbase", product))
        kraken = self.latest.get(("kraken", product))
        if not coinbase or not kraken:
            return
        fees = {venue: self._fee(venue) for venue in ("coinbase", "kraken")}
        for notional in NOTIONALS:
            for buy, sell in ((coinbase, kraken), (kraken, coinbase)):
                observation = evaluate_spread(buy, sell, notional, fees[buy.venue], fees[sell.venue])
                self._store_spread(observation, buy, sell)

    def _fee(self, venue: str) -> Optional[float]:
        with self.connection.cursor() as cursor:
            cursor.execute(
                """SELECT taker_fee_bps FROM fee_schedule_snapshots
                WHERE venue=%s AND effective_at<=now() AND (expires_at IS NULL OR expires_at>now())
                ORDER BY effective_at DESC LIMIT 1""", (venue,),
            )
            row = cursor.fetchone()
            return float(row[0]) if row else None

    def _store_spread(self, observation: Any, buy: BookSummary, sell: BookSummary) -> None:
        from psycopg.types.json import Jsonb
        with self.connection.transaction():
            with self.connection.cursor() as cursor:
                cursor.execute(
                    """
                    INSERT INTO spread_observations
                        (product_id,buy_venue,sell_venue,notional_usd,observed_at,buy_book_age_ms,sell_book_age_ms,
                         receive_delta_ms,buy_vwap,sell_vwap,gross_edge_bps,net_edge_bps,buy_fee_bps,sell_fee_bps,
                         impact_bps,rebalance_reserve_bps,safety_buffer_bps,classification,delay_outcomes_json,
                         rejection_reasons_json,created_at,updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,now(),now())
                    ON CONFLICT DO NOTHING
                    """,
                    (observation.product_id, observation.buy_venue, observation.sell_venue, observation.notional_usd,
                     max(buy.received_at, sell.received_at), buy.book_age_ms, sell.book_age_ms, observation.receive_delta_ms,
                     observation.buy_vwap, observation.sell_vwap, observation.gross_edge_bps, observation.net_edge_bps,
                     observation.cost_components_bps["buy_fee"], observation.cost_components_bps["sell_fee"],
                     observation.cost_components_bps["impact"], observation.cost_components_bps["rebalance_reserve"],
                     observation.cost_components_bps["safety_buffer"], observation.classification,
                     Jsonb({"250": None, "500": None, "1000": None}), Jsonb(observation.rejection_reasons)),
                )


async def collect_coinbase(products: Iterable[str], sink: MarketEventSink) -> None:
    books = {product: L2Book("coinbase", product) for product in products}
    url = os.getenv("COINBASE_MARKET_DATA_WS", "wss://advanced-trade-ws.coinbase.com")
    while True:
        try:
            async with websockets.connect(url, ping_interval=20, ping_timeout=20) as websocket:
                await websocket.send(json.dumps({"type": "subscribe", "product_ids": list(products), "channel": "level2"}))
                async for raw in websocket:
                    received = datetime.now(timezone.utc)
                    message = json.loads(raw)
                    for event in message.get("events", []):
                        product = event.get("product_id")
                        if product not in books:
                            continue
                        sink.raw("coinbase", product, message, received)
                        changes = [(str(item["side"]), float(item["price_level"]), float(item["new_quantity"])) for item in event.get("updates", [])]
                        event_time = _event_time(message, received)
                        book = books[product]
                        try:
                            if event.get("type") == "snapshot":
                                bids = [(price, size) for side, price, size in changes if side.lower() in {"bid", "buy"}]
                                asks = [(price, size) for side, price, size in changes if side.lower() in {"offer", "ask", "sell"}]
                                book.snapshot(bids, asks, message.get("sequence_num"), event_time, received)
                            else:
                                book.update(changes, message.get("sequence_num"), event_time, received)
                            sink.summary(book.summary(NOTIONALS, received))
                            sink.checkpoint("coinbase", product, book)
                        except SequenceGap as exc:
                            sink.incident("coinbase", product, "sequence_gap", str(exc))
                            sink.checkpoint("coinbase", product, book, "resync_required")
                            raise
        except Exception:
            LOGGER.exception("Coinbase collector reconnecting")
            await asyncio.sleep(2)


async def collect_kraken(products: Iterable[str], sink: MarketEventSink) -> None:
    kraken_symbols = {product.replace("-", "/"): product for product in products}
    books = {product: L2Book("kraken", product) for product in products}
    url = os.getenv("KRAKEN_MARKET_DATA_WS", "wss://ws.kraken.com/v2")
    while True:
        try:
            async with websockets.connect(url, ping_interval=20, ping_timeout=20) as websocket:
                await websocket.send(json.dumps({"method": "subscribe", "params": {"channel": "book", "symbol": list(kraken_symbols), "depth": 25, "snapshot": True}}))
                async for raw in websocket:
                    received = datetime.now(timezone.utc)
                    message = json.loads(raw)
                    if message.get("channel") != "book":
                        continue
                    for item in message.get("data", []):
                        product = kraken_symbols.get(item.get("symbol"))
                        if not product:
                            continue
                        sink.raw("kraken", product, message, received)
                        bids = [(float(level["price"]), float(level["qty"])) for level in item.get("bids", [])]
                        asks = [(float(level["price"]), float(level["qty"])) for level in item.get("asks", [])]
                        book = books[product]
                        event_time = _parse_time(item.get("timestamp"), received)
                        if message.get("type") == "snapshot":
                            book.snapshot(bids, asks, None, event_time, received)
                        else:
                            book.update([("bid", price, qty) for price, qty in bids] + [("ask", price, qty) for price, qty in asks], None, event_time, received)
                        checksum = item.get("checksum")
                        if checksum is not None and int(checksum) != kraken_checksum(book):
                            book.valid, book.invalid_reason = False, "checksum_mismatch"
                            sink.incident("kraken", product, "checksum_mismatch", "Kraken checksum mismatch; forcing resynchronization")
                            raise SequenceGap("Kraken checksum mismatch")
                        sink.summary(book.summary(NOTIONALS, received))
                        sink.checkpoint("kraken", product, book)
        except Exception:
            LOGGER.exception("Kraken collector reconnecting")
            await asyncio.sleep(2)


def kraken_checksum(book: L2Book) -> int:
    text = ""
    for levels in (sorted(book.bids.items(), reverse=True)[:10], sorted(book.asks.items())[:10]):
        for price, quantity in levels:
            text += _checksum_number(price) + _checksum_number(quantity)
    return zlib.crc32(text.encode()) & 0xFFFFFFFF


def _checksum_number(value: float) -> str:
    return (f"{value:.12f}".rstrip("0").replace(".", "").lstrip("0") or "0")


def _event_time(payload: Dict[str, Any], fallback: datetime) -> datetime:
    return _parse_time(payload.get("timestamp"), fallback)


def _parse_time(value: Any, fallback: datetime) -> datetime:
    if not value:
        return fallback
    return datetime.fromisoformat(str(value).replace("Z", "+00:00")).astimezone(timezone.utc)


async def run_collectors(connection: Any, products: Iterable[str]) -> None:
    sink = MarketEventSink(connection)
    await asyncio.gather(collect_coinbase(products, sink), collect_kraken(products, sink))
