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

`superadmin`, `admin`, `hr` (D-06), `assistant`, `employee`. Details in section 4.

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
- Notifications to employees on the final leave decision (D-57). `[OPTIONAL]` Notifications to approvers on leave submission.

### 2.3 Packages

The source says: packages that can be used but not limited to, not all required; other well-maintained, up-to-date packages are allowed.

| Source name | Package to install (verify) | Purpose here | Scope |
| --- | --- | --- | --- |
| barryvdh/laravel-debugbar | `barryvdh/laravel-debugbar` | Request and query debugging | dev only |
| 404labfr/laravel-impersonate | `lab404/laravel-impersonate` | Superadmin acts as a user for support (D-20) | `[OPTIONAL]` |
| cybercog/laravel-ban | `cybercog/laravel-ban` | Ban or deactivate users (D-20) | prod |
| nomaduro/larastan | `larastan/larastan` | Static analysis | dev, CI |
| barryvdh/laravel-ide-helper | `barryvdh/laravel-ide-helper` | IDE metadata | dev only |
| propaganistas/laravel-phone | `propaganistas/laravel-phone` | Validate and normalise phone numbers to E.164 | prod |
| spatie/laravel-activitylog | `spatie/laravel-activitylog` | The ActivityLog entity and audit trail | prod |
| spatie/laravel-permissions | `spatie/laravel-permission` | Roles and permissions, permission-first | prod |
| spatie/laravel-sluggable | `spatie/laravel-sluggable` | Slugs for projects and departments | prod |
| spatie/laravel-settings | `spatie/laravel-settings` | App settings: carry-over toggle, retention period | prod |
| spatie/laravel-navigation | `spatie/laravel-navigation` | Navigation | prod |
| Filament Admin | `filament/filament` | Admin panel | prod |

Three source names look mistyped (`404labfr`, `nomaduro`, `laravel-permissions`). The middle column is my best reading. Verify before install.

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
- Default: own tasks and own logs only. All projects and customers read-only. Colleagues’ public profile only. Hourly rates, payment plan and budget are hidden from everyone except superadmin and admin (permission `clients.view_rates`). Assistant exports omit those columns.
- Confirm: yes.

**D-06 — HR is a role.**

- Source: HR is an admin in the HR department, or a new role `hr`.
- Default: new role `hr`. The HR department stays plain organisational data.
- Confirm: yes.

**D-07 — Leave approval chain.**

- Source: leave is approved by the direct manager and HR. LeaveRequest has a single reviewer. Superadmin can approve.
- Default: two stages, direct manager then HR, recorded in `leave_request_approvals`. The request becomes `approved` when both approve. Any rejection sets `rejected` and requires a note. Superadmin may decide at any stage. A requester with no manager skips the manager stage. `reviewed_by`, `reviewed_at`, `review_note` on `leave_requests` hold the final decision.
- Confirm: no. The owner confirmed that both the direct manager and HR must approve.

**D-08 — Leave policy vocabulary.**

- Source: country policy with effective dates, confirmed entitlement, proration, carry-over and expiry. In some countries holidays exclude weekends and are carried over. Later: grant on 1 January prorated by start date, no carry-over (setting).
- Default: carry-over of leave between years is a per-country policy option, off by default (this matches no carry-over as a setting). A bank holiday that falls on a weekend has no automatic effect and is not shifted. Where a country observes it on the Monday (Albania, for a Sunday holiday), HR creates the Monday holiday as its own bank holiday entry (owner answer). Confirmed entitlement means a grant entry written to the ledger after the policy is applied. The working week defaults to Monday–Friday and is configurable per country.
- Confirm: no for the weekend-holiday question (answered by the owner). Leave carry-over between years stays off unless a country policy turns it on.

**D-09 — One role per user.**

- Source: User has a `role`.
- Default: exactly one Spatie role per user, enforced in the application. Every role inherits the employee baseline permissions.
- Confirm: yes.

**D-10 — Permission defaults.**

- Source: the role lists are partial. Nothing is said for hr, salaries, offboarding, labels, expenses, settings. The assistant can add master data but nothing about editing it.
- Default: unspecified means denied. Owner answers: hr, admin and superadmin manage the private HR record, salaries, offboardings, expenses and settings; HR can also create bank holidays; an assignee can change the status of their own tasks. Superadmin holds every permission, assigned explicitly by the seeder so the grid stays the single source of truth. (Amended by D-41: the superadmin additionally has root access through a before-gate.) Assistant gets create only on master data.
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
- Default: `customers.country_id` foreign key. `currency_code` defaults to the country currency and is fixed once rates or logs exist.
- Confirm: yes.

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

