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
- `[EXAMPLE-HERO]`: documentation example only. It shows the expected HERO format and must never be executed as project work.
- `[HERO]`: high-level planning reference for a large initiative. It is not directly executable; future child plans must be derived from it and checked against it before implementation.
- `[HERO-CONCLUDED]`: the HERO has been explicitly concluded by the developer. Never mark a HERO with this status unless the developer specifically asks to conclude that HERO.

## Workflow

- For any request related to roadmap, planning, next tasks, backlog, or implementation order, read this file first.
- Detailed plans live in `storage/ia-context/roadmap-references/`.
- Use numeric kebab-case names such as `001-create-login.md` or `002-configure-user-session.md`.
- Every roadmap reference must be self-contained and readable by an AI that knows nothing except the project documentation named in the reference.
- Standard plans must list required context files to read first.
- Standard plan context files are recommended starting points, not a closed allowlist. Every standard plan must state that the agent may inspect other relevant project files when the plan requires it, while still starting with and preferring the listed files.
- Every standard plan must include `storage/ia-context/project-references/012-troubleshooting-and-qa.md` in `Context Files To Read First`.
- Every standard plan involving Vue pages, Vue components, Vue rendering, Vite integration, or admin Vue screens must include both `storage/ia-context/mvc.md` and `storage/ia-context/mvc-references/10-vue-vite.md` in `Context Files To Read First`.
- Standard plans must define a `Skills To Use` section.
- Standard plans must list application files or directories to inspect before editing.
- Standard plans must list likely files to create or change. If exact files are not knowable yet, list the decision that must be answered first.
- Standard plans must define expected QA evidence before delivery.
- HERO references may contain broader context than standard plans, but they must not be executed directly.
- HERO reference lists are recommended starting points, not a closed allowlist. HERO references should include `storage/ia-context/project-references/012-troubleshooting-and-qa.md`, and any future Vue child plan split from a HERO must include the MVC and Vue/Vite context files required for standard Vue plans.
- Future child plans split from a HERO must be checked against the HERO before implementation.
- Child tasks for a HERO must stay directly below their specific HERO in the roadmap item list, using one leading tab before the child bullet so the relationship is visually obvious.
- If a future request conflicts with a HERO, stop and ask the developer before creating or executing the child plan.
- Every standard plan that affects packages must read `storage/ia-context/package.md` and prefer the mandatory package baseline.
- Every standard plan that affects database behavior must read `storage/ia-context/database_standards.md` and the focused database reference files.
- At the end of executed work, update `storage/ia-context` files when implementation changes project standards, database shape, roadmap status, or reusable knowledge.

## HERO Child Task Indexing

- Keep every child task under the exact HERO that owns it. Do not move child tasks to a separate flat list.
- Use one literal tab before each child task bullet under a HERO item.
- Do not mark a HERO as `[HERO-CONCLUDED]` automatically, even when every child task is `[CONCLUDED]`.
- When completing a child task and it was the last unfinished child task under that HERO, finish the child task and then ask the developer whether the HERO should be marked `[HERO-CONCLUDED]`.
- Mark a HERO `[HERO-CONCLUDED]` only after the developer explicitly asks to conclude that specific HERO.

## Skills To Use Protocol

