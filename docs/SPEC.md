# task-logger — Agent-Ready Specification

*Restructured from the owner’s original brief for use by coding agents. Nothing from the source has been dropped. Anything that is not literally in the source carries a tag so it can be reviewed.*

## 0. Read this first

### 0.1 Tag legend

- **No tag** — stated in the source brief.
- `[DERIVED]` — not stated, but logically required to implement something that is.
- `[ASSUMED D-xx]` — default chosen where the source is ambiguous, contradictory or silent. Implement it, keep it isolated (config, enum, policy or one class) and expect it may change. D-xx points to the Decision Log in section 3.
- `[OPTIONAL]` — source wording is soft (can, maybe, possibly). Build after the core milestones.
- `[PROPOSED]` — a process suggestion, not product behaviour.

### 0.2 Agent operating rules

1. Complete the Phase 0 deliverables (section 10) before writing application code. Store them under `docs/`.
2. Permission-first: define permissions, then roles as bundles of permissions. Application code checks permissions through Policies and Gates, never role names.
3. Do not invent fields, enum values or behaviour. If something is missing, write an ADR in `docs/adr/` and extend the Decision Log. Do not pick silently.
4. Money is integer minor units plus an ISO 4217 currency. Never floats.
5. Names in the source are examples. Use Laravel conventions (plural snake\_case tables, singular models, `{model}_id` foreign keys, backed enums). The original name is kept in parentheses where a field was renamed.
6. Every business rule (BR-xx, section 6) needs at least one automated test that cites its ID.
7. Every create, update and delete on a business model is written to the activity log.
8. Source typos are normalised: marital, spouse, planning, recurring, status, offboardings, Tirane.
9. Verify package names and version compatibility on Packagist and in the Filament docs before installing. Do not trust names from memory.
10. A decision marked Confirm: yes must not block you. Implement the stated default and continue.

## 1. Product overview

### 1.1 Summary

task-logger is a web application where employees of a company manually log working hours against tasks (or directly against eligible projects). Admins manage clients, projects and tasks. The superadmin manages master data, people and leave. Beyond time logging the app covers employee HR data, leave (PTO and sick leave) with country-specific rules and bank holidays, performance reviews, offboarding and optional expenses.

Source scope sentence: users, user skills, roles and permissions, clients, projects, tasks, logs, PTO request management, etc.

### 1.2 Business goals

- Record who worked how long, on which project, task and technology, and of which type (normal, extra, replacement, other).
- Compare estimated vs logged hours per project and technology, and value logged work at the client hourly rate that applied when it was logged.
- Manage employees across countries: national holidays, leave entitlements, currencies.
- Keep an audit trail. Retain business history after an employee leaves and anonymise personal data after a retention period.

### 1.3 Non-goals for v1 (all stated)

- Invoicing. Payment plans are client metadata only (maybe V2).
- Multiple languages. English only for the first release.
- Manager approval of time logs. Not required.
- Automatic or timer-based logging. Logging is manual.
- Multiple assignees per task. One assignee only.

### 1.4 Actors

`superadmin`, `admin`, `hr` (D-06), `assistant`, `employee`, `finance`. Details in section 4.

## 2. Stack and infrastructure

### 2.1 Stack

| Layer | Choice | Notes |
| --- | --- | --- |
| Backend | Laravel | Latest stable at project start. PHP version per Laravel requirement. Pin in composer.json. |
| UI | Livewire, Alpine.js, Tailwind CSS | The TALL stack. |
| Admin panel | Filament | Source says an admin panel generator like Filament. Use the latest stable major that supports the chosen Laravel and check every plugin for compatibility. |
| Database | MySQL | InnoDB, utf8mb4. |
| Cache, queue, session | Redis | `[DERIVED]` Source lists Redis without a role. |
| Process manager | Supervisor | `[DERIVED]` Keeps queue workers (and the scheduler, or Horizon if adopted) running. |

### 2.2 Scheduled and queued work `[DERIVED]`

- Annual leave grant on 1 January in each country’s timezone (BR-18).
- Move approved leave to `taken` after its end date (BR-19).
- Anonymise personal data 2 months after the offboarding end date (BR-28).
- Queued exports (BR-33).
- Remind the assignee before a task is due (D-81).
- Notifications to employees on the final leave decision (D-57). `[OPTIONAL]` Notifications to approvers on leave submission.

### 2.3 Packages

The source says: packages that can be used but not limited to, not all required; other well-maintained, up-to-date packages are allowed.

| Source name | Package to install (verify) | Purpose here | Scope |
| --- | --- | --- | --- |
| barryvdh/laravel-debugbar | `barryvdh/laravel-debugbar` | Request and query debugging | dev only |
| lab404/laravel-impersonate | `lab404/laravel-impersonate` | Superadmin acts as a user for support (D-20) | `[OPTIONAL]` |
| cybercog/laravel-ban | `cybercog/laravel-ban` | Ban or deactivate users (D-20) | prod |
| larastan/larastan | `larastan/larastan` | Static analysis | dev, CI |
| barryvdh/laravel-ide-helper | `barryvdh/laravel-ide-helper` | IDE metadata | dev only |
| propaganistas/laravel-phone | `propaganistas/laravel-phone` | Validate and normalise phone numbers to E.164 | prod |
| spatie/laravel-activitylog | `spatie/laravel-activitylog` | The ActivityLog entity and audit trail | prod |
| spatie/laravel-permission | `spatie/laravel-permission` | Roles and permissions, permission-first | prod |
| spatie/laravel-sluggable | `spatie/laravel-sluggable` | Slugs for projects and departments | prod |
| spatie/laravel-settings | `spatie/laravel-settings` | App settings: carry-over toggle, retention period | prod |
| spatie/laravel-navigation | `spatie/laravel-navigation` | Navigation | not installed (D-27) |
| Filament Admin | `filament/filament` | Admin panel | prod |

The source misspelled three of these names (`404labfr/laravel-impersonate`, `nomaduro/larastan`, `spatie/laravel-permissions`); the first column shows the corrected names.

## 3. Decision log

Each entry: what the source says, the default this spec implements, and whether the owner should confirm it. Section 3.2 lists the confirmations that matter most.

### 3.1 Decisions

**D-01 — Area and Skill become one `technologies` table.**

- Source: Area and Skill are separate entities. The FAQ says they can be merged into one technologies table: users link with a level, clients with a rate, projects with estimated hours.
- Default: one `technologies` table with pivots `technology_user` (level), `client_technology_rates` (rate, effective\_from) and `project_technology` (estimated\_hours). The UI label is Technologies. Level scale 1–10 (the source examples use 7–10).
- Confirm: yes. Keeping two tables would only change names.

**D-02 — Priority lives on the client only.**

- Source: Project lists `priority`. The FAQ says priority is per client rather than per project.
- Default: `customers.priority` only. No `projects.priority`. Task priority is unaffected.
- Confirm: yes.

**D-03 — Salary and leave balance are not columns on users.**

- Source: User lists `salary` and `total_days_off`. The same brief keeps salaries in a separate history table and defines leave by country policy with proration.
- Default: no `users.salary` (use `salaries`). No `users.total_days_off` (use the leave ledger, BR-18).
- Confirm: no.

**D-04 — Project budget is computed.**

- Source: Project has `budget` and `estimated_hours`. The FAQ says the budget is based on hours and rate.
- Default: `estimated_hours` = sum of `project_technology.estimated_hours`. Budget = sum of (estimated\_hours × client rate effective on the project start\_date), stored as an integer snapshot in the client currency and recomputed whenever technologies, estimates, rates or fixed costs change. Fixed costs are included (owner answer): budget = hours × rate plus the sum of the project’s fixed costs. Fixed costs must use the client’s currency.
- Confirm: fixed costs are included (owner answer). The rate date (project start\_date) is still the default; confirm if another date applies.

**D-05 — What an employee may see.**

- Source: employees see only their tasks and logs, but also can view tasks, logs, projects and customers. Employees can see all employees. The FAQ splits a public profile from a private HR record.
- Default: own tasks and own logs only. All projects and customers read-only. Colleagues’ public profile only. Hourly rates, payment plan and budget are hidden from everyone except superadmin and admin (permission `clients.view_rates`; D-91 shows budgets to finance on the Financials page only). Assistant exports omit those columns.
- Confirm: yes.

**D-06 — HR is a role.**

- Source: HR is an admin in the HR department, or a new role `hr`.
- Default: new role `hr`. The HR department stays plain organisational data.
- Confirm: yes.

**D-07 — Leave approval chain.**

- Source: leave is approved by the direct manager and HR. LeaveRequest has a single reviewer. Superadmin can approve.
- Default: two stages, direct manager and HR (in any order, D-85), recorded in `leave_request_approvals`. The request becomes `approved` when both approve. Any rejection sets `rejected` and requires a note. Superadmin may decide at any stage; a manager who is also HR completes both stages with one approval (D-86). A requester with no manager skips the manager stage. `reviewed_by`, `reviewed_at`, `review_note` on `leave_requests` hold the final decision. Who the approver is, who is told and the superadmin's own leave: D-70.
- Confirm: no. The owner confirmed that both the direct manager and HR must approve.

**D-08 — Leave policy vocabulary.**

- Source: country policy with effective dates, confirmed entitlement, proration, carry-over and expiry. In some countries holidays exclude weekends and are carried over. Later: grant on 1 January prorated by start date, no carry-over (setting).
- Default: carry-over of leave between years is a per-country policy option, off by default (this matches no carry-over as a setting). A bank holiday on a weekend is observed on the next working day where the country is set to move weekend holidays (D-62, which replaced the earlier owner answer that HR adds the observed Monday as its own entry). Confirmed entitlement means a grant entry written to the ledger after the policy is applied. The working week defaults to Monday–Friday and is configurable per country.
- Confirm: no for the weekend-holiday question (answered by the owner, then replaced by D-62). Leave carry-over between years stays off unless a country policy turns it on.

**D-09 — One role per user.**

- Source: User has a `role`.
- Default: exactly one Spatie role per user, enforced in the application. Every role inherits the employee baseline permissions.
- Confirm: yes.

**D-10 — Permission defaults.**

- Source: the role lists are partial. Nothing is said for hr, salaries, offboarding, labels, expenses, settings. The assistant can add master data but nothing about editing it.
- Default: unspecified means denied. Owner answers: hr, admin and superadmin manage the private HR record, salaries, offboardings, expenses and settings; HR can also create bank holidays; an assignee can change the status of their own tasks. Superadmin holds every permission, assigned explicitly by the seeder so the grid stays the single source of truth. (Amended by D-41: the superadmin additionally has root access through a before-gate.) Assistant gets create only on master data.
- Superseded in part: settings are superadmin only (D-64).
- Confirm: partly answered. Still unspecified and therefore denied: labels (superadmin only), the assistant editing master data, and employee access to expenses.

**D-11 — Log data model.**

- Source: Log has `hours`, `minutes`, `date` (created\_at may serve), `project_id`, `task_id`. The FAQ adds a rate snapshot per entry and a month lock.
- Default: `duration_minutes` (the UI accepts hours and minutes). An explicit work `date` (backdating allowed until the month is locked). `technology_id` (from the task, or chosen for direct logs). `rate_minor` and `rate_currency` snapshot.
- Confirm: yes. Is backdating allowed?

**D-12 — Project membership.**

- Source: only assigned project members can log directly to a project. No membership entity is defined.
- Default: `project_user` pivot managed by superadmin and admin. Task assignment does not add a member automatically.
- Confirm: yes.

**D-13 — Timesheet lock.**

- Source (FAQ): No timesheet lock? Superadmin locks a month; edits to locked entries need an admin and are activity-logged. Read as: a lock exists.
- Default: `timesheet_locks` per calendar month, global. Employees cannot create, edit or delete logs dated in a locked month. Admin or superadmin can, and each change is logged with before and after values. Superadmin can unlock (logged).
- Confirm: yes.

**D-14 — Privacy split.**

- Source: public profile = name, role, department, skills, avatar. Private HR record is policy-guarded, superadmin only.
- Default: Directory shows name, surname, position, department, skills, avatar. Everything else is the private HR record, enforced per field: email, phone, date of birth, gender, address, education, marital status, emergency contacts, source of hire, health insurance, employment type, start date, manager, country, description, metadata. Superadmin, admin and hr can read and edit it (owner answer: the HR record is managed by HR, admin and superadmin; this widens the superadmin-only FAQ line).
- Confirm: yes. Should the employee see their own record? Should email be public?

**D-15 — Anonymisation.**

- Source: retain business and audit history, anonymise email, phone and any other personal data after a specific period.
- Default: setting `anonymize_after_months` in spatie/laravel-settings, default 2 (owner answer: personal data such as name, email and phone is kept for 2 months, then anonymised). Trigger: offboarding `end_date` plus 2 months. Replace name with `Former employee #id`, email with `anonymized-{id}@invalid.example`, clear private profile, emergency contacts, avatar and metadata, and scrub personal values in activity log properties. Keep ids, logs, leave, salary and performance rows. Set `users.anonymized_at`.
- Confirm: no for the period. Still open: whether the 2 months start at the offboarding end date (the default) and whether salary and performance rows are retained (default: retained).

**D-16 — Filament panel layout.**

- Source: TALL plus Filament. Employees use the app too.
- Default: one Filament panel for all roles, visibility via policies. Custom pages: My Day (daily log form with pinned tasks) and Leave Calendar (since 2026-11-14 the Calendar tab of Leave Requests).
- Confirm: no. Record as an ADR in Phase 0.

**D-17 — Expenses are optional and unscoped.**

- Source: we can also add employee expenses. No role capabilities are defined.
- Default: `[OPTIONAL]`, last milestone. Managed by hr, admin and superadmin (owner answer). Employees have no expense access until defined. One currency per expense (lines carry a currency column, validated equal).
- Confirm: answered by the owner on 2026-11-12: finance and superadmin only (D-51); expenses ship.

**D-18 — Customer country and currency.**