- Source: IDEA.md, PRD.md, TRD.md and the earlier Phase 1 draft disagree with this spec (multiple assignees, invoicing, Finance role, MCP, extra packages).
- Default: this spec wins. The earlier draft is archived in `docs/archive/phase1-draft/`. Conflicts are listed in `docs/00-business-requirements.md` §7.
- Confirm: yes. Is invoicing really out, and are multiple assignees really out?

**D-25 — MCP and public API deferred (ADR-0004).**

- Source: IDEA.md calls an MCP for coding agents important; D-22 says no public API in v1.
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
- Default: after every insert the history is settled: rows dated in the future are `pending`; of the rest the latest is `active` and every earlier one `superseded`. A daily job activates pending rows when their date arrives. A back-dated row goes to history and never replaces the current salary. Only `status` ever changes on a row (BR-29).
- Confirm: yes.

**D-32 — The last superadmin is protected `[DERIVED]`.**

- Default: the last active superadmin cannot be demoted or deactivated, and nobody can deactivate themselves.
- Confirm: no.

**D-33 — Self-service profile is read-only for name and email (refines D-14).**

- Default: on their own Profile page a user changes their password and two-factor setup; name and email are shown but only the superadmin changes them (they are HR data). Users have no right over their own HR record.
- Confirm: yes. Should employees edit their own name, phone or emergency contacts?

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
- Not overridden by root, because the spec makes them impossible on purpose: the audit trail is read-only (BR-32); salary history is append-only (BR-29); users and clients are never hard-deleted (SPEC 5.0, BR-31); an export file belongs to the person who requested it (BR-33). Root falls through to the policy for those and is denied.
- Consequence for D-39: the superadmin can now edit and delete any user's log in any month. Admin still cannot edit others' logs in an open month.
- Confirm: yes. Should root also be able to change the immutable items above (for example delete audit entries)? Not recommended.

**D-42 — Leave day-counting details the spec leaves open `[ASSUMED]`.**

- Source: BR-16, BR-17 and the half-day enum `full`, `am`, `pm`.
- Default: the part describes which part of that date is on leave. A request that starts `pm` leaves at noon on the first day, one that ends `am` returns at noon on the last day, every date in between is a full day. A single-day request carries one part (the end part is ignored). A request with no working day in it (for example a weekend) is refused. A request may cover at most 366 calendar days (`task-logger.leave_max_request_days`), a guard against a wrong year. Overlap (BR-23) is checked per half day, so a morning and an afternoon request on one date do not clash; `taken` requests count as active like `approved` ones.
- Confirm: no.

**D-43 — Approval order and the ledger `[ASSUMED]`.**

- Source: D-07, BR-18, BR-19, SPEC 5.5 ("balance = sum of non-expired days").
- Default: the manager decides first, then HR, for everybody including the superadmin ("any stage" means either stage, not out of order). The manager stage is required only when the employee has a manager when the decision is made. The deduction is written on final approval, dated the first day of the leave, and a cancellation writes a reversal. The balance is the plain sum of the ledger: expiry is itself a negative entry. On the new year (country timezone) last year's unused balance expires through one `expiry` entry and, when the country policy enables carry-over, a `carry_over` entry puts back up to the policy maximum; carried days that are still unused after `carry_over_expires_after_months` expire through a further `expiry` entry, counting days used since the carry-over against the carried part first. The ledger has no manual `adjustment` writer in v1 (no permission for it in the grid).
- Confirm: no.

**D-44 — Who cancels and who sees leave. Decided by the owner (2026-11-12).**

