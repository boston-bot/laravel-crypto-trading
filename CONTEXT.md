# Domain Context

## Canonical Market Evidence

The point-in-time candle set allowed to influence a trading evaluation. Evidence must use the canonical provider identity, UTC-aligned intervals, acceptable finality and quality, and availability no later than the evaluation time.

## Logical Bar

The shared close time of the complete candle evaluated by a strategy run. A logical bar identifies the market event being evaluated independently of the scheduler minute that observed it.

## Common Eligible Bar

The newest logical bar for which every asset in the pinned universe has Canonical Market Evidence.

## Evidence Eligibility

Whether an asset evaluation is supported by complete and valid point-in-time evidence. Evidence Eligibility is durable and does not expire after evaluation.

## Actionability

Whether an eligible non-HOLD evaluation may create a trade decision at consumption time. Actionability may be suppressed without discarding the evaluation.

## Evidence Cutoff

The UTC time at which the scheduler observed the market-evidence state used by a cycle. It limits evidence availability and local first observation independently of the Logical Bar.

## Repair Plan

A deterministic, read-only description of malformed or missing market evidence and the exact bounded mutations an operator-run repair would perform.
