# 001 Example Roadmap Plan

Status: `[EXAMPLE]`

This is an example plan. It demonstrates the expected structure only and must not be executed as real project work.

## Goal

Create an example authenticated profile screen that displays the current user, lets the user edit language and timezone preferences, and shows timestamps using normalized display fields.

## Context Files To Read First

- `AGENTS.md`
- `storage/ia-context/project.md`
- `storage/ia-context/package.md`
- `storage/ia-context/database_standards.md`
- `storage/ia-context/roadmap.md`
- `storage/ia-context/project-references/001-security-baseline.md`
- `storage/ia-context/project-references/002-temporal-api-and-ui-normalization.md`
- `storage/ia-context/project-references/003-frontend-i18n-foundation.md`
- `storage/ia-context/project-references/004-user-feedback-and-confirmations.md`

## Files Or Directories To Inspect

- Backend routes/controllers for authenticated user/session behavior.
- Backend models or services that persist user preferences.
- Front-end entry point, shared components, and current router/page structure.
- Existing i18n/message files.
- Existing date/time formatter or service files.

## Open Questions For User

- Should language and timezone preferences be global per user or scoped per organization/tenant?
- Which timezone list should be available to the user?
- Should saving preferences happen immediately on change or only after clicking a save button?

These questions intentionally block approval. If this were a real plan, it could not become `[APPROVED]` until the user answered them or explicitly delegated the exact decisions and approved the updated plan.

## Implementation Steps

- Confirm the answers to the open questions and update this plan.
- Add or reuse backend endpoints/services for reading and saving profile preferences.
- Validate server-side authorization, accepted locale values, accepted timezone values, and response shape.
- Add or reuse front-end form controls using the package baseline.
- Display timestamps through `_tz_normalized` fields or the shared temporal formatter.
- Use localized visible copy and shared feedback/confirmation behavior.
- Update `storage/ia-context` if implementation creates new reusable patterns.

## Expected QA Evidence

- Backend lint/static checks for changed PHP files.
- Focused backend tests for authorization and validation.
- Front-end build.
- Browser checks for desktop and mobile profile screen behavior.
- Verification that displayed timestamps use normalized fields and show the active timezone.

## Files Likely To Change

- Backend route/controller/service files.
- User preference model or repository files.
- Front-end profile page/component files.
- Front-end i18n/message files.
- Shared date/time or preference service files.

## Approval Notes

- Example only. A real plan records user answers here before changing status to `[APPROVED]`.
