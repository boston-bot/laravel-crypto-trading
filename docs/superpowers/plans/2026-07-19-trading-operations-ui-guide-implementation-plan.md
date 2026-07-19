# Trading Operations UI Guide Implementation Plan

Date: 2026-07-19

**Goal:** Publish a developer/operator handbook explaining how to use every Trading Operations Console screen, interpret evidence and failures, execute the supported workflows, and make reproducible strategy changes.

**Design:** `docs/superpowers/specs/2026-07-19-trading-operations-ui-guide-design.md`

## Task 1: Verify the implemented operator surface

Inspect and record the canonical source for:

- console routes, page titles, actions, and API projections;
- runtime and research Artisan commands;
- decision, research, holdout, and paper-evidence states;
- strategy definitions, feature/family logic, portfolio construction, state transitions, execution policy, contracts, and tests;
- current environment defaults and numeric evidence gates.

## Task 2: Author the handbook

Create `docs/trading-operations-ui-guide.md` with:

- linked table of contents;
- safety model and terminology;
- setup and runtime instructions;
- detailed reference for all seven console screens;
- task-first daily, paper, research, holdout, and troubleshooting workflows;
- result-interpretation tables and common misreadings;
- strategy-change classification, source map, versioning rules, testing gates, and edit-to-evidence checklist;
- command reference and readiness checklists.

Keep detailed research internals in `docs/strategy-research-runbook.md`; summarize them in the UI guide and link to the runbook.

## Task 3: Verify and deliver

- Confirm all documented routes and commands exist.
- Confirm all referenced repository files exist.
- Search for stale thresholds, legacy-default instructions, automatic-promotion language, and accidental holdout disclosure guidance.
- Run `git diff --check`.
- Commit the completed guide as `docs: add trading operations ui handbook`.
