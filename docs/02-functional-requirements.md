# 02. Functional requirements

Conventions: ID format `FR-<MODULE>-NN`. Priority: **M** = Phase 1 must, **S** = should. Decision references (`D-xx`, `A-xx`) point to [00-decisions.md](00-decisions.md). "Manager" means the user's `manager_id` relationship; subtree means all direct and indirect reports. Acceptance criteria (AC) are written so each can become a Pest test.

Modules: AUTH, USR, ORG, CLI, PRJ, TASK, LOG, LOCK, LEAVE, CAL, BUD, BILL, INV, NOTIF, MCP, SET, AUD.

## FR-AUTH: Authentication and onboarding

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-AUTH-01 | Sign in with email and password. No public registration route exists. | GET/POST to any registration URL returns 404. Wrong credentials show a generic error. Throttled to 5 attempts/min per email+IP. | M |
| FR-AUTH-02 | Creating a user (Admin/Superadmin) sends a welcome email with a one-time set-password link valid 72h. No password is emailed. | Email contains a signed link only. After 72h the link is rejected. After use it cannot be reused. Admin can resend (invalidates the previous link). | M |
| FR-AUTH-03 | First login forces setting a password (policy: min 12 chars, breached-password check) and then offers enabling 2FA. | User with a pending-password flag is redirected to set-password from every route. After setting, a dismissible 2FA prompt appears. | M |
| FR-AUTH-04 | Optional TOTP 2FA with recovery codes for everyone. | Enabling requires confirming a code. Recovery codes work once each. Losing the device: Superadmin can reset 2FA, audited. | M |
| FR-AUTH-05 | 2FA mandatory for roles configured in settings (default Superadmin, Admin, Finance); can be disabled in settings (D-42). | With the setting on, such users without 2FA are redirected to the 2FA setup page. With it off, they are not. | M |
| FR-AUTH-06 | Password reset by emailed link. | Reset invalidates existing sessions of that user. | M |
| FR-AUTH-07 | Deactivated users cannot sign in and their sessions and tokens are revoked immediately. | After deactivation: web request returns to login, MCP token returns 401. | M |
| FR-AUTH-08 | Superadmin can impersonate any non-Superadmin user. | Banner shows during impersonation. Start and stop are audited with both user ids. Impersonating Superadmin or acting on 2FA/password settings is blocked. | M |

## FR-USR: Users and organization chart

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-USR-01 | Admin/Superadmin create and edit users: name, email, phone, department, country, timezone, manager, start date, roles (A-10). | Phone validated to E.164 (propaganistas/laravel-phone). Timezone defaults from country. Email unique. | M |
| FR-USR-02 | Manager assignment cannot create a cycle and a user cannot manage themselves. | Setting A's manager to a descendant of A is rejected. | M |
| FR-USR-03 | Employees can view a directory of all users (name, department, country, manager, skills). | Directory excludes email-sensitive and leave/balance data and rates. | M |
| FR-USR-04 | Admin/manager sets skill levels (1-10) per user. | Level outside 1-10 rejected. Only a manager in the chain, Admin or Superadmin can edit. | M |
| FR-USR-05 | Deactivate a user: account disabled, open tasks flagged "needs reassignment", manager notified. | Task list shows a flag for tasks whose only assignees are inactive. | M |
| FR-USR-06 | 30 days after deactivation a scheduled job anonymises the user (D-45). | Name becomes `Former employee #<id>`; email, phone, avatar, skills and 2FA data are cleared; logs, leave, invoices and audit rows remain and reference the anonymised user. | M |
| FR-USR-07 | Superadmin can reactivate within the 30 days; afterwards it is impossible. | Reactivation before the job restores access. After anonymisation the action is unavailable. | M |
| FR-USR-08 | Users edit their own profile (name, phone, timezone, avatar, password, 2FA). Not email, roles, manager, country. | Attempts to alter restricted fields via request tampering are ignored/rejected. | M |

