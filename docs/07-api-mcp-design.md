# 07. API design: MCP server only

Per D-47 there is **no public or REST API**. The only machine interface is an **MCP (Model Context Protocol) server** so coding agents can work with a user's own data. It is a thin facade: every tool calls the same action classes as the UI (05 section 6), so there is one implementation of each business rule.

## 1. Server

| Aspect | Decision |
|---|---|
| Package | `laravel/mcp` (1.1.2, Laravel 13 compatible) |
| Transport | Streamable HTTP at `POST /mcp` (a stdio server cannot carry a per-user token, so it is not used) |
| Authentication | `Authorization: Bearer <personal token>` (Sanctum personal access token, **Assumption A-21**) |
| Identity | The token's user. The agent acts **as that user**, never as a service account |
| Authorisation | Token **abilities** (scopes) first, then the same policies and scoped queries as the UI |
| Rate limit | 60 requests/minute per token (HTTP 429 + `Retry-After`) |
| Audit | Every call writes an activity-log row: user, token name, tool, arguments (description text truncated to 200 chars), result status, `source = mcp` |
| Deactivation | Deactivating a user deletes their tokens (FR-AUTH-07) |
| Exposed surface | Tools only in v1. No MCP resources or prompts yet |

## 2. Tokens

| Property | Rule |
|---|---|
| Creation | Profile page, "MCP tokens" (Livewire component) |
| Fields | Name (required), scopes (checkboxes), expiry (optional, default 90 days, max 1 year; **Assumption A-26**) |
| Display | Plain token shown **once**; stored hashed |
| Scopes | `read`, `log-time`, `leave-request` |
| Revocation | Immediate, per token or "revoke all" |
| Who | Any active user with `tokens.manage-own` (all roles) |
| Admin tools | Superadmin can list and revoke any token (no plain value), audited |

## 3. Tools (v1)

All dates are ISO `YYYY-MM-DD`. "Today", "this week" and "this month" are evaluated in the **user's timezone**; weeks start on **Monday** (**Assumption A-27**). Durations in tool inputs/outputs are **hours as decimals** (0.25 steps) because they are friendlier to agents; internally they become integer minutes. Money and rates are **never** returned by any tool.

| # | Tool | Scope | Purpose | Read-only |
|---|---|---|---|---|
| 1 | `list_my_tasks` | read | Tasks assigned to me | yes |
| 2 | `show_my_task` | read | One of my tasks with progress | yes |
| 3 | `log_my_time` | log-time | Create a time log | no |
| 4 | `list_my_logs` | read | My logs in a range | yes |
| 5 | `request_pto` | leave-request | Submit a PTO request | no |
| 6 | `hours_summary` | read | Hours logged today / this week / this month | yes |
| 7 | `count_active_tasks` | read | How many active tasks I have | yes |
| 8 | `my_leave_balances` | read | PTO and sick days available/used | yes |

### 3.1 `list_my_tasks`

| Input | Type | Required | Notes |
|---|---|---|---|
| status | enum `todo`, `in_progress`, `in_review`, `done`, `cancelled` or `active` | no | `active` = todo + in_progress + in_review (default) |
| overdue_only | boolean | no | |
| project | string | no | partial name match |
| limit | integer 1-50 | no | default 20 |
| cursor | string | no | from previous result |

Output: `tasks[]` each with `id`, `title`, `status`, `project`, `client`, `area`, `due_date`, `is_overdue`, `estimate_hours`, `logged_hours`, `budget_state` (`ok`, `warning`, `over`, `no_estimate`), plus `next_cursor`.

### 3.2 `show_my_task`

Input: `task_id` (required). Output: everything above plus `description`, `my_logged_hours`, `assignees[]` (names only), `recent_logs[]` (my last 5). If the task is not assigned to the caller: `not_found` (no hint that it exists).

### 3.3 `log_my_time`

| Input | Type | Required | Notes |
|---|---|---|---|
| task_id | integer | yes | must be assigned to me |
| date | string | yes | not in the future; default none (explicit is safer for agents) |
| hours | number | yes | multiple of 0.25, 0.25 to 24 |
| description | string | yes | 1-1000 chars |
| hour_type | string | no | name; default type if omitted |
| idempotency_key | string | no | up to 64 chars. Same key within 24h returns the original log instead of duplicating (agents retry) |

