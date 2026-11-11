# 00. Decisions log

Outcome of the requirements grilling (IDEA.md open questions plus two interview rounds). Everything here is **decided** unless labelled **Assumption**, which means I filled a gap and you should confirm it. Later documents reference these as `D-xx`.

## 1. People, roles, organization

| ID | Decision |
|---|---|
| D-01 | Roles: **Superadmin, Admin, HR, Finance, Employee, Assistant**. Roles are additive (a user may hold several). Permissions are designed first and roles composed from them (spatie/laravel-permission). |
| D-02 | **Admin = Team Leader = manager**. Admin also does the initial data entry (clients, projects, tasks, users). "Team Lead" is not a separate role. |
| D-03 | Each user has one `manager_id`. The chain is unbounded (employee, team lead, CTO, CEO). Approval and visibility rights derive from this relationship, not from a role. |
| D-04 | A manager sees their **whole subtree** (all direct and indirect reports): tasks, logs, leave, balances. Read-only. |
| D-05 | HR and Finance are **separate roles** (not folded into Admin). |
| D-06 | Admin and Superadmin create and assign tasks. Employees may change the status of their own assigned tasks only. |
| D-07 | Rates, money values and invoices are visible only to Superadmin, Admin and Finance. Assistant exports exclude money columns. |
| D-08 | Skill levels (scale 1-10) are set only by Admin/manager (not self-assessed). Skills are many-to-many with users. |
| D-09 | Assistant: manages lookups (departments, skills, areas), views and exports clients/projects/tasks/logs, requests own leave, **cannot log time**. |

## 2. Leave

| ID | Decision |
|---|---|
| D-10 | A request needs **both** the requester's manager and **any one HR user**. Order is irrelevant. A rejection (by either) ends the request and **requires a message**. The requester is notified. |
| D-11 | No manager set: HR approval alone suffices. Requester is HR: manager plus a different HR user (or Superadmin). The same person never counts for both approvals. |
| D-12 | Half days (AM/PM) are allowed. Each country has a configurable working week. Weekends and that country's holidays are excluded from the deducted days. |
| D-13 | Yearly lump-sum allowance (PTO and sick separately, per country), prorated for mid-year joiners. Per-user override allowed. |
| D-14 | Carry-over of unused PTO is capped per country and expires on 31 March. |
| D-15 | PTO cannot go negative unless HR explicitly approves the override. Sick leave is flagged when over allowance, never blocked. |
| D-16 | Logging time on a leave day or holiday is allowed with a warning. Approved leave never creates time logs. |
| D-17 | Superadmin (and HR) can record a direct day off for an employee, which deducts from PTO or sick leave. |
| D-18 | Holidays belong to a country and are not deducted. Everyone can see holidays of **all** countries on the calendar. |
| D-19 | Calendars: "My calendar" and "Team calendar" (managers see their subtree, HR sees everyone). |

## 3. Time logging

| ID | Decision |
|---|---|
| D-20 | Log fields: date, task, hours, description (required), hour type. Hours in 15-minute steps. Hard cap 24h per user per day. |
| D-21 | Logging only on tasks assigned to the user. Past dates are allowed. Done tasks are allowed. Cancelled tasks and Cancelled/Completed projects are not. |
| D-22 | Tasks may have several assignees. A task's area may change after hours are logged. Task estimate is per task, shared by all assignees. |
| D-23 | Hour types are an admin-managed lookup (seeded: Regular (default), Extra, Replacement). Each has `is_billable` and `client_rate_multiplier`. "Replacement" is a label only (no balance tracking). No approval for any type. |
| D-24 | A log snapshots area, hourly rate, currency, billable flag and multiplier **at the time it is written**. |
| D-25 | Logs are final in the sense of no approval workflow. The author may edit/delete until the month is **locked** (Finance manually, or automatically on day 5 of the next month). Admin/Superadmin may correct logs at any time, always audited. |
| D-26 | Colour-coded overdue and over-budget labels on tasks and projects: green below 80%, amber 80-100%, red above 100% (thresholds configurable). Alerts when something turns red. Overdue = past due date and not done. |

## 4. Money

| ID | Decision |
|---|---|
| D-30 | Currency is per client. A rate is always stored with its currency. No FX conversion. Reports group by currency. |
| D-31 | Rate history is kept: rates are versioned with an effective date, and each log snapshots the rate in force. |
| D-32 | Phase 1 scope includes a Finance billing report (client x period x area), month locking and CSV/XLSX export, **plus basic invoicing**. |
| D-33 | Invoice = one client and one period built from billable logs of a locked month, lines by project x area x hour type. Finance may edit lines, add manual lines, apply a discount, and change VAT. |
| D-34 | VAT: default per client, overridable per invoice, applied on the invoice total. One issuing company, details in settings. |
| D-35 | Lifecycle Draft, Issued, Paid / Overdue / Cancelled. Number `INV-YYYY-NNNN` assigned on issue. Due date from the payment plan. PDF download. Corrections by cancel and re-issue. No credit notes, no emailing from the app. |

## 5. Platform