## FR-ORG: Lookups and country rules

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-ORG-01 | CRUD for Departments, Skills, Areas by Admin, Assistant and Superadmin. | Cannot delete a record in use; it can be archived instead. | M |
| FR-ORG-02 | Countries (HR/Superadmin): name, ISO code, default timezone, PTO allowance days, sick allowance days, working weekdays, PTO carry-over cap. | Working weekdays: at least one day. Allowances >= 0, step 0.5. | M |
| FR-ORG-03 | Holidays (HR/Superadmin): country, date, name, optional half-day flag. | Unique (country, date). Holidays appear on all calendars (D-18). | M |
| FR-ORG-04 | Payment plans (Finance/Superadmin): name and due rule (prepayment, end of month, N days after invoice date). | Due-date computation is deterministic (see FR-INV-06). | M |
| FR-ORG-05 | Hour types (Admin/Superadmin): name, `is_billable`, `client_rate_multiplier`, default flag. | Exactly one default type. Multiplier > 0. Seeded: Regular, Extra, Replacement. | M |
| FR-ORG-06 | Changing a lookup used by past logs never rewrites past logs. | Editing an hour type's multiplier changes only future logs. | M |

## FR-CLI: Clients

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-CLI-01 | Create/edit clients (Admin/Superadmin): name, legal details, currency, payment plan, default VAT %, active flag. | Currency is ISO 4217. VAT 0-100. | M |
| FR-CLI-02 | Assign areas to a client with an hourly rate per area. | Rate > 0 with the client's currency. | M |
| FR-CLI-03 | Rates are versioned: a new rate has an effective-from date; old versions kept. | Rate in force for a date is deterministic. Editing in place is not possible, only adding a new version. Audited. | M |
| FR-CLI-04 | Deactivating a client prevents new projects/tasks/logs but keeps history. | Logging on an inactive client's task is rejected with a clear message. | M |
| FR-CLI-05 | Employees and Assistants never see rates; Assistant sees clients and projects read-only. | Rate fields are absent from responses for those roles (not merely hidden). | M |

## FR-PRJ: Projects

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-PRJ-01 | Create/edit projects: client, name, status, start/end date, description. | Statuses per D-26/16: Draft, Active, On hold, Completed, Cancelled. | M |
| FR-PRJ-02 | A project may only select areas that its client has. Each selected area has estimated hours. | Selecting a foreign area is rejected. Removing an area client-side does not break existing projects (project keeps it until removed there; flagged). | M |
| FR-PRJ-03 | Status transitions enforced. | Completed or Cancelled projects block new tasks and logs. Reopening Completed to Active is allowed for Admin/Superadmin. | M |
| FR-PRJ-04 | Project dashboard: per area estimated vs. logged hours with colour. | See FR-BUD. | M |

## FR-TASK: Tasks

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-TASK-01 | Create tasks (Admin/Superadmin): project, one of the project's areas, title, description, estimate hours, optional due date, one or more assignees, status. | Area must belong to the project. Assignees must be active. | M |
| FR-TASK-02 | Statuses: To do, In progress, In review, Done, Cancelled. Admin/Superadmin change any; assignee changes only their own tasks. | Employee cannot alter title, estimate or assignees. | M |
| FR-TASK-03 | Employees see only tasks assigned to them. Managers also see their subtree's tasks read-only. Assistants see all (read, export). | List queries are scoped in the database query, not in the UI. | M |
| FR-TASK-04 | Changing a task's area after logs exist is allowed; old logs keep their area snapshot. | Old log keeps area and rate; new logs use the new area's rate. | M |
| FR-TASK-05 | Removing an assignee who has logs on the task is allowed; their logs stay. | Logs remain visible and counted in totals. | M |
| FR-TASK-06 | Tasks can be filtered by project, client, assignee, status, area, due/overdue, and budget colour. | Filters combine with AND; the empty state is explained. | M |

