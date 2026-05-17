# Roadmap Workflow

This file controls project planning and execution.

## Core Rule

- Do not infer missing requirements in roadmap work.
- Planning may contain open questions from the AI.
- Any unresolved open question blocks approval and execution.
- If a user says to approve a plan without answering existing questions, ask again before approval. Do not mark it approved and do not execute it.
- If the user wants the AI to decide, the roadmap must record the exact delegated decision and the user must approve that recorded decision before execution.

## Status Tags

- `[PLANNING]`: the task is still being shaped, may contain AI questions, and must not be executed.
- `[APPROVED]`: the only status that makes a task actionable.
- `[CONCLUDED]`: the task has been completed and must not be selected as next work.
- `[EXAMPLE]`: documentation example only. It shows the expected format and must never be executed as project work.

## Workflow

- For any request related to roadmap, planning, next tasks, backlog, or implementation order, read this file first.
- Detailed plans live in `storage/ia-context/roadmap/references/`.
- Use numeric kebab-case names such as `001-create-login.md` or `002-configure-user-session.md`.
- Every plan must be self-contained and readable by an AI that knows nothing except the context files named in the plan.
- Every plan must list required context files to read first.
- Every plan must list application files or directories to inspect before editing.
- Every plan must list likely files to create or change. If exact files are not knowable yet, list the decision that must be answered first.
- Every plan must define expected QA evidence before delivery.
- At the end of executed work, update `storage/ia-context` files when implementation changes project standards, database shape, roadmap status, or reusable knowledge.

## Required Plan Sections

Each roadmap reference must include these sections:

- `Status`
- `Goal`
- `Context Files To Read First`
- `Files Or Directories To Inspect`
- `Open Questions For User`
- `Implementation Steps`
- `Expected QA Evidence`
- `Files Likely To Change`
- `Approval Notes`

## Open Questions Protocol

- Use `Open Questions For User` for any unclear requirement.
- Keep questions concrete and answerable.
- Do not hide assumptions in implementation steps.
- Do not convert a `[PLANNING]` item to `[APPROVED]` while `Open Questions For User` contains unanswered items.
- If answers change the plan, update the plan first, then ask for approval of the updated plan.

## Items

- `[EXAMPLE]` [Example roadmap plan](roadmap-references/001-example.md): demonstrates the expected structure for a plan, including required context reads, open questions, execution steps, QA evidence, and approval notes. It is not real project work.
