---
name: create-ticket
description: Create a Linear ticket with proper structure and conventions
---

Create a Linear ticket from `$ARGUMENTS`.

## Steps:
1. `get_user("me")` → get name/team. If multiple teams, ask which.
2. `list_cycles(teamId, type: "current")` → get current cycle
3. Determine type: **bug** (broken behavior) vs **feature/task** (new work)
4. Create issue assigned to "me" with current cycle

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
[e.g. Mac OS Monterey 12.2, Safari 15.3/Chrome 140]
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
