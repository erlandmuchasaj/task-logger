---
name: commit
description: Stage and commit changes following project conventions
---

Commit the current changes following project conventions.

## Steps:

1. Run `git status` and `git diff` (staged + unstaged) to understand all changes
2. Extract the issue number from the current branch name (e.g., `feature/ERL-1822` → `ERL-1822`). If the branch doesn't match the `{type}/{issue-number}` pattern, ask the user for the issue number before proceeding.
3. Group changes by subject — if changes span multiple unrelated concerns, create separate commits for each group
4. For each commit group: stage the relevant files by name (never `git add -A`), then commit
5. Do NOT commit files that likely contain secrets (`.env`, credentials, etc.)

## Branch naming:
`{type}/{issue-number}` — type from Linear labels: `bug`→`bugfix/`, `task`→`task/`, `feature`→`feature/`, no label→`feature/`

## Commit message format:
`{ISSUE-NUMBER} {message}`

- Message is lowercase, concise, describes the change
- Example: `ERL-1234 add user auth endpoints`
- Example: `ERL-1822 remove CAST from MorphToMany join conditions`

## Rules:
- **NEVER** add `Co-Authored-By` or any attribution trailers
- **NEVER** modify git author/committer config
- **NEVER** use `--no-verify` to skip hooks
- **NEVER** amend a previous commit unless explicitly asked — always create a new commit
- If a pre-commit hook fails, fix the issue and create a NEW commit

## If `$ARGUMENTS` is provided:
Use it as the commit message body (still prefix with the issue number).