- Before creating, approving, or executing a roadmap plan, inspect the Codex skills installed globally and the skills available in this project.
- The `Skills To Use` section must list the installed skills that are relevant to the plan, with a short note explaining which task each skill supports.
- If no installed skill is relevant to the plan, the `Skills To Use` section must explicitly say that no installed skill is required and explain why.
- Do not invent skill names or list aspirational skills that are not available in the current environment.
- Select skills according to the work in the plan. For example, front-end Vue work should list Vue-related skills when installed, browser QA should list browser or Playwright skills when installed, and document/spreadsheet work should list the matching artifact skills when installed.
- When the plan can run through Agent Mode (`MA`), group skills by role when useful: Visual Director, Copy Director, Operator, Frontend Specialist, Backend Specialist, Documentation Reviewer, QA, and Code Review.
- Front-end roles should almost always include `playwright`, `browser`, or the project browser-testing skill when one is installed. The Frontend Specialist uses browser checks for practical review, while detailed validation remains the QA role.
- Backend roles should list the relevant language, framework, database, security, package, or testing skills when installed.
- Visual direction should list visual-design, brand, UI, interaction, accessibility, front-end design, image-generation, image-editing, or asset-production skills when installed.
- Copy direction should list writing, creative-writing, marketing-copy, product-copy, or prose skills when installed.
- QA should list browser automation, Playwright, test-framework, trace/log review, and project QA skills when installed.
- Code Review should list language, framework, architecture, testing, security, and code-review skills when installed.
- In Agent Mode (`MA`), the orchestrator must not open or read skill files. Each subagent must verify and read the skills required for its own role before editing, testing, or reviewing.
- Outside Agent Mode, before executing an approved plan, verify that every skill named in `Skills To Use` is still available.
- If any named skill is not found, stop before editing files and report: `Stopped the plan because the required skill was not found: <skill-name>.`

## Agent Mode (`MA`)

- By default, executing an approved roadmap plan uses Agent Mode (`MA`), also called `Modo Agente`. If the current runtime cannot create subagents, report that limitation before executing.
- The developer may opt out for a request by saying not to use Agent Mode, not to use `MA`, `nao usar modo agente`, or `nao usar MA`.
- In Agent Mode, the orchestrator owns coordination only. The orchestrator reads the approved plan, decides which roles are needed, assigns or adopts subagent display labels, passes role instructions, waits for required checkpoints, and resolves impasses.
- In Agent Mode, the orchestrator must not read project context files, inspect source files, review uncommitted file contents, read skill files, or perform code review by itself. Reading context, skills, source files, git diffs, and QA artifacts belongs to the subagents.
- The orchestrator may perform targeted reads only when needed to resolve an impasse, verify a safety issue, enforce explicit developer instructions, or decide whether to stop, restart, or abort the Agent Mode cycle.
- If the tool interface shows an automatic subagent name, that interface-visible name is the official subagent name. The orchestrator must not invent a second conflicting personal name.
- If the tool allows choosing a display name before creation, choose a clear name and include the function in parentheses, such as `Maria (Visual Director)`, `Joao (Frontend Specialist)`, or `Felizberto (Code Review)`. Funny names are allowed when they stay respectful and do not reduce clarity.
- If the tool reveals the visible name only after creation, use the revealed name plus function in all later orchestration messages, such as `Lorentz (Full-stack Operator)`. If the initial prompt used a placeholder or temporary name, immediately send a follow-up correction telling the subagent to use the interface-visible name as the official name.
- Every subagent prompt must explicitly tell the subagent that it is a subagent, include its function label in parentheses, and state that the orchestrator is coordinating the workflow.
- Keep no more than four subagents active at the same time. Prefer three active subagents for normal work, and use the fourth slot only when a short-lived extra role is genuinely useful.
- Do not create every possible role at once. Schedule Agent Mode work in waves so the runtime stays stable and the active set remains small.
- Keep the Operator active when a later QA, Code Review, Frontend, Backend, Visual Director, or Copy Director handoff is likely. Close or summarize completed consultative subagents when their work has been passed forward and the active-agent budget is needed.
- Before any Operator edit in a first pass or repeat pass, the Operator must inspect uncommitted git changes because other subagents may have changed files since the Operator last worked.
- The Operator may request new images from the Visual Director or new copy from the Copy Director when implementation exposes a real need.
- If a subagent reports a blocker that is likely an operational setup gap, the orchestrator must first decide whether it can be safely unblocked instead of accepting it as a residual limitation. Examples include missing `vendor/`, missing `node_modules`, build artifacts not generated, a local server not started, or a verification command not yet attempted.
- The orchestrator may run low-risk workspace commands directly, ask the Operator to run them, or explicitly authorize QA/Code Review to run them when that is the clearest path. The decision depends on risk, scope, and whether the command changes tracked source or project dependency definitions.
- Low-risk unblock commands include installing already-declared dependencies, running builds, running syntax checks, running tests, starting local development servers, and writing QA artifacts under `storage/logs/`. If the command would add a new dependency, change package definitions, read secrets, inspect `.env`, run destructive operations, or require elevated permissions, stop and ask the developer or route the decision through the Operator with the risk stated.
- Read-only QA and Code Review must not edit source files, docs, schema files, package manifests, lock files, or committed assets. They may run verification commands that create ignored caches, generated build output, or QA logs only when the orchestrator explicitly allows it for that round. If a needed command is outside their read-only allowance, they must ask the orchestrator to run it or return it to the Operator.
- Count one Operator return each time the simultaneous QA and Code Review round requires the work to go back to the Operator. If both QA and Code Review return work in the same round, count one return round with stronger evidence.
- On the third Operator return, terminate all active subagents, create fresh subagents, and pass the unresolved QA and Code Review problems to the new Operator with the approved plan and an instruction to inspect the current uncommitted git state.
- After that restart, allow only three more Operator returns. If the restarted operation returns to the Operator three more times, abort the operation and tell the developer that the work failed because there was a problem with the operators.