Output: `log` (id, date, hours, task, hour type), `day_total_hours`, `remaining_today_hours`, `warnings[]` (e.g. `holiday`, `on_leave`), `budget_state_after`. Failures follow section 4. The action enforces every FR-LOG rule (BR-01 to BR-08, BR-29, BR-30).

### 3.4 `list_my_logs`

Inputs: `from`, `to` (required, max range 92 days), `task_id` (optional), `limit` (1-100, default 50), `cursor`. Output: `logs[]` (id, date, hours, task title, project, hour type, description, `editable` boolean), `total_hours`, `next_cursor`. `editable` is false once the month is locked or the log is invoiced.

### 3.5 `request_pto`

| Input | Type | Required | Notes |
|---|---|---|---|
| start_date, end_date | string | yes | end >= start |
| start_part, end_part | enum `full`, `am`, `pm` | no | default `full` |
| note | string | no | up to 500 chars |

Output: `request_id`, `status: pending`, `days` (computed per the user's country), `balance_after_if_approved`, `warnings[]` (e.g. `would_go_negative`, `overlaps_holiday`), `awaiting[]` (`manager`, `hr`). Only PTO is available via MCP; sick leave stays in the UI (**Assumption A-28**: sick leave is typically reported at short notice by a human and was not requested). The request enters the normal two-party approval flow and notifies approvers.

### 3.6 `hours_summary`

Input: `period` (`today`, `week`, `month`), optional `date` (any date inside the period to look at a past one). Output: `from`, `to`, `total_hours`, `by_day[]`, `by_hour_type[]`, `by_project[]` (top 10), `expected_hours` (**A-14**) and `missing_days[]` (working days with no logs, excluding leave/holidays).

### 3.7 `count_active_tasks`

No input. Output: `active` (todo + in_progress + in_review), counts per status, `overdue`, `over_budget`.

### 3.8 `my_leave_balances`

Input: `year` (default current). Output for `pto` and `sick` each: `allowance`, `carried_over` (PTO only, with expiry date), `used`, `pending`, `available`, plus `next_expiry`.

## 4. Errors

Tool failures return an MCP result with `isError: true` and structured content:

```json
{ "code": "daily_cap_exceeded",
  "message": "You already logged 7.5h on 2026-11-06; the maximum is 24h.",
  "hint": "Remaining: 16.5h.",
  "details": { "remaining_hours": 16.5 } }
```

| Code | Meaning |
|---|---|
| `validation_failed` | Input malformed (field-level `details`) |
| `not_found` | Task or record absent **or not visible to you** |
| `forbidden_scope` | Token lacks the required scope |
| `not_assigned` | Task not assigned to you (returned only where existence is already known; otherwise `not_found`) |
| `task_closed` | Task cancelled, project not active, or client inactive |
| `month_locked` | Date falls in a locked month |
| `daily_cap_exceeded` | Over 24h for the day |
| `no_rate` | Client has no rate for the task's area on that date (BR-06) |
| `future_date` | Date after today |
| `leave_overlap` | Overlaps another active request |
| `rate_limited` | HTTP 429 |

Protocol-level problems (malformed JSON-RPC, unknown tool) use standard MCP errors.

## 5. Security considerations specific to agents

| Risk | Control |
|---|---|
| **Prompt injection** via task titles/descriptions or log text authored by others and returned to the agent | Tool output is treated as data; the server has no tool that reads other users' data or performs admin actions; writes are limited to the caller's own logs and leave; the field `description` returned from tasks is length-limited and plainly labelled in the schema as untrusted text |
| Over-broad token | Scopes; short default expiry; token list in profile with last-used time |
| Runaway agent | 60/min limit; per-day soft cap of 200 `log_my_time` calls per token (**Assumption A-29**) to limit mass bad data; every call audited |
| Duplicate logs from retries | `idempotency_key` |
| Data leakage | No rates, invoices, other users' leave or logs, no emails; output fields whitelisted per tool |
| Impersonation | Impersonated sessions cannot create tokens; tokens work independently of web sessions |

## 6. Schema addition

`time_logs.idempotency_key varchar(64) NULL` with `UNIQUE(user_id, idempotency_key)`; keys older than 24h are cleared by the prune job so they can be reused.

## 7. Test contract

Each tool has Pest feature tests for: scope enforcement, cross-user isolation, each error code, rate limit, audit row, and parity with the UI action (same inputs, same outcome). See 11.

## 8. Future (not in v1)

Read-only reporting tools for managers (team hours, budget status), task creation/status update, resources such as `task://{id}`, OAuth instead of personal tokens. Each needs its own permission and threat review.
