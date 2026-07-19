from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Mapping

import pandas as pd

from .portfolio import construct_target
from .strategy_definition import StrategyDefinition, canonical_hash
from .strategy_families import evaluate_family
from .strategy_state import PositionState, StrategyState
from .execution_policy import build_order_intent


@dataclass
class PortfolioEvaluator:
    definition: StrategyDefinition

    def evaluate(self, feature_frames: Mapping[str, pd.DataFrame], assets: list[dict[str, Any]], as_of: datetime, portfolio_context: dict[str, Any], evidence_hash: str, states: Mapping[str, StrategyState] | None = None) -> dict[str, Any]:
        cutoff = pd.Timestamp(as_of if as_of.tzinfo else as_of.replace(tzinfo=timezone.utc)).tz_convert("UTC")
        states = states or {}
        scored: list[dict[str, Any]] = []
        raw: dict[str, dict[str, Any]] = {}
        for asset in assets:
            symbol = str(asset["symbol"])
            frame = feature_frames.get(symbol, pd.DataFrame())
            frozen = frame.loc[frame.index <= cutoff] if not frame.empty else frame
            if frozen.empty:
                raw[symbol] = {"asset_id": int(asset["id"]), "asset": symbol, "missing": True}
                continue
            family = evaluate_family(self.definition.family, frozen.iloc[-1].to_dict())
            item = {"asset_id": int(asset["id"]), "asset": symbol, "score": family.score, "family": family, "correlation_group": asset.get("correlation_group", symbol)}
            raw[symbol] = item
            if family.score >= float(self.definition.parameters["entry_score"]) and all(rule["passed"] for rule in family.rules):
                scored.append(item)
        target = construct_target(scored, portfolio_context, self.definition.parameters)
        target_assets = {position["asset"] for position in target["positions"]}
        ranks = {item["asset"]: rank for rank, item in enumerate(sorted((v for v in raw.values() if not v.get("missing")), key=lambda x: (-x["score"], x["asset"])), 1)}
        proposals = []
        current_positions = {str(p.get("asset")): p for p in portfolio_context.get("positions", [])}
        for asset in assets:
            symbol = str(asset["symbol"]); item = raw[symbol]; state = states.get(symbol, StrategyState(PositionState.OPEN if symbol in current_positions else PositionState.FLAT))
            if item.get("missing"):
                action, resolution, factors, rules, gross = "HOLD", "blocked_by_evidence", {}, [{"rule": "point_in_time_features", "passed": False}], 0.0
                reason = ["missing_point_in_time_features"]
            else:
                factors, rules, gross = item["family"].factors, list(item["family"].rules), item["score"] * 100
                held_days = (cutoff.to_pydatetime() - state.opened_at).total_seconds() / 86400 if state.opened_at else 0
                forced_exit = state.state in {PositionState.OPEN, PositionState.EXIT_CONFIRMING} and (item["score"] <= float(self.definition.parameters["exit_score"]) or held_days >= float(self.definition.parameters.get("maximum_hold_days", 21)))
                if forced_exit:
                    action, resolution, reason = "EXIT", "actionable", ["exit_rule_confirmed"]
                elif symbol in target_assets and state.state in {PositionState.FLAT, PositionState.ENTRY_CONFIRMING}:
                    next_state = state.advance(True, False, cutoff.to_pydatetime(), entry_bars=int(self.definition.parameters.get("entry_confirmation_bars", 2)))
                    confirmed = next_state.state is PositionState.OPEN
                    action, resolution, reason = ("ENTER", "actionable", ["entry_confirmed"]) if confirmed else ("HOLD", "blocked_by_strategy", ["entry_confirmation_pending"])
                else:
                    action, resolution, reason = "HOLD", "blocked_by_strategy", ["not_selected_for_portfolio"]
            costs = float(self.definition.parameters["cost_estimate_bps"]); net = gross - costs
            if action == "ENTER" and net < float(self.definition.parameters["minimum_net_edge_bps"]):
                action, resolution, reason = "HOLD", "blocked_by_strategy", ["insufficient_net_edge"]
            rules = [*rules, {"rule": "minimum_net_edge", "passed": net >= float(self.definition.parameters["minimum_net_edge_bps"]), "observed": net, "required": float(self.definition.parameters["minimum_net_edge_bps"])}]
            trace = {"strategy_family": self.definition.family, "strategy_definition_version": self.definition.version, "rank": ranks.get(symbol, len(assets)), "portfolio_state": {"state": state.state.value, "cash_weight": target["cash_weight"], "gross_exposure": 1-target["cash_weight"]}, "rule_checklist": rules, "factor_contributions": factors, "gross_edge_bps": gross, "cost_estimate_bps": costs, "net_edge_bps": net, "primary_explanation": f"{symbol} resolved to {action} because {reason[0].replace('_', ' ')}.", "reason_codes": reason, "counterfactual": "The action changes when the failed rule passes at a later closed bar.", "evidence_hash": evidence_hash, "parameter_hash": self.definition.parameter_hash}
            target_position = next((position for position in target["positions"] if position["asset"] == symbol), None)
            current_weight = float(current_positions.get(symbol, {}).get("weight", 0.0))
            target_weight = float(target_position["target_weight"]) if target_position else 0.0
            reference_price = float(item.get("family") and feature_frames[symbol].loc[feature_frames[symbol].index <= cutoff].iloc[-1].get("reference_price", 0.0) or 0.0)
            order_intent = None
            if action in {"ENTER", "EXIT"} and reference_price > 0:
                order_intent = build_order_intent(asset_id=int(asset["id"]), asset=symbol, side="buy" if action == "ENTER" else "sell", target_weight=target_weight, delta_weight=target_weight-current_weight, equity=float(portfolio_context.get("equity", 0.0)), reference_price=reference_price, earliest_execution_at=cutoff.isoformat(), portfolio_context_hash=str(portfolio_context.get("context_hash", "")), portfolio_target_hash=target["target_hash"], base_increment=str(asset.get("base_increment", "0.00000001")), minimum_notional=float(asset.get("minimum_notional", 1.0)), costs={"fee_bps": costs, "spread_bps": 0.0, "slippage_bps": 0.0}, idempotency_key=f"strategy:{self.definition.version}:bar:{cutoff.isoformat()}:{symbol}:{action.lower()}")
            proposals.append({"asset_id": int(asset["id"]), "asset": symbol, "action": action, "resolution": resolution, "decision_trace": trace, "portfolio_target": target, "order_intent": order_intent, "warnings": reason if resolution == "blocked_by_evidence" else []})
        return {"strategy_family": self.definition.family, "strategy_definition_version": self.definition.version, "proposals": proposals, "diagnostics": {"point_in_time": True, "parameter_hash": self.definition.parameter_hash, "portfolio_target_hash": target["target_hash"], "evaluation_hash": canonical_hash(proposals)}}