### Agent Mode Concurrency Budget

- The active subagent budget is four. The orchestrator must never intentionally keep more than four subagents active at the same time.
- The expected active sets are usually smaller: Operator plus Visual Director plus Copy Director, Operator plus QA plus Code Review, or Operator plus Frontend Specialist plus Backend Specialist.
- Use the fourth slot only for a necessary extra role, such as a Documentation Reviewer waiting for routed documentation updates, a Visual Director preparing assets while the Operator works, or a short overlap during a handoff.
- When a new role is needed and four subagents are already active, the orchestrator must first close a finished consultative subagent, summarize that subagent output into the next prompt, or wait for an active role to finish.
- Preserving useful context is important, but it does not override the four-subagent limit. Summaries, changed-file lists, asset paths, QA evidence, and review findings are the handoff mechanism when a subagent must be closed.
- If a runtime supports fewer than four active subagents, use the same wave model with the smaller runtime limit and report that limitation when it affects execution.

### Agent Mode Flow

1. Classify the approved plan: visual direction, brand-image guidance, UI/interaction guidance, image creation, public-facing copy, development, front-end, back-end, documentation, QA, and code-review needs.
2. Assign or adopt display labels for every needed subagent. When possible, label each one as `<name> (<function>)`; otherwise use the interface-visible name as official and include the function in parentheses in the prompt and all later orchestration messages.
3. If visual direction, brand-image guidance, UI/interaction guidance, asset decisions, or image creation is required, start the Visual Director first. This role is responsible for visual direction even when no bitmap image is generated.
4. If image files are needed, the Visual Director is responsible for generating, editing, selecting, or preparing those images and returning asset notes for the Operator.
5. If attractive public copy is required, start the Copy Director in parallel when possible and within the active-agent budget. This includes public site sections such as About Us, Our History, marketing sections, acquisition copy, and similar user-facing content.
6. After Visual Director and Copy Director finish, pass their outputs and placement instructions to the Operator with inherited context. If the task only required visual guidance, images, or copy, stop after the directors and report the result. If implementation continues and the budget is needed, close finished directors after summarizing their output.
7. The Operator acts as the full-stack developer and executes the approved plan for general development work.
8. After the Operator finishes, if the work touched front-end or back-end code, start the needed Frontend Specialist, Backend Specialist, and Documentation Reviewer roles as separate subagents without inherited context, while staying within the active-agent budget. Tell each one which of the others exists.
9. These specialist subagents must not share direct context with each other. Coordination and documentation-change routing go through the orchestrator.
10. Frontend and Backend Specialists may edit only their respective code areas. They may review IA-context documentation but must not edit it directly; documentation changes must be sent to the orchestrator for the Documentation Reviewer.
11. The Documentation Reviewer waits for documentation-change requests from the orchestrator, applies approved documentation edits, and is closed only after Frontend and Backend Specialists finish.
12. Frontend, Backend, and Documentation Reviewer subagents must know that parallel edits may be happening. If a test or review fails due to likely in-progress changes, they may wait a few minutes and retry before reporting a blocker.
13. After Frontend, Backend, and Documentation Reviewer finish, start QA and Code Review simultaneously as read-only subagents while keeping the Operator active when practical. Tell each one the other exists, do not let them share direct context, and wait for both before deciding whether to return work to the Operator.
14. QA reads the plan, relevant project docs, git changed files, and uncommitted changes, then tests the implemented behavior. QA must not edit any file.
15. Code Review reads the plan, relevant project docs, git changed files, and uncommitted changes, then reviews code clarity, method documentation, avoidable indirection, reuse opportunities, and unnecessary logic. Code Review must not edit any file.
16. When QA or Code Review reports that verification is blocked by missing local setup or an unattempted command, the orchestrator must try to unblock it when safe. Run the command directly, ask the Operator to run it, or authorize that read-only role to run it if the command only creates allowed transient artifacts. Do not finish with a residual-risk note until this unblock decision has been made and recorded.
17. If QA or Code Review finds implementation failures, return the work to the Operator and repeat the needed cycle. If both return failures in the same QA/Code Review round, count it as one Operator return with stronger evidence.
18. On the third Operator return, terminate all current subagents, create a fresh Agent Mode set, and pass the unresolved QA and Code Review problems to the new Operator with the approved plan and an instruction to inspect relevant context plus current uncommitted git state.
19. After the fresh Agent Mode set starts, allow only three more Operator returns. If the operation reaches those three additional returns, abort the operation and report to the developer that the work failed because there was a problem with the operators.
20. The Operator may challenge QA or Code Review findings. QA and Code Review may accept the challenge or reply. The orchestrator decides unresolved impasses.

