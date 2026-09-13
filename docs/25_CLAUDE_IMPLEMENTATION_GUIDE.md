# Claude Implementation Guide

## Purpose
This document tells an AI coding agent how to work on SchoolHub safely.

## Required Reading Order
Before implementation:
1. `README.md`
2. `docs/00_MASTER_SPECIFICATION.md`
3. The module specification related to the task
4. `docs/17_DATABASE_DESIGN.md` for database changes
5. `docs/18_API_DESIGN.md` for API changes
6. `docs/21_UI_UX_ACCESSIBILITY.md` for UI changes
7. `docs/22_TESTING_AND_ACCEPTANCE.md` for completion criteria

## Implementation Procedure
1. Inspect the existing repository.
2. Identify existing reusable components.
3. Identify affected database tables.
4. Identify affected roles and permissions.
5. Plan the smallest safe change.
6. Implement.
7. Test success cases.
8. Test validation/error cases.
9. Test unauthorized access.
10. Check responsive UI.
11. Update documentation if behavior changed.
12. Summarize changed files and tests.

## Do Not
- Do not rewrite the entire project for a small feature.
- Do not delete existing functionality without instruction.
- Do not invent business rules that conflict with the specifications.
- Do not bypass backend authorization.
- Do not expose passwords/secrets.
- Do not use plaintext passwords.
- Do not put executable files in upload storage.
- Do not silently change the technology stack.
- Do not mark a feature complete without testing.

## When Requirements Are Ambiguous
Use the existing specification first. If still ambiguous, choose the simplest safe implementation that does not conflict with existing behavior and clearly report the assumption.

## Completion Report Format
After implementation, report:
- Feature implemented
- Files created
- Files modified
- Database changes
- Routes/API changes
- Permissions changed
- Tests performed
- Known limitations
- Assumptions

## Additional Required Reading
Insert into the reading order above:

- `CLAUDE.md` at the repository root — read **first**; it is loaded automatically and
  carries the stack constraints and security rules.
- `docs/26_STATUS_TRANSITIONS.md` — required before touching any enrollment status.
- `docs/17_DATABASE_DESIGN.md` section 8 — the integrity rules the schema cannot
  enforce. These are the ones most easily missed.
