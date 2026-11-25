---
name: ticket
description: "Take one Linear issue from ticket to an open pull request: read the issue, check it against docs/SPEC.md, branch, build it test-first, run the gates in AGENTS.md, then commit, push the feature branch and open the PR with the issue ID so Linear links and closes it. Only run when the user types /ticket <issue ID or URL>; never start it as part of another task."
argument-hint: "<Linear issue ID or URL, e.g. ERL-123>"
disable-model-invocation: true
---

# Ticket

Work one Linear issue to an open pull request. The issue is `$ARGUMENTS`.

Typing `/ticket` is the owner's consent to: create a branch, commit, push **that feature branch**, and open a pull request. It is not consent to anything else. Never push `main`, never force-push, never merge, never move the issue to Done (the merge does that through Linear's GitHub integration).

`AGENTS.md` is the authority for how code is written here and for the "Before you say a task is done" list. This skill is only the order of work. Do not restate those rules from memory; read them.

## 1. Preconditions

1. Run `git status --short` and `git branch --show-current`.
   - If the working tree has changes that are not yours, stop and tell the user. Do not carry unrelated work into the branch, and do not stash or discard it.
   - If the current branch is not `main`, say so and ask whether to branch from here or from `main`.
2. `gh auth status` must pass. Use `gh` for everything on GitHub. Do not use the GitHub MCP server.
3. If `$ARGUMENTS` is empty, ask for the issue ID and stop.

## 2. Read the issue

Linear's tools come from the Linear MCP server and their names are not fixed. Find them with `ToolSearch` (query `linear issue`, then `linear comment`), then read:

- title, description, acceptance criteria, labels, priority, project, parent and sub-issues, and **all comments** (decisions often hide there);
- the issue's identifier (for example `ERL-123`) and its type, from the label: bug, task or feature (no label counts as feature).

If no Linear tool is available, tell the user to connect it (`/mcp`, server `linear`) or to paste the issue text, and stop until they do. Never guess an issue's content from its ID.

## 3. Check it against the spec

1. Find the rules the issue touches: search `docs/SPEC.md` for BR-xx (§6), D-xx (§3.1) and W-xx (§7), and read the milestone note for the module in `docs/04-architecture.md` §8 to §19. A later D-xx overrides earlier text.
2. Decide which of three cases applies:
   - **Clear and covered.** Build it.
   - **The spec is silent.** Follow the gap policy in `AGENTS.md` ("Where the truth is"): take the narrowest default, keep it behind config, an enum or a policy, record it as the next D-xx in `docs/SPEC.md` §3.1 marked `Confirm: yes`, and build. Entry format is in that section: `**D-NN — Title.**` then Source, Default, Confirm.
   - **Irreversible or contradictory.** If the work would remove or rewrite stored data, or the issue contradicts a D-xx or BR-xx, do not build. Post the question as a comment on the Linear issue, tell the user, and stop.
3. If the issue is too vague to build even with a narrow default, comment on it with the specific questions and stop. A short, concrete question beats a guessed feature.

## 4. Branch

Create the branch from up-to-date `main` and put the issue ID in it (Linear links on it):

The name is `{type}/{ISSUE}`, as in `AGENTS.md` and the `/commit` skill, which reads the issue ID from it. The type comes from the Linear label: `bug` is `bugfix/`, `task` is `task/`, `feature` or no label is `feature/`. Keep the ID in upper case and add no slug, for example `feature/ERL-123`. Do not touch `.claude/settings.local.json`.

## 5. Build

- Work test-first where a rule changes. Load the skills that match the work before writing code: `testing-best-practices` for tests, `filament-development` for screens, `laravel-permission-development` for permissions, `activitylog-development` for audited models. Read `tests/CLAUDE.md` before the first test and `app/Filament/CLAUDE.md` before the first screen.
- Copy the nearest sibling, as `AGENTS.md` says. A schema change is a new migration under the "Schema" rules; never edit a committed one.
- Keep the diff to what the issue asks. Note anything else you notice in the report and in a Linear comment; do not fix it here.

## 6. Verify

Run the "Before you say a task is done" list in `AGENTS.md`, line by line. In practice that means:

- `vendor/bin/pint --dirty --format agent`, then re-run the affected tests (Pint changes behaviour);
- `composer test` and read the exit code. A failing gate is fixed or reported with its output, never described as passing;
- for a screen: `npm run build`, then open it at `http://task-logger.test/app` as a role that is not the superadmin, in light and dark mode and at phone width, and check `browser-logs`. If you cannot do this, say so in the PR instead of skipping it quietly.

If `.claude/agents/spec-conformance-reviewer.md` exists, run that agent on the diff and act on what it finds.

## 7. Commit, push, open the PR

Only when `composer test` exited 0. If a gate is red and you cannot fix it, stop here: do not push, and report the failing output to the user.

1. `git status` must show only files for this issue.
2. Commit through the `/commit` skill (Skill tool, `skill: "commit"`). It holds the message format (`ERL-123 add leave carry-over report`, lower case, no attribution lines) and stages files by name. Never run `git add` or `git commit` by hand.
3. `git push -u origin <branch>`.
4. `gh pr create` against `main`:
   - title: `<ID>: <description>`;
   - body, in this order: `Closes <ID>`; what changed; what you verified and how; what you could not verify; every D-xx you added, each with its "Confirm: yes" question; every place the spec and the code disagree. This is item 10 of the `AGENTS.md` list.
5. Comment on the Linear issue with the PR link, the new D-xx questions and anything unverified. Do not change the issue's status.

## 8. Report and hand off

Tell the user, briefly: the branch and PR link, what changed, the gates that ran and their result, what you could not verify, and the D-xx entries that need an owner answer.

Then tell them to type `/ship` to have the PR reviewed and watched through CI, conflicts and review comments, or `/steward` to only watch it. Both are typed by the user; you cannot start them. Merging is the owner's step (or GitHub auto-merge once CI and an approval are in).
