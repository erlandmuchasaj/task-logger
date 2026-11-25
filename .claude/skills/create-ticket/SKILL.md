---
name: create-ticket
description: Create a Linear ticket with proper structure and conventions. Only run when the user types /create-ticket.
argument-hint: "<what the ticket is about>"
disable-model-invocation: true
---

Create a Linear ticket from `$ARGUMENTS`.

## Steps:
1. Find the Linear tools with `ToolSearch` (query `linear issue`, then `linear cycle`). Their names come from the Linear MCP server and are not fixed. If none is available, tell the user to connect it (`/mcp`, server `linear`) and stop.
2. Look up "me" and their team. If there are several teams, ask which.
3. Look up the team's current cycle.
4. Determine type: **bug** (broken behavior) vs **feature/task** (new work)
5. Write the description, then run it through the `humanizer` skill (`AGENTS.md`: text written for people).
6. Create the issue assigned to "me" with the current cycle. Check first that a matching issue does not already exist.

## Title:
Concise, plain language. Avoid jargon.

## Bug description format:
```
**Steps to Reproduce:**
1. [Exact steps]

**Actual Result:**
[Current behavior — specific, factual]

**Expected Result:**
[Correct behavior and why actual is wrong]

**Browsers:**
[Operating system and browser with versions, only if the bug is in the web app]
```

## Feature/task description format:
```
**Context**
Currently, [behavior/limitation]. This [issue/impact].
We need to [objective] so that [benefit].
After implementation, [expected behavior].

**Tasks**
- [Imperative verb] [specific, testable action]
- Add/update tests covering [scenarios and edge cases]
```

## Style:
Plain language; imperative mood for tasks; no code blocks unless asked; no extra sections; infer missing details conservatively; never invent links or product names.

## Defaults (override if user specifies):
Status=To Do, Priority=3/Medium (0=None,1=Urgent,2=High,3=Medium,4=Low), Assignee="me", Cycle=current. Estimate/Project only if provided.