### Agent Mode Role Prompts

- Visual Director prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Visual Director). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. You are responsible for visual direction, brand-image guidance, visual hierarchy, UI density, interaction feel, accessible states, loader behavior, CSS/Vue visual constraints, asset strategy, and image needs for this plan. Read the plan and required visual/front-end/brand context. If images or visual assets are needed, generate, edit, select, or prepare them yourself and return file paths, prompts, usage guidance, and placement notes. Return concise visual and interaction guidance for the Operator. Do not implement application code unless the orchestrator explicitly asks.`
- Copy Director prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Copy Director). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. Read the plan and public-facing context. Write attractive, project-appropriate copy for the requested public sections and tell the Operator exactly where each text belongs. Do not implement application code unless the orchestrator explicitly asks.`
- Operator prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Full-stack Operator). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. Read the plan, required project docs, outputs from Visual Director and Copy Director, and current uncommitted git changes before editing. Implement the plan, keep scope tight, update required context docs when implementation changes project knowledge, and return changed files plus verification evidence.`
- Frontend Specialist prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Frontend Specialist). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. Start without inherited context. Read the approved plan, required project docs, front-end files, and current uncommitted git changes. Review and improve front-end code, reuse, components, UX implementation, and practical browser behavior. Use Playwright or browser checks when appropriate. You may edit front-end files. Review IA-context docs but send documentation-change requests to the orchestrator instead of editing docs directly.`
- Backend Specialist prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Backend Specialist). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. Start without inherited context. Read the approved plan, required project docs, back-end files, and current uncommitted git changes. Review and improve back-end code, class reuse, parent-class extraction opportunities, security, validation, persistence, and unnecessary logic. You may edit back-end files. Review IA-context docs but send documentation-change requests to the orchestrator instead of editing docs directly.`
- Documentation Reviewer prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Documentation Reviewer). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. Start without inherited context. Read the approved plan, required project docs, and documentation-change requests from the orchestrator. Update IA-context documentation only from those requests, remove useless documentation when requested, and report changed docs. Stay idle until Frontend or Backend Specialists send documentation requests through the orchestrator.`
- QA prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Read-only QA). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. Do not edit source, docs, schema, package manifests, lock files, or committed assets. Read the plan, relevant project docs, git changed files, and uncommitted changes. Test the implemented behavior, inspect logs or browser output when relevant, and report reproducible failures with evidence. If a missing local setup step or unattempted command blocks verification, ask the orchestrator to run it, return it to the Operator, or request explicit permission to run it when it only creates allowed transient artifacts. If failures are found, recommend returning work to the Operator.`
- Code Review prompt: `You are a subagent. Use the name shown for you in the interface as your official name, and use the workflow label <interface-visible-name> (Read-only Code Review). Do not use any other personal name unless the orchestrator corrects it after creation. The orchestrator is coordinating the workflow. Do not edit source, docs, schema, package manifests, lock files, or committed assets. Read the plan, relevant project docs, git changed files, and uncommitted changes. Review method documentation, code clarity, directness, reuse, avoidable complexity, unnecessary logic, and whether implementation matches the plan. If a missing local setup step or unattempted command blocks review, ask the orchestrator to run it, return it to the Operator, or request explicit permission to run it when it only creates allowed transient artifacts. If problems are found, recommend returning work to the Operator.`

## Required Standard Plan Sections

Each standard roadmap reference must include these sections:

- `Status`
- `Goal`
- `Context Files To Read First`
- `Skills To Use`
- `Files Or Directories To Inspect`
- `Open Questions For User`
- `Implementation Steps`
- `Expected QA Evidence`
- `Files Likely To Change`
- `Approval Notes`

## Required HERO Sections

Each `[HERO]` or `[EXAMPLE-HERO]` reference must start with an H1 title, a `Status` line, and this required notice:

`This is a high-level planning reference. It must not be executed directly. Future child plans must be split from this HERO and checked against it before implementation. If a future request conflicts with this HERO, stop and ask the developer before creating or executing the child plan.`

Each `[HERO]` or `[EXAMPLE-HERO]` reference must include these sections:

- `Status`
- `Goal`
- `Context`
- `Decisions Already Shaped`
- `References`
- `Proposed Initial Database Shape`
- `Files Or Directories To Inspect`
- `Open Questions For User`
- `Future Child Plan Candidates`

Recommended optional HERO sections:

- `Non-Goals And Boundaries`
- `Child Plan Rules`

HERO references may be larger than standard plans because they preserve broad initiative context. Implementation steps, QA evidence, files likely to change, and approval notes belong in future child plans unless the developer explicitly asks to include them in the HERO as non-executable guidance.

## Open Questions Protocol

- Use `Open Questions For User` for any unclear requirement.
- Keep questions concrete and answerable.
- Do not hide assumptions in implementation steps.
- Do not convert a `[PLANNING]` item to `[APPROVED]` while `Open Questions For User` contains unanswered items.
- Open questions in a HERO do not block documenting the HERO itself, but they block approval or execution of any child plan that depends on those answers.
- If answers change the plan, update the plan first, then ask for approval of the updated plan.

## Items

- `[EXAMPLE]` [Example roadmap plan](roadmap-references/001-example.md): demonstrates the expected structure for a plan, including required context reads, open questions, execution steps, QA evidence, and approval notes. It is not real project work.
- `[EXAMPLE-HERO]` [Example HERO roadmap reference](roadmap-references/002-example-hero.md): demonstrates the expected structure for a high-level HERO reference that future child plans must be split from. It is not real project work.