## FR-LOG: Time logging

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-LOG-01 | An employee adds a log: date, task, hours, description, hour type. | Only tasks where the user is an assignee are selectable and accepted by the server. | M |
| FR-LOG-02 | Hours are multiples of 15 minutes, min 15 min. | 0.1h or 20 minutes rejected. Stored as integer minutes (A-09). | M |
| FR-LOG-03 | Daily cap: sum of a user's logs per date <= 24h. | Adding a log that would exceed the cap fails with remaining time shown. Concurrent inserts cannot jointly exceed it (transactional check). | M |
| FR-LOG-04 | Past dates allowed; future dates not allowed. (**Assumption** A-11: future dates are rejected.) | Date > today in user's timezone is rejected. | M |
| FR-LOG-05 | Allowed on Done tasks; rejected on Cancelled tasks, and on tasks of Draft/Cancelled/Completed projects (A-17) or inactive clients. | Each rejected case returns a specific message. | M |
| FR-LOG-06 | On save the system snapshots area, rate (version in force on the log date), currency, billable flag and multiplier (D-24). | After changing the client's rate, an old log still shows the old rate. The rate used is the one effective on the log's date. | M |
| FR-LOG-07 | If the date is a holiday for the user's country or an approved leave day, show a warning but allow saving (D-16). | Warning shown; log saved; flagged in reports. | M |
| FR-LOG-08 | Author may edit/delete own logs until the month is locked. | After the month is locked the action is denied for the author. | M |
| FR-LOG-09 | Admin/Superadmin may edit/delete any log at any time, except logs on an issued/paid invoice (A-03). Every change is recorded with old/new values. | Attempt on an invoiced log is rejected with the invoice number shown. | M |
| FR-LOG-10 | When an edit changes the task or date, the snapshot is recomputed. When only description/hour type changes, the rate snapshot stays unless hour type changes (then multiplier and billable flag refresh). | Covered by tests in 11. | M |
| FR-LOG-11 | "My logs" view: by day/week/month with totals, filters, and quick-add. | Totals equal the sum of rows. | M |
| FR-LOG-12 | Managers view subtree logs read-only; Finance views all (with money); Assistant views all (no money) and exports. | Money columns absent for roles without `timelogs.view-rates`. | M |

## FR-LOCK: Month locking

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-LOCK-01 | Finance can lock a month manually. | Lock records who and when. Creating/editing/deleting logs dated in that month by non-Admin is denied. | M |
| FR-LOCK-02 | A scheduled job auto-locks month M on day 5 of month M+1 (00:00 company timezone, A-02). | On the 5th, month M is locked even if nobody acted. Already locked months are untouched. | M |
| FR-LOCK-03 | Finance/Superadmin can unlock a month (reason required) as long as no issued invoice covers it. | Unlock is audited. Not possible if an issued/paid invoice exists for the month. | M |
| FR-LOCK-04 | Creating a log dated in a locked month by an employee is also denied (back-filling). | Employee gets a message to contact their Admin. | M |

## FR-LEAVE: Leave

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-LEAVE-01 | Every user (including Assistant and Admin) can request PTO or sick leave: type, start date, end date, optional AM/PM for first/last day, note. | End >= start. Half-day flags only on the first/last day. | M |
| FR-LEAVE-02 | Days are computed excluding non-working weekdays of the user's country and that country's holidays (D-12). Result stored on the request as `days`. | A Fri-Mon request in a Mon-Fri country spanning a Monday holiday counts 1 day. | M |
| FR-LEAVE-03 | Overlapping requests (non-rejected, non-cancelled) for the same user are rejected. | Any date overlap triggers a validation error. | M |
| FR-LEAVE-04 | The request creates two approval steps: manager and HR (D-10/D-11). Approvals independent of order. | Request is Approved only when both steps approved (or the single applicable step when no manager). | M |
| FR-LEAVE-05 | A step approver cannot be the requester and one person cannot satisfy both steps. | A user who is both manager and HR can approve one step only; the HR step needs a different HR user. | M |
| FR-LEAVE-06 | Rejection requires a message and ends the request. The requester is notified in-app and by email. | Empty message rejected. Status Rejected; balance untouched. | M |
| FR-LEAVE-07 | Balance handling: PTO that would go below zero shows a warning at request time. The HR approver must tick "allow negative" with a comment; without it HR can only reject. Sick leave beyond allowance is flagged, never blocked. | Approval without override fails when balance < days. Sick never fails. | M |
| FR-LEAVE-08 | On final approval the balance ledger records the deduction. | Ledger row created in the same transaction as the status change. | M |
| FR-LEAVE-09 | Requester can cancel a pending request. For an approved request they file a cancellation request that any HR user approves (A-06). | Balance restored when cancellation is approved. | M |
| FR-LEAVE-10 | HR/Superadmin can record a direct day off for any user (including one without platform access), PTO or sick, which is approved immediately and deducts the balance. | Direct entries are marked as such, with creator recorded. | M |
| FR-LEAVE-11 | Yearly allowance is credited on 1 January (country allowance or user override), prorated for joiners (by remaining days or months, **Assumption** A-12: by remaining whole months rounded to nearest 0.5 day). | New joiner on 1 July with 24 days gets 12. | M |
| FR-LEAVE-12 | Carry-over: unused PTO up to the country cap carries to the new year and expires on 31 March; deductions consume carry-over first. | Remaining carry-over is removed on 1 April by ledger entry. | M |
| FR-LEAVE-13 | Users see their own balances (available, used, pending, carry-over). Managers and HR see their subtree's/all balances. | Pending requests shown separately from used. | M |
| FR-LEAVE-14 | HR can adjust a balance (ledger entry with reason). | Adjustment cannot be deleted, only countered by another entry. | M |
| FR-LEAVE-15 | Approval reminders: pending steps older than 3 working days notify the approver again (**Assumption** A-13). | One reminder per 3 working days, max 3. | S |