- Source: the grid has no cancel permission and no permission for a manager to see their reports' requests.
- Default: a person cancels the requests they made for themselves while pending or approved (not taken). HR (`leave_requests.approve`) and the superadmin (`leave_requests.create_for_others`) cancel any request, including an approved one or a direct day off; a cancelled approved request writes a reversal. A direct manager sees their reports' requests through the `manager_id` relationship plus `leave_requests.approve_direct_reports` (D-28); `view_any` is superadmin and HR as in the grid. The Leave Calendar (the Calendar tab of Leave Requests) shows everybody's approved and taken leave to everybody (colleagues see each other's leave) and pending requests only to those who may see the request; holidays are the viewer's own country's (every country for people who see all leave).
- Confirm: no.

**D-45 — Offboarding transitions and review immutability `[ASSUMED]`.**

- Source: D-19 lists the offboarding statuses `pending`, `in_progress`, `completed`, `cancelled`; W-10 says completion deactivates the login; BR-27 restricts reading reviews. No transition rules or edit rules are given.
- Default: `pending` moves to `in_progress`, `completed` or `cancelled`; `in_progress` to `completed` or `cancelled`; `completed` and `cancelled` are final and the facts of a final offboarding cannot be edited. An offboarding may be created `pending`, `in_progress` or `completed` (completing at creation deactivates the login straight away) but not `cancelled`. Completing is refused for yourself and for the last superadmin, like deactivating (D-32); it is atomic with the ban. Offboardings are cancelled, never deleted. Performance reviews are never edited or deleted. Root access does not change either rule (RootAccess `POLICY_ONLY`). A review's date has no limit and the reviewer may be anyone holding `performances.create`.
- Confirm: no.

**D-46 — What anonymisation does exactly `[ASSUMED]`.**

- Source: D-15 and BR-28 (replace name and email, clear profile, contacts, avatar and metadata, scrub the activity log, keep logs, leave, salary and performance rows).
- Default: the clock starts on a `completed` offboarding only: `end_date` plus `anonymize_after_months` (months added without overflow, compared with today in the person's country timezone). Someone whose login was reactivated is never anonymised. The user becomes `Former employee #id` with no surname, email `anonymized-{id}@invalid.example`, a random password, no avatar (the file is deleted), no 2FA secrets, no sessions and no password-reset tokens. The `employee_profiles` row is kept with every field emptied; emergency contacts are deleted. The position, department, manager, skills and country stay (they are not personal data and keep reports meaningful). In the activity log the entries about the user, their profile and their contacts keep their place but lose their before/after values and properties, and the IP address and user agent are cleared on entries the person caused. Writing this happens without model events so the old values are not logged again; one `anonymized` entry is added. The job is idempotent and runs daily.
- Confirm: yes. Should free-text fields written by others about the person (leave reasons, task and log descriptions, review notes) be scrubbed too? The default keeps them: they are business records, and the rows are kept by BR-28.

**D-47 — Impersonation `[OPTIONAL]` (M6). Requested by the owner (2026-11-12).**

- Source: IDEA.md and the grid row `employees.impersonate`; D-38 already records `impersonator_id` on every audit entry.
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
- Default: a sixth role `finance` (`RoleName::Finance`). It holds the employee baseline plus every `expenses.*` permission and nothing else; one role per user still holds (D-09). hr and admin do not manage expenses. Assistant and employee have no expense access, including to their own. Superadmin keeps every permission explicitly and root access (D-41). The grid in 4.4 shows the superadmin column; the `finance` column is exactly the employee column plus the expenses row.
- Confirm: no. Open for the owner: should finance see anything else (clients, rates, logs)? It sees none of it today.

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

### 3.2 Confirm before coding

1. D-14 and D-05: what employees and assistants may see, and whether an employee sees their own HR record. HR and admin access is now set by the owner.
2. D-21: performance review visibility (implemented literally: superadmin, hr, direct manager).
3. D-11 and D-04: whether backdating logs is allowed, and which rate date prices the budget.
4. D-15: whether the 2 months run from the offboarding end date, and whether salary and performance rows are retained.
5. (answered) D-17: expenses ship, managed by finance and superadmin only (D-51).
6. D-10: labels and whether the assistant can edit master data.
7. D-22: headcount and expected volume.
8. D-24, D-25: invoicing, multiple assignees and MCP (conflicts with IDEA.md).
9. D-28: whether employee-role managers can approve leave.
10. D-31 and D-33: the salary status rules, and whether employees may edit any of their own data.
11. D-39: future dates, closed tasks, and whether admin may edit others' logs in open months.
12. D-35 and D-36: what is immutable after creation, and the budget rule when no rate exists on the start date.
14. D-48: whether the two dropdown lists need an admin screen, and whether values outside the list must be refused.
15. D-50: whether the private profile columns must be encrypted at rest.
13. D-46: whether free text written about a former employee (leave reasons, descriptions, review notes) must also be scrubbed.

Answered by the owner and already applied: D-04 (budget includes fixed costs), D-07 (both manager and HR approve), D-08 (weekend holidays are handled manually by HR), D-10 (hr, admin and superadmin manage the HR record, salaries, offboardings, expenses and settings; assignees change their own task status), D-15 (2-month retention), D-44 (HR cancels approved leave; colleagues see each other's leave on the calendar), D-17 and D-51 (expenses are managed by a Finance role and the superadmin only).

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

- hr, admin and superadmin manage the private HR record, salaries, offboardings and settings. Expenses are managed by a Finance role and the superadmin only (D-51, owner answer 2026-11-12, replacing the earlier answer that gave expenses to hr and admin).
- HR can create bank holidays, for example the Monday observed for a holiday that falls on a Sunday.
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
| employees.impersonate `[OPTIONAL]` | ✔\* | — | — | — | — |
| performances.create | ✔ | ✔ | — | — | — |
| performances.view | ✔ | direct manager only | ✔ | — | — |
| clients.view | ✔\* | ✔ | ✔\* | ✔ | ✔ |
| clients.create / update / activate / deactivate | ✔ | ✔ | — | — | — |
| clients.export | ✔\* | — | — | ✔ | — |
| clients.view\_rates (rates, payment plan) | ✔\* | ✔\* | — | — | — |
| payment\_plans.manage | ✔ | — | — | — | — |
| projects.view | ✔\* | ✔ | ✔\* | ✔ | ✔ |
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
| bank\_holidays.view (calendar) | ✔\* | ✔\* | ✔\* | ✔\* | ✔\* |
| countries.manage | ✔ | — | — | add | — |
| departments.manage | ✔ | — | — | add | — |
| technologies.manage | ✔ | — | — | add | — |
| expenses.\* `[OPTIONAL]` (D-51: also held by the `finance` role) | ✔ | — | — | — | — |
| activity\_logs.view | ✔\* | — | — | — | — |
| settings.manage | ✔ | ✔ | ✔ | — | — |
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

- `name`, `code` (ISO 3166-1 alpha-2, e.g. AL, unique), `prefix` (phone prefix, e.g. +355), `timezone` (IANA, e.g. Europe/Tirane), `currency_code` (ISO 4217, e.g. ALL; Kosovo uses EUR, Albania uses ALL).
- Source fields `days_off per year` and `sick leave` are entered on the country form but stored in `country_leave_policies` so they can change over time. `[ASSUMED D-08]`
- `working_days` (set of weekdays, default Monday–Friday) `[DERIVED]`. There is no automatic weekend-holiday rule: HR adds an observed Monday as its own bank holiday (D-08).
- The source says etc.: add further country attributes only through the decision log.

**country\_leave\_policies** `[DERIVED]` (FAQ: policy by country with effective dates, proration, carry-over and expiry)

- `country_id`, `leave_type_id`, `days_per_year` decimal(4,1), `effective_from` date, `proration` enum `none` or `by_start_date` (default `by_start_date`), `carry_over_enabled` bool default false, `carry_over_max_days` nullable, `carry_over_expires_after_months` nullable.
- The active policy for a date is the row with the latest `effective_from` on or before that date. Unique (`country_id`, `leave_type_id`, `effective_from`).

**leave\_types** (lookup behind `leave_type_id`)

- Seeded: `annual_leave`, `sick_leave`, `personal_leave`, `unpaid_leave`, `parental_leave`.
- `affects_balance` bool: true for annual and sick, false for the others until the owner says otherwise `[ASSUMED D-19]`. `is_active`.

**bank\_holidays** (BankHoliday)

- `name`, `start_date`, `end_date` (equal to start for a single day), `country_id`, `description`.
- Constraint: end\_date on or after start\_date. Index (`country_id`, `start_date`).

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

- **BR-16** Day counting: `days_requested` counts working days only between start and end inclusive. Skip the country’s weekend days and the employee’s country bank holidays. A half day (am or pm) counts 0.5. Computed on the server, never trusted from input. A holiday that falls on a weekend has no effect on the count; HR adds the observed Monday as its own holiday (D-08).
- **BR-17** Half days: the first and last day can each be full, am or pm. A single-day request carries one part.
- **BR-18** Entitlement: on 1 January (country timezone) a grant ledger entry is written per user and balance-affecting leave type from the active country policy, prorated by start date. For an employee who joins during the year, the grant for that year is written on the start date, prorated by remaining days. `[ASSUMED]` Policies have effective dates. Carry-over and expiry follow the country policy; carry-over is off by default (setting). Prorated values round to the nearest 0.5 day. `[ASSUMED D-08]`
- **BR-19** Status flow: pending to approved, rejected or cancelled. Approved to taken or cancelled. Rejected, taken and cancelled are final. A scheduled job moves approved to taken after the end date. Cancelling an approved request that is not yet taken writes a reversal ledger entry.
- **BR-20** Approvals follow D-07. An approver may not approve their own request. Superadmin may decide at any stage.
- **BR-21** Rejection requires `review_note`.
- **BR-22** Direct day off: the superadmin can create a leave request for an employee (created\_by = superadmin) that skips approval, is stored as approved and is deducted from PTO or sick leave. Use case: the employee did not come to work and has no platform access.
- **BR-23** No two active (pending or approved) requests for the same user may overlap. `[DERIVED]`
- **BR-24** For balance-affecting types, a request cannot exceed the available balance (balance minus pending). A superadmin direct day off may override with a note. `[ASSUMED]`
- **BR-25** Bank holidays are created by the superadmin or HR per country (country, date or date range, name) and shown on the calendar together with leave. They feed BR-16. Holidays are not shifted automatically: where a country observes a weekend holiday on the Monday (for example Albania), HR creates the Monday holiday as its own entry.

### 6.4 People and governance

- **BR-26** Privacy split (D-14): the Directory exposes only public fields. A test must prove private fields never appear in any directory query, resource, export or API response for users who lack employees.view\_hr\_record (everyone except superadmin, admin and hr).
- **BR-27** Performance reviews are visible only to superadmin, HR and the employee’s direct manager (D-21).
- **BR-28** Offboarding and anonymisation (D-15): personal data is anonymised 2 months after the offboarding end date. `equipment_returned` only records whether the person returned the laptop or other company equipment. Completing an offboarding deactivates login.
- **BR-29** Salary history is append-only, with an ISO currency per salary, and is visible only to superadmin, admin and hr (D-10).
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
3. The employee logs hours: date, task, hours and minutes, log type, description. The rate snapshot is taken (BR-06).
4. Status changes (BR-35): the assignee can change the status of their own tasks. The creator of a task (the manager) can change statuses of tasks they created for other users. Admin and superadmin can change any task.

**W-04 Direct project logging** (employee, admin)

1. In the log form tick the direct-logging checkbox.
2. Pick a project where direct logging is enabled and the user is a member, then a project technology (BR-02, BR-03).
3. Enter date, duration, type, description. The rate snapshot is taken.

**W-05 Leave request** (employee or admin for themselves)

1. Create the request: type, start and end date, day parts, reason. The server computes `days_requested` (BR-16), checks overlap (BR-23) and balance (BR-24).
2. The direct manager approves or rejects (BR-20). Rejection needs a note (BR-21).
3. HR approves or rejects.
4. On final approval a deduction ledger entry is written and the leave shows on the calendar.
5. After the end date a job sets the status to `taken` (BR-19).

**W-06 Direct day off** (superadmin)

1. Pick the employee, type (PTO or sick), dates and a note.
2. The request is stored as approved and the balance is deducted (BR-22).

**W-07 Holidays** (superadmin or HR)

1. Select the country, date (or range) and name.
2. The holiday shows on the calendar and is excluded from leave counting for that country’s employees (BR-25, BR-16).

**W-08 Month close** (superadmin)

1. Export the logs for the month (BR-33).
2. Lock the month (BR-07). Later edits need an admin and are activity-logged.

**W-09 Performance review** (superadmin or admin)

1. Select the employee, enter the score 1–10, notes and review date.
2. Only superadmin, HR and the direct manager can read it (BR-27).

**W-10 Offboarding** (superadmin, admin or hr)

1. Create the offboarding: end date, equipment returned, resignation letter signed, description, status.
2. On completion the login is deactivated.
3. Two months after the end date the anonymisation job runs (BR-28).

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
- FAQ, in source order: manual logging and no log approval (BR-01); English only (1.3); only assigned members log directly (BR-02, D-12); one assignee (BR-09); leave approved by manager and HR (D-07); HR role (D-06); country leave policy (BR-18, D-08); weekend and carried-over holidays (D-08); currency by country (5.1, D-18); budget from hours and rate (D-04); payment plans are metadata (BR-15); priority per client (D-02); performance visibility (BR-27); equipment boolean (5.2, BR-28); anonymisation (BR-28, D-15); merged technologies (D-01); rate history and snapshot (5.3, BR-06); working-day leave counting (BR-16 to BR-18); privacy split (D-14, BR-26); timesheet lock (D-13, BR-07); force to log (BR-08); invoicing non-goal (1.3); integer money (BR-12).
- Package list: section 2.3.
- Workflow list (initially define): section 10.
- Filament demo: section 12.