| ID | Decision |
|---|---|
| D-40 | Stack: Laravel 13, Livewire, Alpine.js, Tailwind, Filament, MySQL, Redis, Supervisor. One Filament panel, permission-gated; employee screens are custom Livewire pages inside that panel. |
| D-41 | Invite-only. The welcome email carries a **one-time set-password link** (72h, resendable), not a plaintext password. No SSO. |
| D-42 | 2FA (TOTP) available to everyone and recommended after first login. Mandatory by default for Superadmin, Admin and Finance, **configurable** (can be disabled in settings). |
| D-43 | Notifications: in-app (Filament database notifications) plus email. |
| D-44 | UI English only; every label goes through `__()` so another language can be added later. Content is not translated. No translation-manager UI. |
| D-45 | Offboarding: deactivate, 30-day grace (Superadmin may reactivate), then scheduled anonymisation. Logs, leave, invoices and audit entries are kept. |
| D-46 | Everything is stored in **UTC** and displayed in the user's local timezone. |
| D-47 | No external API. A **MCP server** for coding agents is planned (see D-50). |
| D-48 | Dev on WAMP (Windows) and Herd (Mac); production Linux (Nginx, PHP-FPM, MySQL 8, Redis, Supervisor); staging plus production environments; GitHub Actions CI. |
| D-49 | Scale: 50-200 users, about 30 countries. Business data kept indefinitely (accounting retention about 10 years). Activity log pruned after 2 years. |

## 6. MCP

| ID | Decision |
|---|---|
| D-50 | Official `laravel/mcp`. Agents act **as the user** with a personal token (name, optional expiry, scopes, revocable), under the same policies as the UI, rate limited to 60 requests/min/token, and every call audited with source "mcp". |
| D-51 | v1 tools: list my tasks, show my task, log my time, list my logs, request PTO (enters the normal approval flow), hours logged today/this week/this month, count of my active tasks, my PTO and sick balances (available/used). |

## 7. Assumptions I made (please confirm)

| ID | Assumption | Why it matters |
|---|---|---|
| A-01 | "Stored in UTC" applies to **timestamps**. Calendar dates (log date, holiday date, leave dates) are stored as plain `DATE` values interpreted in the user's/country's local calendar. | Converting a pure date through UTC shifts it by a day for users east of UTC. |
| A-02 | Month lock is **company-wide** (one lock per calendar month), not per client. Auto-lock time uses a configurable company timezone. | Simplest model that matches how Finance exports. |
| A-03 | A log attached to an **issued or paid** invoice is immutable for everyone until the invoice is cancelled. This overrides "Admin can edit anytime" only for invoiced logs. | Prevents changing invoiced hours silently. |
| A-04 | Employees see only clients/projects they have an assigned task in. No money, no project-area estimates. | Matches "see only my tasks" while giving context. |
| A-05 | Admin (team lead) manages clients/projects/tasks and sees all work data (logs) globally. Leave approval and leave/balance visibility are limited to their manager subtree. (Revised.) | Data entry and work data are global, leave data is not. |
| A-06 | Cancelling approved leave: the requester files a cancellation request, and any HR user approves. Balance is restored on approval. | Keeps the workflow to one extra step. |
| A-07 | Countries/holidays/allowances are managed by HR and Superadmin. Payment plans are managed by Finance and Superadmin. Admin can read both. | Aligns with ownership of the data. |
| A-08 | PHP 8.4 baseline (several verified packages require it). | See 05-architecture. |
| A-09 | Time precision: stored as integer **minutes** (multiples of 15), displayed as hours. | Avoids float rounding in billing. |
| A-10 | Only Superadmin can grant the Superadmin, Admin, HR and Finance roles. Admin can create users and give Employee/Assistant. | Prevents privilege escalation. |
| A-11 | Future-dated logs are rejected (date compared in the user's timezone). | Prevents pre-logging. See 02. |
| A-12 | Joiner proration is by whole remaining months, rounded to 0.5 day. | Simple, explainable. See 02. |
| A-13 | Approval reminders every 3 working days. | See 02, 10. |
| A-14 | Expected day is 8h for reminders/indicators. | See 02. |
| A-15 | Invoice rounding is half-up at line level; totals are sums of lines. | See 02, 11. |
| A-16 | Financial audit events are retained beyond the 2-year prune. | See 02, 06. |
| A-17 | Draft projects block logging (only Active and On hold accept logs; Completed/Cancelled do not). | See 04. |
| A-18 | A log is rejected if no client rate is in force on its date. | See 04. |
| A-19 | Leave days are recomputed at approval time if a holiday was added after submission. | See 04. |
| A-20 to A-23 | Architecture choices (module layout, packages, Sentry, Horizon). | See 05. |
| A-24 | HR/Superadmin can reassign a pending approver. | See 06. |
| A-25 | Admin and Finance manage rates. | See 08. |
| A-26 to A-29 | MCP: token expiry default 90 days (max 1 year); Monday week start; PTO only via MCP; 200 `log_my_time` calls/day soft cap. | See 07. |
| A-30 | Sick notes visible to HR and the requester only. | See 08. |
| A-31 | Impersonation limited to 60 minutes. | See 08. |
| A-32 | Monthly impersonation report to Superadmins. | See 08. |
| A-33 | Load model: 200 users, about 600 logs/day, about 1.5M logs in 10 years, about 10 req/s peak. | See 10. |

Open point for confirmation: `laravel-lang/lang` is **deferred** (older releases had a malware-flag history), which departs from the earlier "keep" decision. Since the UI is English only, nothing is lost now.
