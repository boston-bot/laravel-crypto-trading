# Trading Operations UI Guide Design

Date: 2026-07-19  
Status: Approved for documentation

## Purpose

Create one developer/operator handbook for the repository owner explaining how to use the local Trading Operations Console, interpret its evidence and operational states, complete each supported workflow, troubleshoot failures, and make strategy changes without breaking reproducibility or contaminating holdout evidence.

The deliverable will be stored at `docs/trading-operations-ui-guide.md` and will complement, rather than duplicate, `docs/strategy-research-runbook.md`.

## Audience and assumptions

The sole reader is the developer and operator of this repository. The guide may assume familiarity with Laravel, Artisan, PostgreSQL, Python virtual environments, JavaScript builds, Git, and reading application code. It should still explain domain-specific states and safety rules explicitly.

The guide will include both UI operations and the command-line steps required for operations intentionally unavailable through HTTP, especially holdout authorization and runtime startup.

## Documentation approach

Use a task-first handbook with a compact screen reference. A screen-only reference would fragment multi-screen workflows, while a pure research-lifecycle manual would be less useful for daily operation.

The guide should let the reader begin with a goal such as “understand the last decision,” “run a paper cycle,” “compare candidates,” or “change a strategy,” then follow one complete sequence with links back to detailed screen explanations.

## Required contents

### Safety model and terminology

- State that the console is local-only, Coinbase is the only execution venue, Kraken data is comparison-only, and live trading is not enabled by research or paper evidence.
- Distinguish an evaluation, HOLD, actionable decision, approval, order, fill, attribution, reconciliation, and portfolio snapshot.
- Distinguish `Not measured`, `Incomplete`, `Stale`, `Measured`, operational errors, strategy blockers, and evidence failures.
- Explain immutable versions, manifests, experiment candidates, finalist, holdout, and evidence-eligible paper session.

### Setup and runtime

- Give the required environment defaults and explain the canonical database/Python engine seam.
- Provide commands for migrations, dependencies, tests, production build, data sync/backfill, market-evidence repair, and `trading:runtime`.
- Explain the visible refresh state, background polling, stale fallback, worker heartbeats, queue state, and engine jobs.

### UI screen reference

Document the purpose, major fields, interpretation rules, common actions, and warning states for:

- Overview (`/dashboard`)
- Strategies (`/strategies`)
- Assets (`/assets`)
- Activity (`/activity`)
- Paper (`/paper`)
- Operations (`/operations`)
- Research (`/research`)

The Strategies section must explain all three decision-inspector layers, including rule results, counterfactual, factors, thresholds, risk/policy/execution context, and lineage hashes. HOLD must be described as a complete neutral outcome, not a failure.

The Research section must explain champion/challenger roles, evidence level before performance, normal and stressed results, linked OOS equity, drawdown, folds, robustness, attribution, costs, benchmarks, rejection reasons, and every holdout lifecycle state. Locked holdout values must remain sealed.

### Task workflows

Provide ordered procedures and completion checks for:

1. Initial local setup.
2. Daily startup and health verification.
3. Syncing and repairing point-in-time data.
4. Starting operational paper trading.
5. Running and interpreting a paper cycle.
6. Reviewing and approving/rejecting a paper proposal.
7. Reconciling activity, fills, ledger entries, and snapshots.
8. Creating and monitoring a preregistered four-family experiment.
9. Comparing candidates and determining whether any may become a finalist.
10. Freezing exactly one finalist.
11. Authorizing and interpreting the globally single-use holdout.
12. Starting a pinned evidence-eligible paper session.
13. Monitoring the 90-day forward-paper evidence gate.
14. Responding to stale data, failed cycles, failed reconciliation, mismatched versions, drawdown suppression, worker failures, and inconclusive research.

### Strategy-change workflow

- Identify the primary Laravel and Python locations for strategy definitions, family evaluation, feature computation, portfolio construction, state transitions, execution policy, contracts, fixtures, and tests.
- Categorize changes as explanation/UI-only, implementation-preserving refactors, behavior-changing strategy changes, universe changes, feature/data changes, cost/execution-policy changes, and evidence-gate changes.
- Explain which categories require new hashes, versions, manifests, experiments, holdout intervals, or paper sessions.
- Require tests, parity, causality, formatting, production build, and PostgreSQL checks before evidence collection.
- Explain that a change after finalist freeze invalidates the old lineage for the changed strategy and requires a new development experiment. Never tune against an opened holdout.
- Include a practical edit-to-evidence checklist and recommended Git commit boundaries.

### Command and source reference

- Include copy-paste commands that exist in the repository.
- Link to relevant source files and existing documentation using repository-relative paths.
- Include concise checklists for “safe to run development,” “safe to open holdout,” and “safe to start forward paper evidence.”

## Accuracy and safety constraints

- Describe current implemented behavior only; do not invent UI controls.
- Clearly label command-only operations.
- Never imply that a passing holdout or satisfied paper gate enables live trading.
- Use current paper thresholds: 90 days, 15 closed round trips, three assets, two regimes with 20 common bars each, reconciliation, and maximum 15% drawdown inclusive.
- Explain that drawdown above 15% or manifest/reconciliation failure suppresses new entries while exits remain possible.
- Keep market benchmark returns distinct from strategy performance and state when costs are excluded.
- Do not expose or recommend querying locked holdout values.

## Style and usability

- Write concise but complete Markdown with a linked table of contents.
- Prefer ordered procedures, decision tables, interpretation tables, and checklists over long narrative sections.
- Use warnings only for genuine safety or evidence-integrity boundaries.
- Define terms on first use and include concrete examples where a result is commonly misunderstood.
- Optimize for terminal and GitHub rendering; screenshots and external assets are not required.

## Verification

Before delivery:

- Verify every route, command, threshold, state name, and referenced file against the repository.
- Search the guide for stale eight-week/30-trade thresholds, automatic promotion language, or legacy-engine default instructions.
- Confirm all Markdown links target existing repository files.
- Run a Markdown structure/link sanity check and `git diff --check`.

## Out of scope

- Building new UI controls or changing application behavior.
- Creating screenshots, videos, or a hosted documentation site.
- Designing or authorizing a live-trading workflow.
- Replacing the lower-level strategy research runbook.
