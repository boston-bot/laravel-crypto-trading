from __future__ import annotations

import argparse
import os
import asyncio

from .worker import run_worker
from .collector import run_collectors
from .database import connect


def main() -> int:
    parser = argparse.ArgumentParser(prog="trading-engine")
    subparsers = parser.add_subparsers(dest="command", required=True)
    worker = subparsers.add_parser("worker", help="Claim durable PostgreSQL engine jobs")
    worker.add_argument("--database-url", default=os.getenv("DATABASE_URL"))
    worker.add_argument("--once", action="store_true")
    worker.add_argument("--poll-seconds", type=float, default=2.0)
    collector = subparsers.add_parser("collect", help="Run public Coinbase and Kraken Level 2 collectors")
    collector.add_argument("--database-url", default=os.getenv("DATABASE_URL"))
    collector.add_argument("--products", default="BTC-USD,ETH-USD,SOL-USD,LINK-USD,LTC-USD")
    args = parser.parse_args()
    if args.command == "worker":
        if not args.database_url:
            parser.error("--database-url or DATABASE_URL is required")
        return run_worker(args.database_url, once=args.once, poll_seconds=args.poll_seconds)
    if args.command == "collect":
        if not args.database_url:
            parser.error("--database-url or DATABASE_URL is required")
        with connect(args.database_url) as connection:
            asyncio.run(run_collectors(connection, [item.strip().upper() for item in args.products.split(",") if item.strip()]))
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