## FR-CAL: Calendars

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-CAL-01 | "My calendar": own leave (status colours), holidays of own country, daily logged hours. | Days under 8h (**Assumption** A-14: expected hours = 8 on working days, configurable per country later) are marked incomplete. | M |
| FR-CAL-02 | "Team calendar": approved/pending leave of the viewer's subtree (HR and Superadmin: everyone). | A manager cannot see leave of non-reports. | M |
| FR-CAL-03 | Holidays of all countries are viewable, filterable by country (D-18). | Selecting another country displays its holidays only. | M |
| FR-CAL-04 | Calendar queries are range-bound (month view loads one month). | See 10. | M |

## FR-BUD: Budget and overdue indicators

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-BUD-01 | Colour for task and project-area: green < 80%, amber 80%-100%, red > 100% of estimate (thresholds configurable, D-26). | Boundary cases (exactly 80%, exactly 100%) are specified: 80% is amber, 100% is amber, 100.01% is red. | M |
| FR-BUD-02 | Task or project overdue: due/end date passed and status not Done/Completed/Cancelled. Shown as a distinct label. | Done tasks never show overdue. | M |
| FR-BUD-03 | When a task or project-area turns red, notify the assigned managers/Admins (in-app and email), once per transition. | No duplicate alerts while it stays red; a new alert after it drops below and exceeds again. | M |
| FR-BUD-04 | Tasks with no estimate show no budget colour. | Not red/green, shown as "no estimate". | M |
| FR-BUD-05 | Budget calculations include logs of all assignees and of snapshots with any hour type. | Totals reconcile with the log sum. | M |

## FR-BILL: Billing report

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-BILL-01 | Report (Finance/Admin/Superadmin): client x period, grouped by project x area x hour type: hours, rate, multiplier, amount, per currency. | Amount = minutes/60 x snapshot rate x multiplier, rounded half-up to 2 decimals per line (**Assumption** A-15). Non-billable types excluded from amounts but shown as hours. | M |
| FR-BILL-02 | Export CSV/XLSX asynchronously (queue) with download notification. | Large exports do not time out; file respects role (money columns for Finance/Admin/Superadmin only). | M |
| FR-BILL-03 | Report can be filtered to locked months only, and shows whether the period is locked and invoiced. | Draft report on an unlocked month displays a banner "not final". | M |
| FR-BILL-04 | Assistant and Employee cannot open the billing report. | Route returns 403. | M |
| FR-BILL-05 | Extra hours report per employee and month (for payroll), visible to HR and to managers for their subtree. | Totals by hour type. | S |