- Source: customer has `country`. Money is stored in ISO currency per client. Currency is based on country.
- Default: `customers.country_id` foreign key. `currency_code` defaults to the country currency and is fixed once rates, fixed costs or logs exist (fixed costs must use the client's currency, BR-14).
- Confirm: answered by the owner on 2026-11-27: keep the lock.

**D-19 — Enumerations with unstated values.**

- Source: project\_type (enum or FK, with etc.), gender, marital status, source of hire, task statuses and labels (with etc.), leave types, salary status, offboarding status.
- Default: PHP backed string enums. Values are listed in section 5. Leave types and labels are lookup tables seeded with the source values and extensible. Source of hire is a free string.
- Confirm: yes.

**D-20 — Impersonation and ban.**

- Source: packages listed, behaviour undefined.
- Default: impersonation is `[OPTIONAL]`, superadmin only, logged. Ban or deactivation through `cybercog/laravel-ban` when offboarding completes or an admin deactivates a user.
- Confirm: yes.

**D-21 — Performance review authorship vs visibility.**

- Source: reviews are filled by admin or superadmin. They are visible only to superadmin, HR and the managing person.
- Default: create — superadmin and admin. View — superadmin, hr and the employee’s direct manager. An admin who is not the manager can create but not read back. Implemented literally.
- Confirm: yes. Should the author be able to view what they wrote? Should HR create?

**D-22 — Scale and API surface.**

- Source: the workflow asks for API design and load estimates. No numbers are given.
- Default: v1 has no public REST API (Livewire and Filament only). Define internal contracts (Actions, DTOs). In Phase 0 the agent writes explicit capacity assumptions (headcount, clients, logs per day) for the owner to confirm.
- Confirm: yes.

**D-23 — Duplicates merged (no confirmation needed).**

- Project `client` and `customer_id` are one field: `customer_id`.
- `customer` and `client` are synonyms: table `customers`, UI label Clients.
- Project `deadline (start_date - end_date)` becomes two date columns.
- Employee actions request PTO and create a request for days off are the same leave request flow.
- Employee `role` and the Spatie role are the same thing.

**D-24 — SPEC.md is authoritative (ADR-0003).**

- Default: this spec wins. The earlier draft was removed from the repository in ERL-8 and is only in git history. Conflicts are listed in `docs/00-business-requirements.md` §7.
- Confirm: yes. Is invoicing really out, and are multiple assignees really out?

**D-25 — MCP and public API deferred (ADR-0004).**

- Source: Add a MCP for coding agents important; D-22 says no public API in v1.
- Default: no API or MCP in v1. Actions and DTOs stay transport-agnostic.
- Confirm: yes.

**D-26 — Authentication and two-factor (ADR-0005). Decided by the owner.**

- Default: email and password, no self-registration, password reset. TOTP two-factor authentication through an authenticator app. The superadmin switches it on or off globally, can require it for all users, and sets a grace period (default 14 days, in config, changeable on the settings page). After the grace period users without 2FA must enrol before using the app. New permission `two_factor.manage`, superadmin only. SSO out of v1.
- Confirm: no. Only the grace-period start rule is assumed (from when the requirement is switched on, or from the user's creation if later).

**D-27 — Navigation (ADR-0006).**

- Default: Filament navigation. `spatie/laravel-navigation` is not installed (last release 2024-09-20, no Laravel 13 declaration).
- Confirm: no.

**D-28 — Manager authority by relationship (ADR-0007).**

- Default: manager-stage approval needs `leave_requests.approve_direct_reports` and `manager_id` equal to the actor. Direct manager reads performance reviews by relationship (D-21).
- Confirm: yes. May an employee-role user who manages others approve the manager stage?

**D-29 — Offboarding deactivates login as a system effect (ADR-0008).**

- Default: `CompleteOffboarding` bans the user under `offboardings.manage`; `employees.deactivate` stays superadmin-only.
- Confirm: no.

**D-30 — HR record visibility follows the grid (ADR-0009).**

- Default: superadmin, admin and hr (matches D-14). Section 8 wording "for superadmin" is superseded.
- Confirm: no.

**D-31 — Salary statuses (refines D-19).**

- Source: salary `status` is pending, active or superseded; the current salary is the latest active row on or before today. The values and transitions are not defined.
- Default: after every insert the history is settled: rows dated in the future are `pending`; of the rest the latest is `active` and every earlier one `superseded`. A daily job activates pending rows when their date arrives. A back-dated row goes to history and never replaces the current salary. Only `status` changes by itself; the amount, currency, date and note change only through a correction (D-83).
- Confirm: yes.

**D-32 — The last superadmin is protected `[DERIVED]`.**

- Default: the last active superadmin cannot be demoted or deactivated, and nobody can deactivate themselves.
- Confirm: no.

**D-33 — Self-service profile is read-only for name and email (refines D-14).**

- Default: on their own Profile page a user changes their password and two-factor setup; name and email are shown but only the superadmin changes them (they are HR data). Users have no right over their own HR record.
- Confirm: yes. Should employees edit their own name, phone or emergency contacts?
- Superseded by D-77 (2026-11-22): the owner allows self-editing of the listed fields.

**D-34 — Minor units follow ISO 4217, not ICU.**

- Source: money is integer minor units plus an ISO 4217 currency (BR-12).
- Default: the number of decimals comes from an ISO 4217 table in code (ALL 2, EUR 2, JPY 0, KWD 3, CLF 4). ICU reports the Albanian lek with 0 decimals, which contradicts ISO and would misread every ALL amount.
- Confirm: no.

**D-35 — What cannot change after creation `[ASSUMED]`.**

- Source: silent. Rates, budgets and (from M4) logs are tied to a project's client and to a task's project.
- Default: the client of an existing project and the project of an existing task cannot change. A task's technology can change within its project. Clients are never hard-deleted (deactivate them, BR-31).
- Confirm: yes.

**D-36 — Budget needs a rate on the start date; rounding `[ASSUMED]`.**

- Source: D-04 prices hours at the client rate effective on the project start date; it does not say what happens when there is none.
- Default: a technology can only be added to a project if the client has a rate for it on the start date, and any change (rate, technology, estimate, start date, fixed cost) that would leave a technology without one is refused and rolled back. Each technology line rounds half up to the minor unit. Estimated hours are decimals with at most 2 digits.
- Confirm: yes.

**D-37 — Delete guards `[DERIVED]`.**

- Default: a project that still has tasks cannot be deleted (cancel it instead); a task with sub-tasks cannot be deleted; a technology used by a task cannot be removed from its project. Deletes are soft. End date is optional (recurring and pay-as-you-go projects); start date is required because it prices the budget.
- Confirm: no.

**D-38 — Audit context: IP, user agent and impersonator.**

- Source: BR-32 requires actor, subject and before/after values. Impersonation is `[OPTIONAL]` (D-20): a superadmin acting as a user for support. The owner asked that the real person be recorded.
- Default: every activity entry also stores `client_ip`, `user_agent` and `impersonator_id` (the person who is really acting; the causer stays the user being acted as). They are read from the HTTP request and the session key `impersonated_by` (the default of `lab404/laravel-impersonate`, configurable in `config/task-logger.php`). Outside a request (console, queued jobs) they are null. A stale or forged impersonator id is ignored so it can never break an audited write. IP address and user agent are personal data and are cleared on anonymisation (BR-28).
- Confirm: no. Open: should queued jobs (exports) carry the originating IP and impersonator?

**D-39 — Logging rules the spec leaves open `[ASSUMED]`.**

- Source: D-11 allows back-dating until the month is locked; BR-07 lets admin and superadmin change logs in a locked month; the grid has no permission to edit someone else's log.
- Default: no log can be dated in the future (judged on the person's own calendar day, BR-34). Any task assigned to you is a valid target (BR-02), and the screens offer your open tasks plus the one on the log being edited. A description is optional. `logs.edit_locked` holders can create their own logs in a locked month and correct anyone's logs there (month-close corrections); nobody can edit another person's log in an open month, because no permission grants it. Every change in a locked month is also recorded as a `locked_month_create`, `locked_month_edit` or `locked_month_delete` activity with the month and before/after values.
- Confirm: yes. Should admin be able to edit other people's logs in open months (needs a new permission)? Should closed or cancelled tasks accept logs from the screen?

**D-40 — Export format and retention.**

- Source: BR-33 says exports run on the queue and respect permissions and field visibility. Format and retention are not stated.
- Default: CSV (UTF-8 with byte order mark so Excel reads names), written to the private disk, downloadable only by the requester through the Exports screen for 7 days, then deleted by a daily job. Rate, amount, budget, currency and payment-plan columns need `clients.view_rates`. Cells starting with `=`, `+`, `-` or `@` get a leading apostrophe against spreadsheet formula injection. The permission is checked again when the job runs. The originating IP and impersonator travel with the job into the audit entry (D-38). Admin cannot export (the grid gives export to superadmin and assistant only).
- Confirm: no. Is XLSX needed as well?

**D-41 — Superadmin root access through a before-gate. Decided by the owner (2026-11-12).**

- Source: D-10 said the superadmin holds every permission explicitly and not through `Gate::before`. The owner asked for a before-gate so the superadmin can always edit everything (for example any user's logs).
- Default: a new permission `system.root`, held only by the superadmin role, makes a `Gate::before` grant every ability. It is keyed on the permission, not on a role name, so authorisation stays permission-first. The explicit grid is kept (the superadmin still holds every permission) and is still the source of truth for every other role. Data rules still apply to root: a root edit of a log must still satisfy BR-02, BR-04 and BR-06.
- Not overridden by root, because the spec makes them impossible on purpose: the audit trail is read-only (BR-32); salary rows are never deleted (BR-29; a correction is allowed, D-83); users and clients are never hard-deleted (SPEC 5.0, BR-31); an export file belongs to the person who requested it (BR-33). Root falls through to the policy for those and is denied.
- Consequence for D-39: the superadmin can now edit and delete any user's log in any month. Admin still cannot edit others' logs in an open month.
- Confirm: yes. Should root also be able to change the immutable items above (for example delete audit entries)? Not recommended.

**D-42 — Leave day-counting details the spec leaves open `[ASSUMED]`.**

- Source: BR-16, BR-17 and the half-day enum `full`, `am`, `pm`.
- Default: the part describes which part of that date is on leave. A request that starts `pm` leaves at noon on the first day, one that ends `am` returns at noon on the last day, every date in between is a full day. A single-day request carries one part (the end part is ignored). A request with no working day in it (for example a weekend) is refused. A request may cover at most 366 calendar days (`task-logger.leave_max_request_days`), a guard against a wrong year. Overlap (BR-23) is checked per half day, so a morning and an afternoon request on one date do not clash; `taken` requests count as active like `approved` ones.
- Confirm: no.

**D-43 — Approval order and the ledger `[ASSUMED]`.**

- Source: D-07, BR-18, BR-19, SPEC 5.5 ("balance = sum of non-expired days").
- Default: the manager and HR each decide their own stage, for everybody including the superadmin ("any stage" means either stage), except that a direct manager who also holds the HR stage completes both with one approval (D-86). **In any order since D-85; the manager-first order of 2026-11-22 is gone.** The manager stage is required only when the employee has a manager when the decision is made. The deduction is written on final approval, dated the first day of the leave, and a cancellation writes a reversal. The balance is the plain sum of the ledger: expiry is itself a negative entry. On the new year (country timezone) last year's unused balance expires through one `expiry` entry and, when the country policy enables carry-over, a `carry_over` entry puts back up to the policy maximum; carried days that are still unused after `carry_over_expires_after_months` expire through a further `expiry` entry, counting days used since the carry-over against the carried part first. The ledger has no manual `adjustment` writer in v1 (no permission for it in the grid).
- Confirm: no.

**D-44 — Who cancels and who sees leave. Decided by the owner (2026-11-12).**

- Source: the grid has no cancel permission and no permission for a manager to see their reports' requests.
- Default: a person cancels the requests they made for themselves while pending or approved (not taken). HR (`leave_requests.approve`) and the superadmin (`leave_requests.create_for_others`) cancel any request, including an approved one or a direct day off, **until it is taken; from the first day on only the superadmin cancels (D-71)**; a cancelled approved request writes a reversal. A direct manager sees their reports' requests through the `manager_id` relationship plus `leave_requests.approve_direct_reports` (D-28); `view_any` is superadmin and HR as in the grid. The Leave Calendar (the Calendar tab of Leave Requests) shows everybody's approved and taken leave to everybody (colleagues see each other's leave) and pending requests only to those who may see the request; holidays are the viewer's own country's (every country for people who see all leave).
- Confirm: no.

**D-45 — Offboarding transitions and review immutability `[ASSUMED]`.**

- Source: D-19 lists the offboarding statuses `pending`, `in_progress`, `completed`, `cancelled`; W-10 says completion deactivates the login; BR-27 restricts reading reviews. No transition rules or edit rules are given.
- Default: `pending` moves to `in_progress`, `completed` or `cancelled`; `in_progress` to `completed` or `cancelled`; `completed` and `cancelled` are final and the facts of a final offboarding cannot be edited. An offboarding may be created `pending`, `in_progress` or `completed` (completing at creation deactivates the login straight away) but not `cancelled`. Completing is refused for yourself and for the last superadmin, like deactivating (D-32), and while the person has direct reports (D-74); it is atomic with the ban. Offboardings are cancelled, never deleted. Performance reviews are never edited or deleted. Root access does not change either rule (RootAccess `POLICY_ONLY`). A review's date has no limit and the reviewer may be anyone holding `performances.create`.
- Confirm: no.

**D-46 — What anonymisation does exactly `[ASSUMED]`.**

- Source: D-15 and BR-28 (replace name and email, clear profile, contacts, avatar and metadata, scrub the activity log, keep logs, leave, salary and performance rows).
- Default: the clock starts on a `completed` offboarding only: `end_date` plus `anonymize_after_months` (months added without overflow, compared with today in the person's country timezone). Someone whose login was reactivated is never anonymised. The user becomes `Former employee #id` with no surname, email `anonymized-{id}@invalid.example`, a random password, no avatar (the file is deleted), no 2FA secrets, no sessions and no password-reset tokens. The `employee_profiles` row is kept with every field emptied; emergency contacts are deleted. The position, department, manager, skills and country stay (they are not personal data and keep reports meaningful). In the activity log the entries about the user, their profile and their contacts keep their place but lose their before/after values and properties, and the IP address and user agent are cleared on entries the person caused. Writing this happens without model events so the old values are not logged again; one `anonymized` entry is added. The job is idempotent and runs daily.
- Confirm: yes. Should free-text fields written by others about the person (leave reasons, task and log descriptions, review notes) be scrubbed too? The default keeps them: they are business records, and the rows are kept by BR-28.

**D-47 — Impersonation `[OPTIONAL]` (M6). Requested by the owner (2026-11-12).**

- Source: D-38 already records `impersonator_id` on every audit entry.
- Default: the superadmin (`employees.impersonate`) can act as another user from the Employees list, using `lab404/laravel-impersonate` 1.7.8 (Laravel 13 compatible). Not allowed: yourself, a deactivated or anonymised account, another root user (`system.root`), or nesting. Starting and stopping are audited with the real person as causer; every audited write while impersonating carries `impersonator_id` (D-38). A banner with a POST-only stop button shows while impersonating; the package's own GET routes are not registered, so an impersonation can only start from the Filament action. While impersonating, the Profile page (password and two-factor setup of the person being acted as) is closed. The impersonated user's own permissions apply; root access does not carry over.
- Confirm: no.

**D-48 — Dropdowns for source of hire and emergency-contact relationship. Requested by the owner (2026-11-12).**

- Source: D-19 keeps both as free strings; the owner wants dropdown lists in the Employees form.
- Default: the form shows a dropdown for each, filled from `task-logger.source_of_hire_options` and `task-logger.emergency_contact_relationships` (editable in config). The columns and the domain validation stay free text, so no migration, import or old value breaks: a stored value that is not in the list is still shown and kept when the record is edited. Seeded lists: Referral, LinkedIn, Company website, Job board, Recruitment agency, University or campus, Social media, Walk-in, Internal transfer, Other; Spouse, Partner, Mother, Father, Brother, Sister, Son, Daughter, Grandparent, Friend, Other.
- Confirm: yes. Should the lists be managed on screen like labels, and should the choice be limited to the list in the database too?

**D-49 — The estimated-versus-logged report `[ASSUMED]`.**

- Source: SPEC 1.2 ("compare estimated vs logged hours per project and technology, and value logged work at the client hourly rate that applied when it was logged") and the optional widget in section 8; no access rule is given.
- Default: a page under Reports, for people holding both `projects.view` and `logs.view_any` (superadmin, admin, assistant), because it shows everyone's logged time. Hours are shown to all of them; the value columns need `clients.view_rates` (D-05), so the assistant sees hours only. Value is the sum of each log's own rate snapshot (BR-06), rounded half up per log like `Log::amount()`, shown per project in the client's currency and grouped by currency in the totals (BR-12). The date filter limits the logs counted, never the estimate. It lists projects 25 to a page; the totals cover every project the filters match. Status defaults to Active. No export in v1.
- Confirm: no.

**D-50 — Security hardening baseline (M7) `[PROPOSED]`.**

- Source: NFR-05 and the SPEC security notes (HTTPS only, secure cookies, HSTS, evaluate encrypted casts).
- Default: outside local and testing the app forces HTTPS URLs and marks session cookies secure (`APP_ENV=production`); every response carries `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy` and `Permissions-Policy`, and HTTPS responses carry HSTS for one year. Passwords need 12 or more characters with mixed case and a number, and in production must not appear in known breaches. No Content-Security-Policy (Filament and Livewire need inline scripts). PII columns (date of birth, phone, address) are not encrypted at rest; the superadmin-only database access and backups are the control. Behind a load balancer set `TRUSTED_PROXIES` so HTTPS and the client IP are detected.
- Confirm: yes. Is encryption at rest of the private profile columns required? It would break searching them and needs a key-rotation plan.

**D-51 — A Finance role manages expenses. Decided by the owner (2026-11-12); ADR-0010.**

- Source: D-17 asked who may use expenses. The owner answered: "finance and superadmin only manage expenses". The spec had no Finance role (D-24 and ADR-0003 listed it as a conflict with D-09's five roles); the earlier owner answer gave expenses to hr, admin and superadmin.
- Default: a sixth role `finance` (`RoleName::Finance`). It holds the employee baseline plus every `expenses.*` permission (D-91 later adds `financials.view` and `financials.export`) and nothing else; one role per user still holds (D-09). hr and admin do not manage expenses. Assistant and employee have no expense access, including to their own. Superadmin keeps every permission explicitly and root access (D-41). The grid in 4.4 shows the superadmin column; the `finance` column is exactly the employee column plus the expenses row (and the financials row, D-91). D-63 adds `clients.view`, `projects.view` and `bank_holidays.view` to finance, because the employee column no longer has them.
- Confirm: no. Open for the owner: should finance see anything else (clients, rates, logs)? D-91 gives it the Financials page; it sees no rate, log or salary otherwise.

**D-52 — Expense rules `[ASSUMED]`.**

- Source: SPEC 5.6 and FR-38 give the fields and the statuses but no permissions, transitions or editing rules.
- Default: permissions `expenses.view`, `.create`, `.update`, `.delete`, `.approve`, `.reimburse`. Flow: `draft` to `submitted`; `submitted` to `approved` or `rejected`; `approved` to `reimbursed`; `rejected` back to `draft` to be edited and resubmitted; `reimbursed` is final. Submit and reopen need `expenses.update`, approve and reject need `expenses.approve`, reimburse needs `expenses.reimburse`. Header, lines and receipt can change and the expense can be deleted only while it is a `draft`, for everyone including the superadmin (the actions check it). Nobody approves or rejects an expense made for themselves, root included (like BR-20). A rejection needs a reason, appended to `notes` as "Rejected: ..." because SPEC 5.6 has no review column. Quantity is a whole number so `amount = quantity x unit price` is exact; the database checks it. All lines share the expense's one currency; totals are the sum of the lines, and any report groups by currency (BR-12). `number` is `EXP-` plus the row id padded to six digits, set in the creating transaction. The receipt is one image or PDF up to 5 MB on the private disk, read only through a permission-checked download; it is kept on anonymisation (the expense stays, BR-28). No expense export and no notifications in v1.
- Confirm: no.

**D-53 — Welcome email with a temporary password; admin password reset. Asked by the owner (2026-11-12).**

- Source: the owner asked that a new employee receive a welcome email with their sign-in email and an assigned password, told to change it after the first sign-in, and that the administrator can also reset a password and email the new one.
- Default: on create the person gets a welcome email (sign-in email, a 16-character letters-and-digits temporary password, sign-in link, a request to change it from their profile). This replaces the set-password link on create (W-01 step 5). Row actions: "Send reset-password link" (unchanged, `employees.update`) and "Reset password and email it" (`employees.update`; not for deactivated or anonymised accounts): the old password stops working at once and the new one is emailed. Both emails are sent at once, never queued, so the password is never stored in the jobs table. The audit records `password_reset_by_admin` without the value; the password hash is excluded from the user's activity log. Forced change (owner answer, 2026-11-12): a new account and an admin reset set `users.must_change_password`; while it is set every panel page redirects to "Choose your password" (sign-out stays reachable, not applied while impersonating). It comes before the 2FA enrolment redirect. The flag clears when the person chooses a password there, on their profile or through a reset link; reusing the temporary password is refused. 2FA prompts: while the grace period runs, every page shows a warning callout with an "Enable 2FA now" button and sign-in shows a persistent reminder; after it, the redirect to the profile carries a danger notification with the same button. When 2FA is optional the profile offers it with no prompt.
- Confirm: no.

**D-54 — The Directory is removed. Decided by the owner (2026-11-12).**

- Source: the owner asked to remove the Directory menu.
- Default: the Directory resource, its query and the `employees.view_directory` permission are deleted. Employees without HR rights no longer browse colleagues. BR-26 still holds: private fields appear only behind `employees.view_hr_record` (columns, the View profile slide-over) and salaries behind `salaries.view`. The Employees row menu gains a read-only View profile slide-over.
- Confirm: no.

**D-55 — Task assignment email. Asked by the owner (2026-11-13).**

- Source: the owner asked that an employee be emailed when a task is assigned to them, with the task details and a link to the task page where they can log hours.
- Default: sent when a task gets a new assignee (on create, edit or the assign action), after the change commits. Not sent when the assignee is unchanged or removed, when people assign themselves, or to a deactivated or anonymised account. The email lists name, project and client, technology, priority, status, estimate, due date, labels, the pinned flag and the description (first 1,000 characters), with "Open the task" and a direct "log hours" link. The task page gains a "Log hours" button for its assignee while the task is open; it opens the log form with the task chosen (`?task={id}`, only one of the person's own open tasks is ever preselected). Sent at once (not queued), like the other emails.
- Confirm: whether the previous assignee should be told when a task is taken from them.

**D-57 — Leave decision notification and in-app notification bell. Asked by the owner (2026-11-13).**

- Source: the owner asked that an employee is told when their leave request is approved or rejected, and that every email we send is also visible in the app, behind a bell in the top menu.
- Default: the employee gets an email and an in-app notification on the final decision only (a rejection at either stage, or HR's approval); a manager's intermediate approval sends nothing. A rejection includes the reason. Not sent to a deactivated or anonymised account. The bell uses Filament's database notifications drawer (unread badge, mark all as read, clear, per-item delete, 30 second polling). `TaskAssigned` (D-55) also goes to the bell. The welcome and password-reset emails stay email only because they contain a password.
- Confirm: whether approvers should also be notified when a request is submitted.

**D-56 — Admin sees every leave request. Decided by the owner (2026-11-13).**

- Source: the owner asked that admins see all leave requests, on the Leave Requests screen and on the employee page's Leave requests tab.
- Default: the admin role gains `leave_requests.view_any` (read only). Deciding still follows the approval rules: admins approve only their direct reports (`leave_requests.approve_direct_reports`).
- Confirm: no.

**D-58 — Seeders always create the superadmin and the role logins; demo data needs --force outside local. Asked by the owner (2026-11-19).**

- Source: the owner asked that the root/superadmin and the per-role users are always created when the application is deployed, and that `LocalDemoSeeder` can run outside local when `--force` is passed.
- Default: `SuperadminSeeder` and `RoleUsersSeeder` run in every environment, production included. The superadmin comes from `SUPERADMIN_EMAIL` and `SUPERADMIN_PASSWORD`, falling back to `superadmin@test.com` / `12345678` (the `config/task-logger.php` defaults) when they are unset; production logs a warning in that case. The role logins stay `{role}@test.com` / `12345678`. Both seeders remain idempotent on email. `LocalDemoSeeder` runs when `APP_ENV=local` or when the seed command has `--force`, and still skips when customers already exist. Supersedes the earlier "never in production" rule for the role logins.
- Confirm: yes. Known weak credentials exist on a production database until someone changes them. Should production require `SUPERADMIN_PASSWORD` and skip the `@test.com` logins? Note also that `php artisan db:seed --force` is the usual production flag, so a full `DatabaseSeeder` run with it now also seeds the demo data.

**D-59 — Bank holidays can repeat every year. Asked by the owner (2026-11-20).**

- Source: the client asked that fixed-date holidays (28 and 29 November in Albania) are entered once and apply every year, while Easter and Eid, which move, stay one-off.
- Default: a bank holiday has a "repeats every year" flag and an optional last year. A repeating holiday applies on the same day and month in every year from the year of its own date on, up to its last year when one is set; earlier years are unchanged. A holiday on a weekend is observed on the next working day where the country moves weekend holidays (D-62), so HR does not add the observed day. A repeating holiday cannot start or end on 29 February, and a range of a year or longer cannot repeat; the form and a database constraint both refuse them. `leave_requests.days_requested` is stored when the request is decided and never recounted, so changing a holiday does not change a past request. All readers (day counting, both calendars, the missing-log marker) go through `BankHolidays::occurrences()`. The seeded Albanian fixed-date holidays are flagged to repeat.
- Confirm: yes. Should 29 February repeat on 28 February in other years? (The question about generating the observed weekday is answered by D-62.)

**D-60 — The employee's phone is a mobile number of their country. Asked by the owner (2026-11-20).**

- Source: the client asked that, once a country such as Albania is chosen, the phone is checked against that country's mobiles, the dial prefix (+355) is shown, and the person types the rest, with or without the leading 0, to get +355XXXXXXXXX.
- Default: on the Employees form the phone shows the chosen country's `prefix` in front of the input. The employee's own phone must be a valid mobile of that country (national input with or without the leading 0, or a full `+` number of that country) and is stored as E.164. Emergency contacts accept any valid number. The rule is in `UpdateHrRecord`, so it binds every actor. A phone that is already stored and unchanged is kept as it is, so an older landline never blocks an edit of another field. A stored number of the employee's own country is shown after the prefix without it. Customers are unchanged. Note: `0671234567` from the request is not a valid Albanian number in libphonenumber's data (a 067 number continues with 2 to 9); `0672123456` and `0692123456` are.
- Confirm: yes. May employees from other countries have a foreign mobile as their own phone? Should customers get the same input?

**D-61 — Country lookup from countries.dev, and the country's main language. Asked by the owner (2026-11-20).**

- Source: the client asked that, when adding a country, typing a name searches https://countries.dev and fills the relevant data.
- Default: the "New country" form has a "Find country" search that asks countries.dev (server side, only the typed name is sent) and fills name, code, phone prefix, currency and main language. The timezone is filled from PHP's country table only when the country has exactly one zone; otherwise the person chooses it. Every filled field stays editable and the usual validation still runs. The lookup appears on create only, never on edit. If the service is slow, down or answers oddly, the form shows a warning and works by hand. Countries gain a nullable `language` (ISO 639-1), informational only because the app is English only. See ADR-0011.
- Confirm: yes. Should the lookup also refresh existing countries?

**D-62 — A holiday on a weekend is observed on the next free working day. Asked by the owner (2026-11-20).**

- Source: the owner asked that HR adds a holiday once, for example 14 March (Summer Day in Albania), and that in a year when it falls on a Saturday or Sunday it carries over to the Monday: 2026 (Saturday) to Monday 16 March, 2027 (Sunday) to Monday 15 March, and in 2028 (Tuesday) it stays on the 14th. The owner chose "per country, Albania on" and "next free working day". The Bank of Albania 2026 list has the same 8 observed days (16 and 23 March, 6 and 13 April, 7 September, 23 and 30 November, 1 December).
- Default: each country has a setting "Holiday on a weekend", `stays` or `next_working_day`. Albania is `next_working_day`; Kosovo and every new country start on `stays`. Where it is on, a holiday date that is not a working day of the country (`working_days`) is also observed on the next working day that is not a holiday and not already the observed day of an earlier date: Saturday 28 and Sunday 29 November 2026 give Monday 30 November and Tuesday 1 December; Sunday 28 November 2027 gives Tuesday 30 November because Monday 29 is Liberation Day; two holidays on the same Sunday share one Monday. A weekend day inside a multi-day holiday is observed on its own, so a Friday to Monday closure is also observed on the next two free working days. A holiday that falls on a working day never moves. The observed day is computed in `BankHolidays::occurrences()` and never stored, so day counting (BR-16), both calendars and the missing-log marker agree; it shows as "Name (observed)" and is not charged as leave, so a request for the observed day alone is refused as having no working days. `leave_requests.days_requested` is stored when the request is decided and is never recounted. The seeded "(observed)" rows are removed, because they would be moved a second time. The setting is on the Country form: the superadmin can change it, and the assistant can set it only when adding a country.
- Confirm: yes. Should Kosovo, or another country, move weekend holidays? Should HR be able to change a country's setting? HR holds `bank_holidays.manage` but not `countries.manage`, so today only the superadmin can edit it. Is it right that each weekend day inside a multi-day range is observed separately? Switching a country on changes what undecided leave requests cost from then on.

**D-63 — Projects, clients and the bank holiday list are hidden from employees. Asked by the owner (2026-11-22).**

- Source: the owner asked that employees see only Tasks & Logs and Leave Requests, and that finance and HR still see projects and clients. This replaces the "everyone can read projects and customers" part of D-05 and D-10.
- Default: `clients.view`, `projects.view` and `bank_holidays.view` (the list) are no longer in the baseline every role shares. Admin, HR, assistant and finance hold them explicitly; the employee does not, so the sidebar entry is gone and a typed URL is a 403. The log form still offers an employee the projects they are a member of (`LogChoices`). Holidays stay on the Leave calendar through the new `bank_holidays.view_calendar`, which every role holds.
- Confirm: yes. The assistant keeps projects and clients because the owner named only finance and HR. Should the assistant lose them too?

**D-64 — Settings are for the superadmin only. Asked by the owner (2026-11-22).**

- Source: the owner answered "change to superadmin only".
- Default: `settings.manage` is held by the superadmin alone; admin and HR lose it. Anything that must stay available to them has its own permission.
- Confirm: no.

**D-65 — Who edits whose profile and skills. Asked by the owner (2026-11-22).**

- Source: the owner said HR may edit other HR users and that an admin may edit a superadmin.
- Default: HR edits the HR record and the skills of an employee and of other HR users, never of a person holding root access. A new permission, `employees.edit_root_profile`, held by the superadmin and the admin, allows the HR record and skills of a superadmin. The account itself (name, role, manager) stays with `employees.update`. Skills are saved by the new `UpdateSkills` Action, so a person who may not change the account can still keep skills up to date.
- Confirm: no.

**D-66 — Everyone but a superadmin has a manager. Asked by the owner (2026-11-22).**

- Source: the owner said that any user with a role other than superadmin should have a `manager_id`, and that a requester without one is a superadmin.
- Default: `CreateEmployee` and `UpdateEmployee` refuse an empty manager unless the chosen role holds `system.root`, and the form marks the field required for those roles. The existing cycle check stays. The rule is in the Action and the form, not in a model hook, because the seeders and the factories create users first and assign the role afterwards. The seeder gives the demo logins a manager when they have none. Existing rows without a manager are not rewritten.
- Confirm: yes. Should a model-level guard be added as well, and should the rows without a manager be filled in?

**D-67 — The first superadmin cannot be deleted. Asked by the owner (2026-11-22).**

- Source: the owner asked for a database trigger that keeps user 1, created first as the root superadmin, from ever being deleted.
- Default: two MySQL triggers on `users` (`users_protect_root_delete` and `users_protect_root_soft_delete`) refuse a DELETE of id 1 and an update that sets `deleted_at` on it, with SQLSTATE 45000. They are part of the create-users migration. Banning or deactivating the account is not blocked.
- Confirm: yes. A server with binary logging needs `log_bin_trust_function_creators=1` or the SUPER privilege to create triggers. Should deactivation of user 1 be blocked too?

**D-68 — Employees keep no Users list. Asked by the owner (2026-11-22).**

- Source: the owner answered "keep as is this one, do not change it" about the employee Users list, and also asked that the Hierarchy is visible to everyone.
- Default: D-54 stays, and employees get no Users list. The Hierarchy page of the later phase is a separate screen open to everyone, showing only name, avatar and job title.
- Confirm: yes. Is that reading right, or should the Hierarchy live inside a Users screen for employees?

**D-69 — Anyone can be a manager. Asked by the owner (2026-11-22).**

- Source: the owner said "manager" is only the parent link (`manager_id`), not a role. The Manager select offers every active user, but only superadmin, admin and HR held `leave_requests.approve_direct_reports`, so a manager with another role could not act on the manager stage.
- Default: `leave_requests.approve_direct_reports` joins the employee baseline, so every role holds it. It still only works for the people whose `manager_id` is the holder (D-28). The grid rows for admin and HR keep it through the baseline.
- Confirm: no. The owner confirmed (2026-11-22).

**D-70 — Who approves leave and who is told. Decided by the owner (2026-11-22).**

- Source: the owner's brief for the leave workflow, and his answers about requesters without a manager.
- Default: the approver of a request is the requester's manager, an HR user's included. With no manager, or a manager who is deactivated or anonymised, the approvers are the superadmins. When a request is made, the approver and all HR users (holders of `leave_requests.approve` who are not root) get an email and an in-app notification at once, after the commit. The requester is never told about their own request. D-07 stays: two stages, manager and HR in any order (D-85; nobody decides their own, BR-20), except that an HR user's own leave has no HR stage: only their manager is told when it is made, and the manager's approval completes it (`LeaveApprovers::skipsHrStage`; with no manager the HR stage stays, so somebody can still decide it). A requester who has no manager and holds root access (the superadmin) is approved when they make the request, with the balance check and a deduction entry, and nobody is notified. A person without a manager who is not a superadmin goes through the normal flow: only the manager stage is skipped (D-07).
- Confirm: no. The owner answered (2026-11-22): HR need not be told about a superadmin's own leave, and an HR user's leave skips the HR stage but their manager must still approve it.

**D-71 — Cancelling leave. Decided by the owner (2026-11-22), amends D-44 and BR-19.**

- Source: the owner's brief. "Taken" means today (the employee's calendar day, in Europe/Tirane for Albania) has reached the first day.
- Default: a pending or approved request can be cancelled until it is taken by its requester (for a request they made themselves, D-44), by HR and by the superadmin. From the first day on, only the superadmin may cancel an approved request, at any time. Nobody cancels a pending request that has started; HR decides it or rejects it (D-72). The rule is in `LeaveRequestPolicy::cancel`, and `cancel` on a leave request is in `RootAccess::POLICY_ONLY` so root cannot cancel a started pending request either. The Cancel button is hidden by the same ability.
- Confirm: yes. May the requester still cancel their own approved leave before it starts (kept from D-44)?

**D-72 — Reminders, escalation and overdue requests. Decided by the owner (2026-11-22).**

- Source: the owner's brief: never auto-approve. **Amended by D-84 (2026-11-23): a request still pending after its first day is now approved by a job.**
- Default: a daily job (`leave.remind-pending`, 09:00 Europe/Tirane, `task-logger.leave_reminder`) sends one reminder when a request is still pending `days_before` (2) days before its first day, to whoever still has a stage to decide (D-87); with no manager the reminder goes to HR and the superadmins. On the first day, if it is still pending, HR and the superadmins are told once. Both are claimed with a conditional update on `reminder_sent_at` and `escalated_at`, so running the job twice sends nothing twice. After the first day a pending request shows an "Overdue" badge and has an "Overdue" filter on Leave Requests; this job never changes the status (D-84 does, at its next run, unless it is switched off). A run that was missed escalates on the next run.
- Confirm: no. The owner accepted (2026-11-22) that a request made after its first day escalates on the next run.

**D-73 — Back at work earlier. Asked by the owner (2026-11-22).**

- Source: the owner asked that someone who requested five days and returns on the third day gets the remaining days back.
- Default: HR (`leave_requests.approve`) and the superadmin (`leave_requests.create_for_others`) can use "Back at work earlier" on an approved request, but never on their own leave. It ends the leave on the day before the chosen return date, which must fall after the first day and on or before the last day, recomputes `days_requested` and writes a reversal entry for the working days no longer taken. The status stays approved, and taken leave is final (BR-19). A request with no working day left is cancelled instead.
- Confirm: no. The owner confirmed (2026-11-22) that HR can do it too.

**D-74 — A person with direct reports cannot be offboarded until the reports have a new manager. Asked by the owner (2026-11-22).**

- Source: the owner's brief: offboarding needs a last working day and the reassignment of direct reports to another manager, and is blocked until that is done. The grid gives HR and admin `offboardings.manage` but not `employees.update`, which is the permission for changing a manager (D-66).
- Default: the last day (`end_date`) is required. Starting an offboarding, whatever its status, and completing one are refused while the person has active direct reports, that is people with `manager_id` set to them who are not deactivated and not anonymised; those stay on record under the old manager. The check runs again on completion, because somebody may have been given this manager in between. `ReassignDirectReports` moves all active reports to one new manager and needs `offboardings.manage`, not `employees.update`, so HR can finish an offboarding alone; the Start form offers it (and requires it when the person has reports), and an open offboarding has a "Reassign direct reports" row action. The new manager must be somebody else who is active, has no offboarding of their own that is not cancelled, and must not create a loop (`ManagerChain`, D-66: not one of the reports, nor anybody who works under one). Each person is updated as a model, so the audit log shows the actor and the old and new manager. Starting with a new manager is one transaction: if the offboarding is then refused, the reports do not move. Pending leave of the reports follows the live `manager_id`, so it reaches the new manager without further work. The new manager gets one email and one in-app notification naming everyone who moved, and each moved person gets one saying who their manager is now and who it was; neither says why. They are sent at once and after the commit, so a refused offboarding sends nothing. The leaving person is not told.
- Confirm: no. The owner confirmed (2026-11-22) that HR may move a leaving person's team, and that the new manager and the moved people are told by email and in the app.

**D-75 — Who is told when an offboarding starts. Asked by the owner (2026-11-22).**

- Source: the owner's brief: notify the person's manager and HR, by email and in the app.
- Default: when an offboarding is created, in any status, the manager and HR get an email and an in-app notification, sent at once and after the commit, never to a deactivated or anonymised person. HR is the group of D-70 (holders of `leave_requests.approve` who are not root). When there is no manager to tell (the person is a superadmin, or their manager is deactivated or anonymised) the superadmins are told in their place. The person is never told about their own offboarding. The person who started it is told too if they are HR. The message names the person and the last day only (the description is HR's note); the "View" link and the email button show only to people who may open Offboardings, because a manager usually may not. Changing the facts, completing or cancelling does not notify again.
- Confirm: no. The owner confirmed (2026-11-22) that a change of the last day or a cancellation is not announced.

**D-76 — The thank-you email on the last working day. Asked by the owner (2026-11-22).**

- Source: the owner's brief: on the last working day, around 09:00 Europe/Tirane, send the person a thank-you email; handle a changed last day and a cancellation; never send it twice.
- Default: the daily job `people.offboarding-thanks` (`task-logger.offboarding_thanks`, 09:00 Europe/Tirane) emails a person whose offboarding is not cancelled and whose last day is today in their own calendar (BR-34). The email only, no in-app message, because the login may be switched off. Because the job reads the date and status when it runs, a last day moved earlier or later sends on the day it holds then, and a cancelled offboarding sends nothing. A completed offboarding still sends (the ban stops the login, not the address); an anonymised person has no address and gets nothing. The send is claimed with a conditional update on `offboardings.thanks_sent_at` before the mail goes, including a re-check of date and status, so two runs, or a run racing an edit or a cancel, send at most once. The claim is never undone: if the last day moves after the email went out, no second one is sent, and a person already thanked for an earlier offboarding that was cancelled is not thanked for a later one. A day on which the job did not run is not made up, and an offboarding created on its own last day after 09:00, or for a date in the past, sends nothing.
- Confirm: no. The owner confirmed (2026-11-22) that a missed run is not repaired and that nothing is sent when the date changes after the email. Still open, not asked: a person offboarded with a last day in the past gets nothing (the default above).

**D-77 — Employees edit their own details; leave dates are inclusive; the estimate report starts unfiltered. Asked by the owner (2026-11-22). Replaces D-33.**

- Source: the owner's brief (Phase 4) and the answer to the open question on self-service editing: name, email, phone, date of birth, university, address, gender and marital status.
- Default: every role holds `profile.update_own` through the employee baseline. The Profile page saves through `UpdateOwnProfile`, which only ever acts on the signed-in person (policy ability `updateOwnProfile`, kept under the policy for the superadmin too, so nobody edits someone else this way), and is refused while someone is impersonating (D-47). It writes the user's name, surname and email and the profile row's phone, date of birth, university, address, gender and marital status, and nothing else. Role, manager, position and employment type are shown and read-only. The phone must be a mobile of the person's country (D-60). A changed email needs the current password and must be unique; there is no verification email, because the login address is not confirmed anywhere else in the app either. **Deviation to confirm:** email editing contradicts D-14 and D-33, which kept the login address with the superadmin. Surname is editable with the name because the app shows both as one name. The profile page also has a "Back to dashboard" button.
- Leave: a request keeps an inclusive start and end date (BR-16). The calendar is the app's own grid, not FullCalendar, so there is no exclusive end to convert; the calendar legend and the end-date field say "Selected dates are inclusive: the first and last day are both days off."
- Estimated vs Logged: the project-status filter starts at "Any status" instead of Active. The filter itself stays.
- Confirm: yes. Should an employee be able to change the login email without HR, and should a changed email be verified first?

**D-78 — The employee dashboard. Asked by the owner (2026-11-22).**

- Source: the owner's brief (Phase 5): active tasks, hours this month, remaining leave, a bar chart of hours per day this week, leave taken against remaining this year, and a "This week" panel with new joiners, leavers and tasks assigned to me.
- Default: widgets under `app/Filament/Widgets`, every figure scoped to the signed-in person and gated by the permission of the data behind it (`tasks.view_own`, `logs.view_own`, `leave_requests.view_own`); the Filament account and version widgets are removed from the panel. "Active tasks" are the person's tasks that are not completed or cancelled. "Hours this month" is the sum of their logs from the first to the last day of the month in their own calendar (BR-34). The week runs Monday to Sunday in their calendar. "Remaining leave" is `LeaveBalance::available` of the leave type named in `task-logger.dashboard.leave_type` (`annual_leave`), so it is the same number as My Leave; "taken" is the days of approved or taken requests of that type that start this year; if the type is missing or inactive the figure shows "—". "Tasks assigned to me" in the week panel means tasks created this week and assigned to me (the owner's answer, 2026-11-22); a task has no assigned-on date, so its creation is the moment it reached the person, and a task given to me later by reassignment is not listed. New joiners are active people whose start date is in the week. Leavers are people whose offboarding is in progress or completed with a last day in the week; a pending or cancelled offboarding is not announced (D-75 treats it as sensitive). Joiners and leavers show name, job title and date only, to everyone who holds `hierarchy.view` (D-79).
- Confirm: no. The owner confirmed (2026-11-22) that `annual_leave` is the dashboard leave type, that "assigned to me" is a task created and assigned to me, and that pending offboardings are not shown.

**D-79 — The Hierarchy page. Asked by the owner (2026-11-22). Uses the D-68 default.**

- Source: the owner's brief (Phase 5): a Users tab with a tree built from a recursive query, starting at the superadmins who have no manager, with name, role or position and avatar. Open question 7 (who may see it) is unanswered, so the D-68 default is used.
- Default: a People page "Hierarchy", linked from the Employees list, open to everyone who holds `hierarchy.view`, which is the employee baseline and so every role. It lists active people only (not deactivated, not anonymised, not deleted). It shows name, job title, department and avatar (initials when there is no photo) and the number of people below (D-89 turns the list into an org chart); it does not show the role, email, phone or any HR field. One recursive query (`OrgTree`) reads the whole tree, so the query count does not grow with the headcount. A root is an active person with no manager, or whose manager is no longer active, so nobody disappears when a manager leaves. The walk stops at `task-logger.hierarchy_max_depth` (20) levels, which also bounds a cycle should one exist.
- Confirm: no. The owner confirmed (2026-11-22) that everyone may see the hierarchy (D-68). Still open, not asked: the role is not shown next to the job title (the default above).

**D-80 — Relation views: projects on a client, tasks on a project, logs on a task. Asked by the owner (2026-11-22).**

- Source: the owner's brief (Phase 6) and four answers (2026-11-22): the client page gets a Projects tab with Create; the project page gets a Tasks tab (list, add, edit in a modal) that uses the existing actions; the task page gets a Logs tab where "Log time" is always for the person adding it; log visibility is the same as the Logs screen.
- Default: three relation managers that only hand input to existing Actions, so authorisation, validation, the 24-hour cap, locked months and the rate snapshot are unchanged. Projects tab (`CreateProject`): the client is fixed to the page's client, and the button is hidden on a deactivated client and for roles without `projects.create`; the budget column needs `clients.view_rates` (D-05). Tasks tab (`CreateTask`, `UpdateTask`): the project is fixed; rows are scoped as on the Tasks screen (everyone with `tasks.view_any`, otherwise only the person's own tasks); the buttons follow the task policy, and the button is hidden when the project's client is deactivated. Logs tab (`CreateLog`): "Log time" shows only to the assignee of a task that is not completed or cancelled, in a month that is not locked; it logs for the signed-in person, and the task, project, technology and type are fixed; rows are the person's own logs unless they hold `logs.view_any`; rate and amount need `clients.view_rates`. A row can be opened for edit on the Logs screen, so edit and delete rules stay in one place; the tab has no delete. The View pages keep their header actions, so each manager overrides `isReadOnly()`.
- Confirm: no. The owner answered the four questions. Not asked, so taken as defaults above: employees see only their own tasks in the project's Tasks tab, and a log cannot be deleted from the task page.

**D-81 — Reminder before a task is due. Asked by the owner (2026-11-22).**

- Source: the owner's brief: when a task's due date is approaching and the task is not in a definitive state, remind the person it is assigned to. The brief names in review, completed and cancelled as the definitive states.
- Default: a daily job (`SendTaskDueReminders`, `work.task-due-reminders`, 09:00 Europe/Tirane, both in `task-logger.task_due_reminder`) sends the assignee one email and one bell notification (`TaskDueSoon`, no rate or amount) `days_before` days ahead of the due date (default 1; the leave reminder of D-72 uses 2). The day is the assignee's own (BR-34). Only tasks in backlog, to do or in progress are reminded (`TaskStatus::awaitsDueReminder`); a task with no assignee, or whose assignee is deactivated or anonymised, is skipped. A run that was missed is made up on the next one, up to the due date; a task that is already overdue gets no reminder. The reminder is claimed with a conditional update on `tasks.due_reminder_sent_at` before it is sent, so running the job twice sends nothing twice. Changing the due date or the assignee clears the claim, so the new date or the new person gets a reminder. The claim column is not fillable and so is not audited.
- Confirm: yes. Is one day ahead right, or should there be more than one reminder (for example 3 days and 1 day ahead)? Should a task that is overdue keep nagging the assignee? Should the manager of the assignee or of the project also hear about a task that is due and still open?

**D-82 — Warning when task estimates exceed the project's estimate for a technology. Asked by the owner (2026-11-22).**

- Source: the owner's brief: when tasks are added to a project and the total estimated hours of the tasks of one technology exceed the project's estimate for that technology, warn but do not prevent the task. Example: technology Y estimated at 100 h on the project, six tasks on Y adding up to 120 h.
- Default: a warning only; `CreateTask` and `UpdateTask` are unchanged and nothing is refused. The sum is the estimated hours of the project's tasks of that technology, including the task being saved and leaving out cancelled and deleted tasks (`TechnologyEstimate`). Sub-tasks count in full: nothing says a parent's estimate already contains its children's. The warning shows twice: as a "Heads up" callout in the task form that follows the technology and the hours (the Tasks screen and the project's Tasks tab), and as a warning notification after a save, because the callout is easily missed when the form is saved straight away. It shows hours only. The project's technology hours are visible to everyone who can open the project, so the warning discloses nothing new. Not covered: lowering a technology's estimate on the project form does not warn about tasks that now exceed it.
- Confirm: yes. Should cancelled tasks count? Should a parent task's estimate be reduced by its children's? Should the project form warn when an estimate is lowered below the tasks already planned?

**D-83 — Correcting a salary row. Asked by the owner (2026-11-23). Refines D-31 and BR-29.**

- Source: the owner's brief: superadmin and HR must be able to edit a salary when a date or an amount was entered by mistake. HR must not delete one, because the rows are the historic timeline.
- Default: a new permission `salaries.correct`, held by superadmin and hr and not by admin, lets a person change the amount, currency, effective date and note of an existing row (`CorrectSalary`). The employee, the recorder (`created_by`) and the recorded-at time never change. Rows are still never deleted, by anyone, root included. After a correction the ledger settles the statuses again (D-31), because a new date can change which row is active or pending. The audit trail keeps the old and new values (`attribute_changes`). Salary create stays with `salaries.manage` (superadmin, admin, hr). The screen action is "Correct" on the Salaries tab; there is no delete action.
- Confirm: yes. Should admin be able to correct a row as well? Should a correction ask for a reason?

**D-84 — Leave nobody decided in time is approved automatically. Asked by the owner (2026-11-23). Supersedes the "never auto-approve" clause of D-72.**

- Source: the owner's question: when a day off was requested and the superadmin or HR did not decide before the day passed, can it be accepted automatically, by a job that runs twice a day without overlapping? Until now it could not: D-72 only reminded, escalated and showed "Overdue".
- Default: the job `leave.auto-approve` (`AutoApproveOverdueLeave`, `task-logger.leave_auto_approve`, 01:00 and 13:00 Europe/Tirane, `withoutOverlapping`) approves a request that is still pending once its **first day has passed** in the employee's own calendar, which is exactly the "Overdue" state of D-72 (on the first day it is only escalated). It does not wait for the last day. It applies whatever stage the request was waiting at, including one where only one of the two stages had approved. It is a final approval like HR's: for a balance-affecting type it writes the deduction dated the first day, through the same code as `DecideLeave`, and BR-24 is checked again. If the balance no longer covers it, the request stays pending and overdue for HR to decide, and the run goes on with the others. No stage rows and no reviewer are written (`reviewed_by` is null, `reviewed_at` is set, `review_note` says it was automatic); `LeaveRequest::wasAutoApproved()` and the Approvals line on Leave Requests say "Approved automatically". The employee gets the usual approval email and bell notification (D-57) without a "decided by" line. Nobody else is told. Each request has its own transaction that locks the employee and re-reads the request, so HR deciding at the same moment wins, and a second run does nothing. Deactivated and anonymised employees are skipped. `leave_auto_approve.enabled` set to false brings back D-72 exactly. Because the first run after midnight approves what became overdue, a request stays "Overdue" for at most about 12 hours (so in practice only between the first day ending and the next run), and an approved one then becomes taken as in BR-19.
- Confirm: yes. This reverses the owner's own decision of 2026-11-22 (D-72). Should it wait for the last day instead of the first? Should HR or the superadmin be told when it happens? Should a request the balance does not cover be rejected instead of staying pending?

**D-85 — Leave stages are decided in any order. Asked by the owner (2026-11-23). Amends D-07 and D-43.**

- Source: the owner: there should be no order; HR and the manager can approve in any order.
- Default: the direct manager and HR each decide their own stage whenever they like. `DecideLeave` no longer requires the manager first, and the HR buttons show from the start (the same row actions feed the table, the Leave calendar details and the employee's Leave requests tab). Which stages are required has not changed: the manager stage only while the employee has a manager, the HR stage unless it is an HR user's own leave (D-70). The request is approved when every required stage has approved, and whoever approves last completes it, so `reviewed_by`, the review note, the deduction (BR-24 checked again) and the employee's notification (D-57) are the last approver's. One approval alone changes nothing the employee sees; no stage notifies anyone. A rejection at either stage ends the request at once and needs a note (BR-21), and the other stage can then no longer be decided. A stage is decided once, and nobody decides their own request (BR-20).
- Confirm: yes. If the manager changes after the old manager approved, that approval stands (owner, 2026-11-23): the stage is a fact about the request, and the new manager is not asked again.

**D-86 — One person who holds both stages decides both at once. Asked by the owner (2026-11-23). Amends D-07, D-28 and D-85, and BR-20.**

- Source: the owner: a manager should see only the manager buttons; when one stage is missing, the other's approval is enough for both; an HR manager may decide both stages of their own report's request.
- Default: the HR stage always needs a holder of `leave_requests.approve` (HR or root), the manager stage the direct manager. A direct manager who also holds the HR stage (an HR user, or the superadmin) is both: **one approval from them completes both stages** (both stage rows are recorded under their name, and the deduction, `reviewed_by` and the employee's notice are theirs). They see only the manager buttons on Leave Requests, the Leave calendar details and the employee's Leave requests tab; `DecideLeave` accepts either stage from them. A manager who is not HR never had the HR buttons and still waits for HR. A rejection by anyone ends the request and needs a note (BR-21). When the manager stage is missing (the employee has no manager) HR's approval alone completes it, as D-07 already said; when the HR stage does not apply (an HR user's own leave, D-70) the manager's alone does. Nobody decides their own request (BR-20). The superadmin who is not the manager still decides either stage separately, as in D-85.
- Confirm: yes. This replaces the first version of D-86 of the same day, which refused the HR stage to every direct manager. A non-superadmin cannot be left without a manager (`ValidatesEmployee`), and a manager change is always a reassignment, so "HR approved, then the manager stage disappears" is not reachable in the app; nothing completes a request at that moment, and cancelling or D-84 stays the exit (owner, 2026-11-23). A new manager is not notified of requests already pending (the D-87 reminder reaches them).

**D-87 — The reminder goes to whoever still has a stage to decide. Asked by the owner (2026-11-23). Amends D-72.**

- Source: the owner, answering the open question of D-85 and D-86 (option b).
- Default: the D-72 reminder, `days_before` days ahead of the first day, goes to the manager (or the superadmins when the manager is deactivated or anonymised, D-70) while the manager stage is open, and to HR (holders of `leave_requests.approve`, not root) while the HR stage is open, each person once (`LeaveApprovers::reminderRecipients`). An HR user's own leave has no HR stage (D-70), so only their manager is reminded. When the employee has no manager, or nothing is open any more, it goes to HR and the superadmins as before. The first-day escalation is unchanged.
- Confirm: yes.

**D-88 — The leave total beside the remaining and available figures. Asked by the owner (2026-11-23). Extends D-78.**

- Source: the owner asked for "Leave remaining 19 days of 21 total" on the dashboard and for balance, pending and total on My Leave. The spec does not say what "total" means.
- Default: total is the balance still in the ledger plus the days already taken this year (`LeaveOverview::total`), so remaining plus taken adds up to it when nothing is pending, and a pending request changes neither the total nor the balance. It is the same number the leave chart's two slices make up. The dashboard stat reads "19.0 days" with "of 21.0 total" beside the leave type; My Leave shows "Balance · pending · total" under each type. No new permission; both screens stay behind `leave_requests.view_own`.
- Confirm: yes.

**D-89 — The Hierarchy is an org chart, and each person has a read-only profile. Asked by the owner (2026-11-24). Amends D-14, D-54, D-68, D-79 and BR-26.**

- Source: the owner's request to draw the hierarchy as an organisational chart, with a department colour on each card and a profile page behind each name, and the owner's answers to thirteen questions on 2026-11-24.
- Default, the chart: the D-79 page is drawn as a top-down chart of cards joined by connector lines (Blade, Tailwind and Alpine; no chart library). A card shows the avatar (initials when there is no photo), the name in bold, the job title and the department in small grey text. Its border is the colour of the person's department; a department with no colour of its own takes its parent's colour, and one with neither gets the plain grey border. Only a `#rgb` or `#rrggbb` value is used. A legend above the chart lists the departments shown. Siblings are ordered by department name, then by name, people with no department last. A card with people below it carries a count of everyone below it, at any depth, and a click on the card shows or hides them; a card with nobody below has no count and does nothing. The chart opens two levels below each top person; Expand all and Collapse all stay, and nothing is remembered between visits. A wide chart scrolls sideways and has zoom buttons. Who appears does not change (D-79): active people only, and a person whose manager is no longer active becomes a top person.
- Default, the profile: the name on a card links to a read-only page at `/app/hierarchy/{id}`, outside the navigation, open to everyone who holds `hierarchy.view`. It shows avatar, name, job title, department, manager (linked to the manager's profile while the manager is active) and work email to everyone. Phone and university show only to a holder of `employees.view_hr_record` and on a person's own profile (an empty value reads "—"); every other private field stays private (BR-26, D-14). A deactivated, anonymised or deleted person has no profile (404), the same rule as the chart. The page has a "Back to hierarchy" button and, for someone who may open the Employees screen, an "Open in Employees" link. It does not list direct reports.
- This changes D-54, D-68 and BR-26 in one place: email and manager, which BR-26 listed as private, are now visible to every role, on this page only. The chart itself already showed who reports to whom.
- Confirm: no. The owner answered every question in this session.

**D-90 — People change their own photo; the Profile page is laid out in grouped sections. Asked by the owner (2026-11-24). Amends D-77.**

- Source: the owner's request to fix the Profile page and let a person update their own avatar. The owner did not say who else may change it or what happens to the old file.
- Default, the photo: the Profile page has a Photo field (an image, up to 2 MB, stored under `avatars/` on the public disk, the same field and storage HR uses on the employee form). It saves through `UpdateOwnProfile` with no new permission (`profile.update_own`, D-77). The action accepts only the photo the person already has or a file under `avatars/` that exists; any other path is refused. Emptying the field removes the photo. The photo shows in the top bar (`User` implements Filament's `HasAvatar`, initials when there is none) as well as on the Hierarchy (D-89).
- Default, the layout (screen only): labels sit above the fields, and the page is grouped into Photo, Contact, Personal details, Your role (shown as text, not disabled inputs), Password and Two-factor authentication.
- A replaced or removed photo file is kept on disk, as when HR changes it (`UpdateEmployee`); anonymising a person still deletes their current file (D-46). HR keeps the right to change a person's avatar on the employee form.
- Confirm: yes. Should a replaced photo file be deleted, and should HR's right to overwrite a person's own photo stay?

**D-91 — Financials: budget, costs and profit per project. Asked by the owner (ERL-5, 2026-11-26). Extends D-51, amends D-05 and BR-29 for this one page.**

- Source: the owner asked for a Financials page for finance and the superadmin (Project, Client, Budget, Costs, Employee costs, Fixed costs, Profit, Total hours, Deadline) and answered five questions: salary is per month; the hourly cost is the salary over the working time of the month (weekends and public holidays removed, 8 hours a day, so November 2026 in Albania is 152 hours); finance may see the salary-derived totals; a salary in another currency than the client's gives "—"; the permission is its own.
- Default, access: new permissions `financials.view` and `financials.export`, held by `finance` and the superadmin only. They do not carry `clients.view_rates` or `salaries.view`: finance sees these totals and still no rate and no salary anywhere else. `ProjectPolicy::viewFinancials` is the gate for the page, the figures (`BuildFinancials`) and the export (`FinancialsExporter`, type `financials`, under Work > Exports). The page sits in the "Clients & projects" group, has a Client and a Status filter, a column toggle and pages of 25, 50 or 100.
- Default, one row per project, totalled from its logs: Budget is the project's budget (D-04, fixed costs included). Fixed costs are the sum of its fixed costs. Employee costs: for each person and day, the salary in force on that day (the latest row dated on or before it, whatever its status) times the minutes logged, divided by the working minutes of that month in the person's country, rounded half up; the sum over the project's logs. Working time of a month: the days of the country's working week that are not bank holidays (including a weekend holiday moved to a working day, D-62) times `task-logger.financials.hours_per_working_day` (8). A person with no country uses `fallback_hours_per_month` (160). Costs are employee costs plus fixed costs. Profit is the budget minus the employee costs only, because the budget already includes the fixed costs (D-04). Total hours is the logged time as `h:mm`, all dates and all people.
- Default, edge cases: a log on a day with no salary on record costs nothing (since D-92 only a log saved before it, or one whose salary a correction (D-83) later moved past its date, can be in that state). Two salary rows on the same date: the later row wins. A loss shows as a negative profit ("-EUR 12.50"). When any salary used for the project is in another currency than the client's, employee costs, costs and profit show "—" (BR-12: no conversion). A project with no logs has zero employee costs.
- Confirm: yes. (1) The owner's "yes" to question 4 is read as costs = employee costs plus fixed costs and profit = budget minus employee costs: is that the intended Costs column? (2) Overtaken by D-92, which blocks such a log (a day left without a salary by an old log or a correction still counts as zero); (3) The export has no filters and always covers every project; should it follow the table's filters? (4) Which country's working calendar prices a past log: the person's current country (built) or their country on the log date? Logs of deactivated or anonymised people count. (5) Finance now sees projects' budgets here while `clients.view_rates` stays off for it (D-51's "sees none of it" no longer holds for this page).

**D-92 — No log without a salary on record. Asked by the owner (2026-11-27). Builds on D-91 and BR-06.**

- Source: the owner asked to block logging when no salary is on record, so Financials (D-91) never prices a day at zero. The owner did not say which salary statuses count, whether editing an existing log is affected, or whether the superadmin is exempt.
- Default: a log can be saved only if its owner has a salary row dated on or before the log date. Any status counts (pending, active or superseded), the same rule D-91 uses to price a day, so every log that passes is priced; `Salary::currentOn` is not used because it needs an active row, and a pending one only turns active in the daily job. The check runs on create and, on update, only when the date changes, so the hours or the note of an older log can still be corrected. It sits in `ValidatesLog` (`SalaryOnRecord`), so it binds the superadmin as well (root access passes Policies, not Actions). The error is on the date field and names no amount: "{name} has no salary on record on {date}, so this time cannot be logged. Ask HR to record a salary effective on or before that date." It is checked before the client rate (BR-06).
- Default, edge cases: existing logs and `CreateEmployee` are unchanged, so a new person cannot log until HR records a salary (RecordSalary, or the salary on the employee form). The seeded superadmin has no salary and cannot log until one is recorded.
- Confirm: yes. (1) Does a pending salary count, as built? (2) Should the superadmin be exempt? (3) Should the employee see a shorter message, since they cannot view salaries? Before release, someone should list active people with no salary row, because they stop being able to log the moment this ships.

### 3.2 Confirm before coding

1. D-14 and D-05: what employees and assistants may see, and whether an employee sees their own HR record. HR and admin access is now set by the owner.
2. D-21: performance review visibility (implemented literally: superadmin, hr, direct manager).
3. D-11 and D-04: whether backdating logs is allowed, and which rate date prices the budget.
4. D-15: whether the 2 months run from the offboarding end date, and whether salary and performance rows are retained.
5. (answered) D-17: expenses ship, managed by finance and superadmin only (D-51).
6. D-10: labels and whether the assistant can edit master data.
7. D-22: headcount and expected volume.
8. D-24, D-25: invoicing, multiple assignees and MCP.
9. D-28: whether employee-role managers can approve leave.
10. D-31 and D-33: the salary status rules, and whether employees may edit any of their own data.
11. D-39: future dates, closed tasks, and whether admin may edit others' logs in open months.
12. D-35 and D-36: what is immutable after creation, and the budget rule when no rate exists on the start date.
14. D-48: whether the two dropdown lists need an admin screen, and whether values outside the list must be refused.
15. D-50: whether the private profile columns must be encrypted at rest.
13. D-46: whether free text written about a former employee (leave reasons, descriptions, review notes) must also be scrubbed.

Answered by the owner and already applied: D-04 (budget includes fixed costs), D-07 (both manager and HR approve), D-08 (country leave policy vocabulary; its weekend-holiday answer was later replaced by D-62), D-10 (hr, admin and superadmin manage the HR record, salaries, offboardings, expenses and settings; assignees change their own task status), D-15 (2-month retention), D-44 (HR cancels approved leave; colleagues see each other's leave on the calendar), D-17 and D-51 (expenses are managed by a Finance role and the superadmin only).

## 4. Roles and permissions

### 4.1 Principles

- Permission-first (stated): when creating roles and permissions, use a permission grid and start from permissions. Roles are only named bundles.
- Permission names are `resource.action`, for example `logs.export`. Scoped variants are `view_any` and `view_own`. `[ASSUMED D-10]`
- Data-dependent rules (own record, direct manager, project member) live in Policies on top of the permission check.
- Every role inherits the employee baseline permissions. `[ASSUMED D-09]`
- Unspecified means denied. `[ASSUMED D-10]`

### 4.2 Roles

- `superadmin`
- `admin`
- `hr` `[ASSUMED D-06]` (source also allows admin plus HR department)
- `assistant`
- `employee` (source: employees)
- `finance`

### 4.3 Capabilities as stated in the source

**superadmin**

- Create new users and employees.
- Create new clients.
- Create new projects.
- Create PTO requests for an employee and approve them.
- Create payment plans. They are used when creating clients, to say when the client pays: 30 days after invoice, end of the month, prepayment, 15 days after invoice, and so on.
- Create departments. They are used when creating users to group them.
- Create countries. They are used when creating a user, so that a company operating internationally knows which country an employee belongs to and therefore which national holidays apply. When creating a country, set the PTO days per year and the sick leave days per year for that country.
- Create skills. They are used when creating employees. One employee can have many skills, each with a level (John: Angular 10, Laravel 7; Janet: .Net 8, Angular 9). Many-to-many between employees and skills.
- Create areas. They are used when creating customers and projects and work like skills per employee. A client is linked to many areas and each carries an hourly rate for that client (.Net 24/h, Angular 20/h, Ionic 18/h). When creating a project for that client, only the client’s areas are offered. The project selects the subset it consists of and sets estimated hours per area (Angular 230h, .Net 330h).
- Create new tasks: select the project and the area of the task, give an estimate, assign to an employee, add a title and description. The employee sees it in their task list after login and can log hours to it.
- Create vacation days: all national (bank) holidays for all countries by selecting country, date and name, so they show in the calendar.
- Create a day off directly for an employee. Example: the employee did not come to work and has no platform access, informs the admin, and the day is recorded and deducted from their PTO or sick leave.
- Export the list of all logs.

**admin**

- Create, edit, activate and deactivate customers.
- CRUD and change status of projects.
- CRUD and change status of tasks.
- Assign tasks.
- Log hours for their own tasks.
- Request days off for themselves.
- See the list of all logs and their own logs.
- See the list of all tasks and only the tasks assigned to them.

**employee**

- See all employees and view them.
- See only their own tasks and their own logs.
- Add new logs to their tasks.
- Request PTO for approval.
- Create a request for days off.
- View projects.
- View customers.
- View tasks.
- View logs.

**assistant**

- Add skills, areas, countries, departments.
- View and export projects.
- View and export customers.
- View and export tasks.
- View and export logs.

**hr** (from the FAQ)

- Approves leave together with the direct manager.
- Can see performance reviews.

**Owner clarifications (after the first review)**

- hr, admin and superadmin manage the private HR record, salaries, offboardings and settings. Expenses and Financials are managed by a Finance role and the superadmin only (D-51, owner answer 2026-11-12, replacing the earlier answer that gave expenses to hr and admin).
- HR can create bank holidays. The Monday that observes a holiday on a weekend is worked out for a country that moves weekend holidays (D-62), so HR does not add it.
- An assignee can change the status of their own tasks. The creator of a task (the manager) can change statuses of tasks they created for other users. Admin and superadmin can change any task.
- A leave request is approved only when both the direct manager and HR approve it.

### 4.4 Permission grid

Legend: ✔ stated in the source or by the owner after review. ✔\* derived or assumed (D-05, D-09, D-10). — denied. `add` = create only.

| Permission | superadmin | admin | hr | assistant | employee |
| --- | --- | --- | --- | --- | --- |
| employees.view\_directory | ✔\* | ✔\* | ✔\* | ✔\* | ✔ |
| employees.create / update / deactivate | ✔ | — | — | — | — |
| employees.view\_hr\_record / update\_hr\_record | ✔ | ✔ | ✔ | — | — |
| salaries.view / manage | ✔ | ✔ | ✔ | — | — |
| offboardings.view / manage | ✔ | ✔ | ✔ | — | — |
| employees.edit\_root\_profile (D-65) | ✔\* | ✔ | — | — | — |
| employees.impersonate `[OPTIONAL]` | ✔\* | — | — | — | — |
| performances.create | ✔ | ✔ | — | — | — |
| performances.view | ✔ | direct manager only | ✔ | — | — |
| clients.view (D-63) | ✔\* | ✔ | ✔\* | ✔ | — |
| clients.create / update / activate / deactivate | ✔ | ✔ | — | — | — |
| clients.export | ✔\* | — | — | ✔ | — |
| clients.view\_rates (rates, payment plan) | ✔\* | ✔\* | — | — | — |
| payment\_plans.manage | ✔ | — | — | — | — |
| projects.view (D-63) | ✔\* | ✔ | ✔\* | ✔ | — |
| projects.create | ✔ | ✔ | — | — | — |
| projects.update / delete / change\_status | ✔\* | ✔ | — | — | — |
| projects.export | ✔\* | — | — | ✔ | — |
| projects.manage\_members | ✔\* | ✔\* | — | — | — |
| fixed\_costs.manage | ✔ | — | — | — | — |
| tasks.view\_any | ✔\* | ✔ | — | ✔ | — |
| tasks.view\_own | ✔\* | ✔ | ✔\* | ✔\* | ✔ |
| tasks.create / update / delete | ✔ | ✔ | — | — | — |
| tasks.change\_status (any task) | ✔ | ✔ | — | — | — |
| tasks.change\_status\_own (assignee; creator via policy, BR-35) | ✔\* | ✔\* | ✔\* | ✔\* | ✔ |
| tasks.assign | ✔ | ✔ | — | — | — |
| tasks.export | ✔\* | — | — | ✔ | — |
| labels.manage | ✔\* | — | — | — | — |
| logs.view\_any | ✔\* | ✔ | — | ✔ | — |
| logs.view\_own | ✔\* | ✔ | ✔\* | ✔\* | ✔ |
| logs.create\_own | ✔\* | ✔ | ✔\* | ✔\* | ✔ |
| logs.update\_own / delete\_own | ✔\* | ✔\* | ✔\* | ✔\* | ✔\* |
| logs.export | ✔ | — | — | ✔ | — |
| logs.edit\_locked | ✔\* | ✔ | — | — | — |
| timesheets.lock | ✔ | — | — | — | — |
| leave\_requests.create\_own | ✔\* | ✔ | ✔\* | ✔\* | ✔ |
| leave\_requests.view\_own | ✔\* | ✔\* | ✔\* | ✔\* | ✔\* |
| leave\_requests.view\_any | ✔\* | ✔ (D-56) | ✔\* | — | — |
| leave\_requests.approve (any request, HR stage) | ✔ | — | ✔ | — | — |
| leave\_requests.approve\_direct\_reports (manager stage) | ✔\* | ✔\* | ✔\* | — | — |
| leave\_requests.create\_for\_others (direct day off) | ✔ | — | — | — | — |
| leave\_types.manage, country\_leave\_policies.manage | ✔\* | — | — | — | — |
| bank\_holidays.manage | ✔ | — | ✔ | — | — |
| bank\_holidays.view (the list, D-63) | ✔\* | ✔\* | ✔\* | ✔\* | — |
| bank\_holidays.view\_calendar (holidays on the Leave calendar) | ✔\* | ✔\* | ✔\* | ✔\* | ✔\* |
| countries.manage | ✔ | — | — | add | — |
| departments.manage | ✔ | — | — | add | — |
| technologies.manage | ✔ | — | — | add | — |
| expenses.\* `[OPTIONAL]` (D-51: also held by the `finance` role) | ✔ | — | — | — | — |
| financials.view / export (D-91: also held by the `finance` role) | ✔ | — | — | — | — |
| activity\_logs.view | ✔\* | — | — | — | — |
| settings.manage (D-64) | ✔ | — | — | — | — |
| two\_factor.manage (D-26) | ✔ | — | — | — | — |
| system.root (D-41) | ✔ | — | — | — | — |

## 5. Data model

### 5.0 Conventions

- Primary key `id` (unsigned bigint). `created_at` and `updated_at` on every table. `created_by` is a foreign key to `users`.
- Soft deletes on users, customers, projects, tasks, logs and leave\_requests so business history survives. `[ASSUMED]` (source: retain business and audit history)
- Enums are PHP backed string enums with Filament labels.
- Money: `*_minor` unsigned bigint plus `currency_code` char(3), ISO 4217 (BR-12).
- Dates: `datetime` columns in UTC. `date` columns are calendar dates in the relevant country’s timezone.
- Phones in E.164 (propaganistas/laravel-phone). Files (avatars, receipts) are path columns; receipts on a private disk.
- Index every foreign key. Composite indexes are listed per table.
- Table and column names are examples (source note). Names below follow Laravel convention.

### 5.1 Reference data

**countries** (Country)

- `name`, `code` (ISO 3166-1 alpha-2, e.g. AL, unique), `prefix` (phone prefix, e.g. +355), `timezone` (IANA, e.g. Europe/Tirane), `currency_code` (ISO 4217, e.g. ALL; Kosovo uses EUR, Albania uses ALL), `language` (nullable, ISO 639-1 main language, informational, D-61).
- Source fields `days_off per year` and `sick leave` are entered on the country form but stored in `country_leave_policies` so they can change over time. `[ASSUMED D-08]`
- `working_days` (set of weekdays, default Monday–Friday) `[DERIVED]`. `weekend_holiday_rule` (`stays` or `next_working_day`, default `stays`, D-62): where it is `next_working_day`, a holiday on a non-working day is observed on the next free working day.
- The source says etc.: add further country attributes only through the decision log.

**country\_leave\_policies** `[DERIVED]` (FAQ: policy by country with effective dates, proration, carry-over and expiry)

- `country_id`, `leave_type_id`, `days_per_year` decimal(4,1), `effective_from` date, `proration` enum `none` or `by_start_date` (default `by_start_date`), `carry_over_enabled` bool default false, `carry_over_max_days` nullable, `carry_over_expires_after_months` nullable.
- The active policy for a date is the row with the latest `effective_from` on or before that date. Unique (`country_id`, `leave_type_id`, `effective_from`).

**leave\_types** (lookup behind `leave_type_id`)

- Seeded: `annual_leave`, `sick_leave`, `personal_leave`, `unpaid_leave`, `parental_leave`.
- `affects_balance` bool: true for annual and sick, false for the others until the owner says otherwise `[ASSUMED D-19]`. `is_active`.

**bank\_holidays** (BankHoliday)

- `name`, `start_date`, `end_date` (equal to start for a single day), `country_id`, `description`, `repeats_yearly` (bool, default false) and `repeat_until_year` (nullable, D-59).
- Constraints: end\_date on or after start\_date; `repeat_until_year` only with `repeats_yearly` and not before the year of start\_date; a repeating holiday does not touch 29 February and spans less than a year. Index (`country_id`, `start_date`).

**departments** (Department)

- `parent_id` (self, nullable), `name`, `slug` (spatie/laravel-sluggable), `description`, `color`, `is_active`.

**technologies** (Skill and Area merged, D-01)

- `name` (unique), `description`.
- Original Area columns: id, name, description. Original Skill columns: id, name, description. Identical, so nothing is lost.

**payment\_plans** (PaymentPlan)

- `days`, `description`, plus (source says etc.) `name` and `type` enum `days_after_invoice`, `end_of_month`, `prepayment` `[ASSUMED]`.
- Source examples: 30 days after invoice, end of the month, prepayment, 15 days after invoice. Metadata only: no invoice is generated.

**labels**

- `name` (unique). Seeded: Bug, Feature, Design, Testing, Refactoring, Enhancement, Documentation (source says etc., so extensible). Optional `color`.

### 5.2 People

**users** (User, the employees) — operational columns

- `name`, `surname`, `email` (unique, login), `password`, `country_id`, `department_id`, `position` (job\_title), `manager_id` (self FK, nullable, the line manager), `employment_type` enum `full_time`, `part_time`, `contractor`, `freelance`, `intern`, `start_date`, `avatar_path`, `anonymized_at`. Active or banned state via `cybercog/laravel-ban`.
- Role via spatie/laravel-permission, one role per user (D-09). Skills via `technology_user`.
- Not on this table: salary (see `salaries`) and total\_days\_off (see the leave ledger), per D-03.
- Visibility is enforced per field, not per table (D-14). The Directory shows only name, surname, position, department, skills and avatar.

**employee\_profiles** (private HR record, 1:1 with users) `[ASSUMED D-14]`

- `user_id`, `date_of_birth`, `phone` (E.164), `gender` enum `male`, `female`, `other`, `undisclosed`, `address`, `university`, `field_of_study`, `marital_status` enum `single`, `married`, `divorced`, `widowed`, `other`, `source_of_hire` (string), `has_health_insurance` bool, `description`, `metadata` JSON (key to value pairs, edited with a Filament KeyValue field).
- Source: maybe create other tables such as users, profiles, skills\_users for normalisation. This is that split.

**emergency\_contacts**

- `user_id`, `name`, `phone` (E.164), `relationship` (string: spouse, brother, sister, and so on). Many per user.

**technology\_user** (skills with level)

- `user_id`, `technology_id`, `level` tinyint 1–10. Unique (`user_id`, `technology_id`).

**salaries** (Salary)

- `user_id`, `created_by`, `amount_minor`, `currency_code`, `effective_date`, `status` enum `pending`, `active`, `superseded` `[ASSUMED D-19]`, `description`.
- History is append-only. The current salary is the latest `active` row with effective\_date on or before today. Index (`user_id`, `effective_date`).

**offboardings** (EmployeeOffBoarding)

- `user_id`, `created_by`, `end_date`, `equipment_returned` bool (whether the person returned the work laptop or other equipment issued by the company), `resignation_letter_signed` bool, `description`, `status` enum `pending`, `in_progress`, `completed`, `cancelled` `[ASSUMED D-19]`.
- At most one non-cancelled offboarding per user `[DERIVED]`.

**performances** (Performance)

- `user_id` (the employee), `created_by` (performed\_by), `score` tinyint 1–10 (performance value), `notes` (description), `review_date` `[DERIVED]`.
- Read access is restricted (BR-27).

### 5.3 Clients and projects

**customers** (Customer, also called client)

- `created_by`, `name`, `surname`, `email`, `country_id`, `phone` (E.164), `address`, `company_name`, `priority` enum `low`, `medium`, `high`, `critical`, `payment_plan_id`, `currency_code` (D-18), `avatar_path`, `is_active` (activate and deactivate).
- Technologies come from `client_technology_rates`.

**client\_technology\_rates** (FAQ: track hourly rate changes)

- `customer_id`, `technology_id`, `rate_minor` (per hour), `effective_from` date. Unique (`customer_id`, `technology_id`, `effective_from`).
- The currency is `customers.currency_code`. The rate for a date is the row with the latest `effective_from` on or before that date.
- A client’s technologies (the old client-to-area many-to-many) are the technologies with at least one rate row. Source example: .Net 24/h, Angular 20/h, Ionic 18/h.

**projects** (Project)

- `created_by`, `customer_id`, `project_type` enum `standalone`, `recurring`, `pay_as_you_go` (source: maybe an enum list or an FK table, etc.; D-19), `name`, `slug` (sluggable, unique), `description`, `manager_id` (FK users; must hold the role admin), `start_date`, `end_date` (source: deadline), `can_log_directly_to_project` bool, `status` enum `planning`, `active`, `on_hold`, `completed`, `cancelled`, `color`.
- Computed: `estimated_hours` and `budget_minor` (D-04). No `priority` (D-02).
- Technologies via `project_technology`. Members via `project_user`.

**project\_technology**

- `project_id`, `technology_id`, `estimated_hours` decimal(8,2). The technology must be one of the client’s technologies (source: only the client’s areas are offered). Example: Angular 230h, .Net 330h.

**project\_user** (project members) `[DERIVED]` (FAQ: only assigned project members can log directly)

- `project_id`, `user_id`. Unique pair.

**fixed\_costs** (FixedCost)

- `project_id`, `name`, `date` (the month it applies to, stored as the first day of that month `[ASSUMED]`), `amount_minor`, `currency_code` (must equal the client’s), `created_by` (superadmin).
- A project has many fixed costs. Example: Management, 100 EUR, for September.

### 5.4 Work

**tasks** (Task)

- `created_by`, `name`, `description`, `project_id`, `technology_id` (source: area; exactly one, must be in `project_technology`), `status` enum `backlog`, `todo`, `in_progress`, `in_review`, `completed`, `cancelled` (source: etc., so extensible), `priority` enum `low`, `medium`, `high`, `critical`, `estimated_hours` (source: hours), `assignee_id` (source: user\_id; one assignee), `parent_id` (self, nullable, default null), `force_to_log` bool, `due_date`.
- Labels via `label_task`. Indexes (`assignee_id`, `status`) and (`project_id`, `status`).
- `assignee_id` is nullable so a task can sit in Backlog before assignment `[ASSUMED D-19]`.

**logs** (Log)

- `user_id` (who logged), `date` (work date), `project_id`, `task_id` (nullable), `technology_id`, `duration_minutes` (source: hours and minutes), `log_type` enum `normal_hours`, `extra_hours`, `replacement_hours`, `others`, `description`, `rate_minor`, `rate_currency` (snapshot, BR-06). `created_at` records when it was entered.
- Exactly one of two shapes (BR-02): a task log, or a direct project log.
- Indexes (`user_id`, `date`), (`project_id`, `date`), (`task_id`).

**timesheet\_locks** `[DERIVED D-13]`

- `month` (first day of month, unique), `locked_by`, `locked_at`, `unlocked_by` nullable, `unlocked_at` nullable.

### 5.5 Leave

**leave\_requests** (LeaveRequest)

- `user_id` (employee), `created_by`, `leave_type_id`, `start_date`, `end_date`, `start_day_part` and `end_day_part` enum `full`, `am`, `pm` `[DERIVED]` (half-day support), `days_requested` decimal(4,1) (computed, BR-16), `reason`, `status` enum `pending`, `approved`, `rejected`, `taken`, `cancelled`, `reviewed_by`, `reviewed_at`, `review_note` (required when rejected).
- Index (`user_id`, `start_date`).

**leave\_request\_approvals** `[ASSUMED D-07]`

- `leave_request_id`, `stage` enum `manager`, `hr`, `reviewer_id`, `decision` enum `approved`, `rejected`, `decided_at`, `note`.

**leave\_ledger\_entries** `[DERIVED]` (replaces total\_days\_off)

- `user_id`, `leave_type_id`, `entry_type` enum `grant`, `adjustment`, `carry_over`, `deduction`, `reversal`, `expiry`, `days` signed decimal(4,1), `effective_on` date, `expires_on` nullable, `leave_request_id` nullable, `note`, `created_by`.
- Balance = sum of non-expired `days`. Index (`user_id`, `leave_type_id`).

### 5.6 Expenses `[OPTIONAL D-17]`

**expenses** (Expense; source: we can also add employee expenses)

- `user_id` (the employee), `created_by` (the admin or manager creating it), `project_id` (optional), `category` enum `travel`, `meals`, `supplies`, `equipment`, `software`, `other`, `description`, `number` (auto-generated, e.g. EXP-000001, unique, generated race-safely), `receipt_path` (file upload, private disk), `notes`, `status` enum `draft`, `submitted`, `approved`, `rejected`, `reimbursed`.
- The total is the sum of its lines.

**expense\_lines** (ExpenseLine; one expense has many lines, like a cart)

- `expense_id`, `description`, `quantity`, `unit_price_minor`, `amount_minor` (quantity × unit price), `currency_code`, `date`.

### 5.7 Audit

**activity\_log** (ActivityLog, spatie/laravel-activitylog)

- Default package table. Log all business models: users, profiles, salaries, offboardings, performances, customers, rates, projects, tasks, logs, leave, holidays, locks, expenses.
- Edits to logs in a locked month store before and after values (BR-07). Properties may contain personal data and are scrubbed on anonymisation (BR-28).

### 5.8 Relationship summary

- users 1–1 employee\_profiles. users 1–N emergency\_contacts, salaries, offboardings, performances, leave\_requests, leave\_ledger\_entries, logs.
- users N–1 departments, countries and users (manager). users N–M technologies (level).
- customers N–1 payment\_plans and countries. customers N–M technologies (rate with effective\_from).
- projects N–1 customers and users (manager). projects N–M technologies (estimated\_hours) and users (members). projects 1–N fixed\_costs, tasks, logs.
- tasks N–1 projects, technologies, users (assignee) and tasks (parent). tasks N–M labels.
- logs N–1 users, projects, tasks (nullable) and technologies.
- leave\_requests N–1 users and leave\_types. leave\_requests 1–N leave\_request\_approvals.
- countries 1–N bank\_holidays, country\_leave\_policies, users, customers.
- expenses 1–N expense\_lines. expenses N–1 users and projects (nullable).

## 6. Business rules

Each rule has an ID for traceability and testing (operating rule 6).

### 6.1 Time logging

- **BR-01** Logging is manual. Time logs need no manager approval.
- **BR-02** A log is exactly one of: (a) a task log: the task is assigned to the logging user, and project and technology come from the task; (b) a direct log: `task_id` is null, the project has `can_log_directly_to_project = true`, the user is a project member (D-12), and the technology is one of the project’s technologies.
- **BR-03** The log form has a checkbox for direct logging. Unchecked: the user’s own tasks are listed. Checked: only projects that allow direct logging and where the user is a member are listed.
- **BR-04** Duration must be greater than zero. Input is hours and minutes (minutes 0–59), stored as `duration_minutes`. One user’s total for one date may not exceed 24 hours. `[DERIVED]`
- **BR-05** `log_type` is one of normal\_hours, extra\_hours, replacement\_hours, others.
- **BR-06** Rate snapshot: when a log is created, copy the client’s rate for that technology effective on the log date into `rate_minor` and `rate_currency`. Later rate changes never alter existing logs. If no rate exists for that date, creation is blocked with a clear message. `[ASSUMED D-11]`
- **BR-07** Month lock (D-13): the superadmin locks a month. Employees cannot create, edit or delete logs dated in a locked month. Admin or superadmin can, and each such change is activity-logged with before and after values.
- **BR-08** Force to log: a task with `force_to_log = true` is pinned in its assignee’s daily log form every day until the task is closed (completed or cancelled). Blocking day submission is not specified. `[DERIVED]`
- **BR-09** One assignee per task. An assigned task appears in the employee’s task list after login and they can log hours to it.
- **BR-10** A task’s technology must belong to the task’s project. A project’s technologies must belong to the project’s client.
- **BR-11** Task `parent_id` is optional, must reference a task in the same project, and may not create a cycle. `[ASSUMED]`

* **BR-35** Task status changes are allowed to the task’s assignee (own tasks), to the task’s creator for tasks they created for other users, and to superadmin and admin for any task. The Task policy checks `tasks.change_status` (any task) or `tasks.change_status_own` (assignee), or the creator rule. “Manager” is read as the task’s creator. `[ASSUMED D-10]`

### 6.2 Money and projects

- **BR-12** Money is stored as integer minor units with an ISO currency, per client and per salary. Reports group by currency. No conversion between currencies in v1. `[DERIVED]`
- **BR-13** Project budget is computed from estimated hours and client rate, plus the project’s fixed costs (D-04).
- **BR-14** Fixed costs are added by the superadmin to a project for a specific month. They count toward the project budget and use the client’s currency.
- **BR-15** Payment plans are client metadata only. No invoices are generated in v1.

### 6.3 Leave

- **BR-16** Day counting: `days_requested` counts working days only between start and end inclusive. Skip the country’s weekend days and the employee’s country bank holidays. A half day (am or pm) counts 0.5. Computed on the server, never trusted from input. A holiday that falls on a weekend has no effect on the count, except in a country that moves weekend holidays, where the working day that observes it is not charged either (D-62).
- **BR-17** Half days: the first and last day can each be full, am or pm. A single-day request carries one part.
- **BR-18** Entitlement: on 1 January (country timezone) a grant ledger entry is written per user and balance-affecting leave type from the active country policy, prorated by start date. For an employee who joins during the year, the grant for that year is written on the start date, prorated by remaining days. `[ASSUMED]` Policies have effective dates. Carry-over and expiry follow the country policy; carry-over is off by default (setting). Prorated values round to the nearest 0.5 day. `[ASSUMED D-08]`
- **BR-19** Status flow (cancelling: D-71; shortening approved leave: D-73): pending to approved, rejected or cancelled. Approved to taken or cancelled. Rejected, taken and cancelled are final. A scheduled job moves approved to taken after the end date. A scheduled job also approves a pending request once its first day has passed (D-84). Cancelling an approved request that is not yet taken writes a reversal ledger entry.
- **BR-20** Approvals follow D-07. An approver may not approve their own request. Superadmin may decide at any stage; a manager who is also HR completes both stages with one approval (D-86). Who approves and who is told: D-70; a superadmin has no manager, so their own request is approved when made (D-70).
- **BR-21** Rejection requires `review_note`.
- **BR-22** Direct day off: the superadmin can create a leave request for an employee (created\_by = superadmin) that skips approval, is stored as approved and is deducted from PTO or sick leave. Use case: the employee did not come to work and has no platform access.
- **BR-23** No two active (pending or approved) requests for the same user may overlap. `[DERIVED]`
- **BR-24** For balance-affecting types, a request cannot exceed the available balance (balance minus pending). A superadmin direct day off may override with a note. `[ASSUMED]`
- **BR-25** Bank holidays are created by the superadmin or HR per country (country, date or date range, name) and shown on the calendar together with leave. They feed BR-16. Where a country moves weekend holidays (for example Albania), a holiday on a weekend is also observed on the next free working day, computed and not entered by HR (D-62). A holiday can repeat every year from the year of its date, with an optional last year (D-59).

### 6.4 People and governance

- **BR-26** Privacy split (D-14): the Directory exposes only public fields. A test must prove private fields never appear in any directory query, resource, export or API response for users who lack employees.view\_hr\_record (everyone except superadmin, admin and hr).
- **BR-27** Performance reviews are visible only to superadmin, HR and the employee’s direct manager (D-21).
- **BR-28** Offboarding and anonymisation (D-15): personal data is anonymised 2 months after the offboarding end date. `equipment_returned` only records whether the person returned the laptop or other company equipment. Completing an offboarding deactivates login.
- **BR-29** Salary history is a timeline: rows are never deleted, and only superadmin and hr may correct one (D-83). Each salary has an ISO currency, and the history is visible only to superadmin, admin and hr (D-10; D-91 shows finance the salary-derived employee costs per project, never a salary).
- **BR-30** A project manager must hold the role admin. Validate on save and when a role changes.
- **BR-31** A deactivated customer cannot receive new projects, tasks or logs. Existing history stays. `[DERIVED]`
- **BR-32** Audit: every business write is recorded in the activity log with actor, subject and before and after values.
- **BR-33** Exports: the superadmin exports all logs. The assistant exports projects, customers, tasks and logs (D-05: rate columns omitted). Exports run on the queue and respect the same permissions and field visibility as the screen.
- **BR-34** Time zones: store UTC. Leave and holiday date logic uses the employee’s country timezone. `[DERIVED]`

## 7. Workflows

Each workflow names the actor, the steps and the rules that apply.

**W-01 Employee onboarding** (superadmin)

1. Make sure departments, countries (with their leave policy) and technologies exist. The assistant may add these too.
2. Create the user: role, department, country, manager, position, employment type, start date, avatar, skills with levels, private profile, emergency contacts, metadata.
3. Create the initial salary row (amount, currency, effective date). Superadmin, admin or hr can manage salaries.
4. The system writes the leave grant for the year of joining (BR-18).
5. No self-registration. Only the superadmin creates users. `[DERIVED]` The new user receives a welcome email with a temporary password and is asked to change it (D-53).

**W-02 Client and project setup** (superadmin; admin for customers and projects)

1. Superadmin creates payment plans (metadata).
2. Create the customer: contact, company, country, priority, payment plan, currency, avatar, technologies with hourly rates and effective\_from.
3. Create the project: customer, type, name (slug), description, manager (role admin only), start and end date, status, color, `can_log_directly_to_project`, members (D-12), and technologies chosen only from the client’s list with estimated hours each.
4. The budget is computed (D-04).
5. The superadmin may add fixed costs for a specific month.

**W-03 Task and logging** (superadmin or admin creates; employee logs)

1. Create the task: project, technology (from the project), estimate, assignee, title, description, priority, labels, due date, optional parent, optional `force_to_log`.
2. The assignee sees the task in their task list after login. Pinned tasks appear in the daily log form (BR-08).
3. The employee logs hours: date, task, hours and minutes, log type, description. The rate snapshot is taken (BR-06). The date needs a salary on record for the employee (D-92).
4. Status changes (BR-35): the assignee can change the status of their own tasks. The creator of a task (the manager) can change statuses of tasks they created for other users. Admin and superadmin can change any task.

**W-04 Direct project logging** (employee, admin)

1. In the log form tick the direct-logging checkbox.
2. Pick a project where direct logging is enabled and the user is a member, then a project technology (BR-02, BR-03).
3. Enter date, duration, type, description. The rate snapshot is taken. The date needs a salary on record for the employee (D-92).

**W-05 Leave request** (employee or admin for themselves)

1. Create the request: type, start and end date, day parts, reason. The server computes `days_requested` (BR-16), checks overlap (BR-23) and balance (BR-24).
2. The direct manager (D-70) and HR are both told, and each approves or rejects their own stage, in any order (D-85, BR-20). Rejection needs a note (BR-21). A request still pending near its first day is reminded and escalated (D-72), and approved automatically once the first day has passed (D-84).
3. The request is approved when the last required stage approves; one rejection, at either stage, ends it.
4. On final approval a deduction ledger entry is written and the leave shows on the calendar.
5. After the end date a job sets the status to `taken` (BR-19).

**W-06 Direct day off** (superadmin)

1. Pick the employee, type (PTO or sick), dates and a note.
2. The request is stored as approved and the balance is deducted (BR-22).

**W-07 Holidays** (superadmin or HR)

1. Select the country, date (or range) and name.
2. The holiday shows on the calendar and is excluded from leave counting for that country’s employees (BR-25, BR-16). A fixed-date holiday is marked "repeats every year" once and then applies in every later year (D-59). In a country that moves weekend holidays, the observed working day appears by itself (D-62).

**W-08 Month close** (superadmin)

1. Export the logs for the month (BR-33).
2. Lock the month (BR-07). Later edits need an admin and are activity-logged.

**W-09 Performance review** (superadmin or admin)

1. Select the employee, enter the score 1–10, notes and review date.
2. Only superadmin, HR and the direct manager can read it (BR-27).

**W-10 Offboarding** (superadmin, admin or hr)

1. Create the offboarding: end date (required), equipment returned, resignation letter signed, description, status. It is refused while the person still has direct reports; their reports move to a new manager first or in the same step (D-74). The manager and HR are told (D-75).
2. On the last day, around 09:00 Europe/Tirane, the person gets a thank-you email, once (D-76).
3. On completion the login is deactivated, again only when no direct reports are left (D-74).
4. Two months after the end date the anonymisation job runs (BR-28).

## 8. UI surfaces (Filament) `[DERIVED]`

**Resources**

- Employees: Directory view for everyone, HR record tabs for superadmin. Relation managers for skills, salaries, emergency contacts.
- Offboardings, Performances.
- Customers with a rates relation manager. Payment Plans.
- Projects with relation managers for technologies, members and fixed costs.
- Tasks, Logs, Leave Requests.
- Bank Holidays, Countries (with leave policies), Departments, Technologies, Labels, Timesheet Locks, Activity Log, Settings.
- Expenses `[OPTIONAL]`.

**Custom pages**

- My Day: the daily log form with pinned tasks and the direct-logging toggle (BR-03, BR-08).
- Leave Calendar: bank holidays and leave in one calendar (source: holidays show in the calendar). Choose a maintained calendar plugin compatible with the chosen Filament major, or build an Alpine component. Built as the Calendar tab of Leave Requests: a server-rendered month grid, where a click or a drag across days opens the Request leave form with those dates.
- My Leave: balances and requests.

**Optional**

- A screen to view the role-by-permission grid. The seeded grid in section 4.4 is mandatory, an editing UI is not. `[OPTIONAL]`
- Widgets: estimated vs logged hours per project and technology, leave balance. `[OPTIONAL]`

## 9. Non-functional requirements

The source lists headings only. Below is what the source implies, plus what the agent must still define in Phase 0.

- **Security.** Authentication through the Filament login, no self-registration, password reset. Two-factor and SSO are undecided: record an ADR. Authorisation through permissions, policies and field-level rules (BR-26). Data protection: HTTPS only, private disk for receipts, evaluate encrypted casts for private profile fields. `[PROPOSED]` Auditing through the activity log (BR-32). Retention and anonymisation (BR-28).
- **Performance and scalability.** Load is unknown (D-22): the agent writes assumptions for headcount, clients and logs per day. Expected hot paths: log lists and exports, project reports, leave balance. Use indexes from section 5, paginate, queue exports, prevent N+1 queries. `[PROPOSED]`
- **Reliability.** Supervisor-managed queue workers and scheduler, failed-job handling, idempotent scheduled jobs (leave grant, anonymisation).
- **Maintainability.** Larastan in CI. Laravel Pint and branch protection are proposals. `[PROPOSED]` ide-helper and debugbar are dev only.
- **Testing.** Unit tests for leave day counting, proration, rate selection and budget. Integration tests for policies, the privacy split and the month lock. End-to-end tests for the main flows. Performance tests for log lists and exports on seeded data. Each BR-xx has at least one test.
- **Localisation.** English only for v1. Use translation helpers for strings so a later language is cheap. `[PROPOSED]`
- **Time and money.** UTC storage, country timezones for date logic (BR-34), integer money (BR-12).

## 10. Phase 0: deliverables to produce before coding

Source workflow: initially define the items below. Write each as a file in `docs/`, reuse sections 1–9 of this document, and list every open point as a question for the owner.

| # | Deliverable | What it must contain | Output file |
| --- | --- | --- | --- |
| 1 | Business requirements | Goals, scope, non-goals, stakeholders (sections 1, 3) | docs/00-business-requirements.md |
| 2 | Functional requirements | Numbered requirements per role and workflow with acceptance criteria (sections 4, 7) | docs/01-functional-requirements.md |
| 3 | Non-functional requirements | Section 9 turned into measurable targets | docs/02-non-functional-requirements.md |
| 4 | Domain analysis | Entities, workflows and relationships (sections 5, 7) | docs/03-domain-analysis.md |
| 5 | Architecture | Decide modular monolith or microservices. TALL plus Filament points to a modular monolith. `[ASSUMED]` Module boundaries, ADR | docs/04-architecture.md, docs/adr/ |
| 6 | Database design | ERD, constraints, indexes, expected access patterns, migration order | docs/05-database-design.md |
| 7 | API design | Contracts, versioning, communication. v1 has no public API (D-22): define internal contracts | docs/06-api-design.md |
| 8 | Security | Authentication, authorisation, data protection, auditing | docs/07-security.md |
| 9 | Project organisation | Coding standards, branching strategy, environments, CI/CD | docs/08-project-organization.md |
| 10 | Scalability and performance | Load estimate, potential bottlenecks | docs/09-scalability.md |
| 11 | Testing | Unit, integration, end-to-end and performance test plans | docs/10-testing-strategy.md |

## 11. Suggested milestones `[PROPOSED]`

A milestone is done when the tests for its business rules pass.

1. **M0 Foundation:** repository, CI, Laravel and Filament install, packages, Phase 0 documents.
2. **M1 RBAC and master data:** permission catalog and seeder, roles, countries with leave policies, departments, technologies, payment plans, labels, bank holidays, settings.
3. **M2 People:** users, private profiles, emergency contacts, skills, salaries, Directory, privacy split, activity log.
4. **M3 Clients, projects, tasks:** customers with rates, projects with technologies and members, fixed costs, tasks.
5. **M4 Time logging:** logs, direct logging, rate snapshot, My Day, force to log, month lock, exports.
6. **M5 Leave:** day counting, ledger, approvals, calendar, direct day off.
7. **M6 Performance and offboarding:** reviews, offboarding, anonymisation job, ban, impersonation `[OPTIONAL]`.
8. **M7 Reporting and hardening:** estimated vs logged reports, performance tests, security review.
9. **M8 Expenses** `[OPTIONAL]`.

## 12. Reference

Filament demo, for studying panels, resources and relation managers (public demo credentials taken from the source):

- GitHub: https://github.com/filamentphp/demo
- URL: https://demo.filamentphp.com/login
- Email: admin@filamentphp.com
- Password: demo.Filament@2021!

## Appendix A. Source coverage checklist

Every block of the original brief and where it now lives.

- Application description, stack, infrastructure: sections 1 and 2.
- Superadmin, admin, employees, assistant capabilities: section 4.3. HR from the FAQ: D-06.
- Permission grid instruction: sections 4.1 and 4.4.
- User, Salary, EmployeeOffBoarding, Performance: section 5.2.
- Customer, PaymentPlan, Project, FixedCost: sections 5.1 and 5.3.
- Task, Log: section 5.4.
- BankHoliday, Country, Department, Area, Skill: section 5.1 (D-01).
- LeaveRequest: section 5.5.
- Expense, ExpenseLine: section 5.6.
- ActivityLog: section 5.7.
- Naming-is-an-example note: section 5.0.
- FAQ, in source order: manual logging and no log approval (BR-01); English only (1.3); only assigned members log directly (BR-02, D-12); one assignee (BR-09); leave approved by manager and HR (D-07); HR role (D-06); country leave policy (BR-18, D-08); weekend and carried-over holidays (D-08, D-62); currency by country (5.1, D-18); budget from hours and rate (D-04); payment plans are metadata (BR-15); priority per client (D-02); performance visibility (BR-27); equipment boolean (5.2, BR-28); anonymisation (BR-28, D-15); merged technologies (D-01); rate history and snapshot (5.3, BR-06); working-day leave counting (BR-16 to BR-18); privacy split (D-14, BR-26); timesheet lock (D-13, BR-07); force to log (BR-08); invoicing non-goal (1.3); integer money (BR-12).
- Package list: section 2.3.
- Workflow list (initially define): section 10.
- Filament demo: section 12.