## FR-INV: Invoicing

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-INV-01 | Finance creates a draft invoice for a client and a locked month, from billable logs not yet on another non-cancelled invoice. | Lines generated per project x area x hour type; logs get `invoice_id` set. A log cannot appear on two active invoices. | M |
| FR-INV-02 | Draft is editable: line description, hours, rate; add manual lines (fixed fee) ; discount (percent or amount); VAT %. | Totals recalculate; edits to generated lines are audited. | M |
| FR-INV-03 | Default VAT from the client, overridable on the invoice; VAT applies to net total after discount. | Example: net 1000, discount 10%, VAT 20% = 1080.00. | M |
| FR-INV-04 | Issue: assigns number `INV-YYYY-NNNN`, freezes content, stores issuer legal details snapshot. | Number is sequential per year without gaps or duplicates under concurrency. Frozen invoice cannot be edited. | M |
| FR-INV-05 | PDF is generated and downloadable by Finance/Admin/Superadmin. | PDF reflects the frozen content exactly (regenerated from snapshot data). | M |
| FR-INV-06 | Due date = issue date plus plan rule (prepayment: issue date; end of month: last day of the issue month; N days: issue + N). | Boundary: 15 days after 20 Jan = 4 Feb. | M |
| FR-INV-07 | Status transitions: Draft, Issued, Paid, Overdue (automatic when due date passes and not Paid), Cancelled. | Overdue computed by scheduled job; marking Paid clears Overdue. | M |
| FR-INV-08 | Cancel an Issued/Paid invoice (reason required): releases its logs; number is not reused; fix by re-issue. | Cancelled invoice stays visible and numbered. | M |
| FR-INV-09 | Manual invoice not tied to logs (e.g. prepayment) is possible. | Created with only manual lines. | M |
| FR-INV-10 | Invoices are visible only to roles with `invoices.view` (Finance, Admin, Superadmin). | Others get 403. | M |

## FR-NOTIF: Notifications

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-NOTIF-01 | In-app (Filament database notifications) and email for: welcome, leave submitted/approved/rejected/cancelled, approval reminder, budget red, export ready, invoice overdue, month locked. | Each event sends both channels; users can mute emails per category in profile except security emails. | M |
| FR-NOTIF-02 | Notifications are queued. | Failure to send email does not roll back business transactions. | M |

## FR-MCP: MCP server for coding agents

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-MCP-01 | Users create/revoke personal tokens on their profile with name, expiry (optional) and scopes (`read`, `log-time`, `leave-request`). | Token is shown once. Revoked token fails immediately. | M |
| FR-MCP-02 | Tools (D-51): `list_my_tasks`, `show_my_task`, `log_my_time`, `list_my_logs`, `request_pto`, `hours_summary` (today/week/month), `count_active_tasks`, `my_leave_balances`. | Each tool is authorised by scope and by the same policy as the UI. | M |
| FR-MCP-03 | Agents act as the user; they can never access other users' data or admin functions. | Cross-user ids return not found. | M |
| FR-MCP-04 | Rate limit 60 req/min/token; every call is written to the activity log with source `mcp` and token name. | 61st call in a minute returns 429. | M |
| FR-MCP-05 | `log_my_time` enforces every FR-LOG rule (steps, cap, lock, assignment) and returns actionable errors. | Same validation results as UI. | M |
| FR-MCP-06 | `request_pto` enters the normal approval flow and notifies approvers. | Request appears with source `mcp`. | M |

## FR-SET: Settings

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-SET-01 | Superadmin edits settings: company legal details and logo, company timezone, budget thresholds, auto-lock day, 2FA mandatory roles, session lifetime, reminder cadence, invoice number format prefix. | Invalid values rejected; changes audited. | M |

## FR-AUD: Audit

| ID | Requirement | Acceptance criteria | P |
|---|---|---|---|
| FR-AUD-01 | Activity log for create/update/delete of: users, roles, clients, rates, projects, tasks, logs, leave, balances, invoices, month locks, settings, impersonation, tokens. | Each row: actor, subject, event, old/new values, timestamp (UTC), source (web/mcp/system). | M |
| FR-AUD-02 | Audit log viewable by Superadmin; filterable; immutable from the UI. | No edit or delete UI. | M |
| FR-AUD-03 | Activity log is pruned after 2 years, except financial events which are retained for the legal period (**Assumption** A-16). | Prune job skips invoice and rate events. | M |

## Traceability: decisions to requirements

| Decisions | Requirements |
|---|---|
| D-01..D-09 | FR-USR, FR-TASK, FR-CLI-05, 08-security |
| D-10..D-19 | FR-LEAVE, FR-CAL, FR-ORG-02/03 |
| D-20..D-26 | FR-LOG, FR-LOCK, FR-BUD |
| D-30..D-35 | FR-CLI-02/03, FR-BILL, FR-INV |
| D-40..D-49 | FR-AUTH, FR-NOTIF, FR-USR-05..07, FR-SET, NFR |
| D-50, D-51 | FR-MCP |
