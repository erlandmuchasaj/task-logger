# 08. Security

Principles: deny by default; permissions designed first, roles composed from them; row-level access by policy and scoped query, never by hiding UI; one implementation of each rule shared by UI and MCP.

## 1. Authentication

| Topic | Decision |
|---|---|
| Sign-in | Email + password through the Filament login. No registration route, no SSO (D-41) |
| Onboarding | Welcome email with a signed, hashed, one-time set-password link valid 72h, resendable (invalidates the previous). No password is ever emailed |
| Password policy | Min 12 chars, breached-password check (Laravel `Password::uncompromised()`), no forced rotation (NIST guidance), reset by emailed link, reset ends all other sessions |
| 2FA | TOTP with recovery codes; optional for all, mandatory by default for Superadmin, Admin, Finance, toggle in settings (D-42). A user who must have 2FA is redirected to setup until done |
| Brute force | 5 attempts/min per email+IP, then lockout 1 min doubling; generic error text |
| Sessions | Redis, secure + HttpOnly + SameSite=Lax cookies, idle timeout 120 min (setting), regenerate on login, "log out other sessions" on password change |
| Deactivation | Immediate: sessions deleted, MCP tokens deleted, login refused |
| 2FA reset | Superadmin only, audited, notifies the user by email |

## 2. Permissions (complete list)

Naming `area.action`. "Rel." marks abilities **derived from the manager relationship** (not assignable): evaluated by `ManagerChain` in policies for any user who has reports.

### 2.1 Permission list

| Area | Permission | Meaning |
|---|---|---|
| Users | `users.view` | Directory: name, department, country, manager, skills |
| | `users.view-sensitive` | Email, phone, start date, allowance overrides, roles |
| | `users.create` / `users.update` | Create invites, edit profile fields (not roles) |
| | `users.assign-basic-roles` | Give Employee/Assistant (A-10) |
| | `users.assign-roles` | Give any role (Superadmin only) |
| | `users.deactivate` | Deactivate a user |
| | `users.reactivate` | Reactivate within the grace period |
| | `users.set-skill-levels` | Edit skill levels |
| | `users.reset-2fa` | Reset a user's 2FA |
| | `users.impersonate` | Impersonate (see section 6) |
| Lookups | `departments.manage`, `skills.manage`, `areas.manage`, `hour-types.manage` | CRUD and archive |
| | `countries.manage`, `holidays.manage` | Allowances, working week, holidays |
| | `payment-plans.manage` | |
| | `payment-plans.view` | Read plans (needed on the client form) |
| Clients | `clients.view` | Names, status (no money) |
| | `clients.manage` | Create/edit/activate |
| | `clients.view-rates` | See rates, currency, VAT default |
| | `clients.manage-rates` | Add rate versions |
| Projects | `projects.view`, `projects.manage` | Manage includes status and area estimates |
| | `projects.view-estimates` | Project-area estimates (hidden from employees, A-04) |
| Tasks | `tasks.view-own` | Tasks I am assigned to |
| | `tasks.view-any` | All tasks |
| | `tasks.manage` | Create, edit, assign, delete |
| | `tasks.change-status-any` | Any transition |
| | `tasks.change-status-own` | Transitions on own tasks |
| Time logs | `timelogs.create-own`, `timelogs.update-own`, `timelogs.view-own` | Own logs (update/delete until month lock) |
| | `timelogs.view-all` | All logs, read |
| | `timelogs.view-money` | Rates, amounts, multipliers |
| | `timelogs.correct-any` | Edit/delete anyone's logs, even after lock (never invoiced, A-03) |
| | `timelogs.export` | Export (money columns only with `view-money`) |
| Months | `months.lock`, `months.unlock` | |
| Leave | `leave.request-own`, `leave.view-own` | |
| | `leave.approve-hr` | Decide the HR step and cancellations |
| | `leave.view-all` | All requests, balances |
| | `leave.record-direct` | Direct day off for a user |
| | `leave.override-negative` | Tick "allow negative" |
| | `leave.manage-balances` | Adjustments and allowance overrides |
| Calendar | `calendar.view-all-leave` | Everyone's leave on the team calendar |
| Reports | `reports.billing` | Billing report |
| | `reports.extra-hours` | Extra hours per employee/month |
| Invoices | `invoices.view`, `invoices.manage`, `invoices.issue`, `invoices.mark-paid`, `invoices.cancel` | |
| Exports | `clients.export`, `projects.export`, `tasks.export` | Non-money exports |
| System | `settings.manage`, `activitylog.view`, `tokens.manage-own`, `tokens.manage-any` | |

Relationship-derived (**Rel.**), for any user with direct or indirect reports: view subtree's tasks, logs, leave, balances (read-only); approve the **manager step** of their direct reports' leave; see the subtree on the team calendar; view `reports.extra-hours` for the subtree; set skill levels for the subtree.

### 2.2 Role grid

Legend: x = granted, (own) = own data only, Rel. = relationship-derived additionally. Superadmin has everything via `Gate::before`, except domain guards (month lock on invoiced logs, anonymised users) which live in actions, so they apply to everyone.

| Capability | Superadmin | Admin | HR | Finance | Employee | Assistant |
|---|---|---|---|---|---|---|
| users.view (directory) | x | x | x | x | x | x |
| users.view-sensitive | x | x | x | | | |
| users.create / update | x | x | | | | |
| users.assign-basic-roles | x | x | | | | |
| users.assign-roles | x | | | | | |
| users.deactivate | x | x | | | | |
| users.reactivate, reset-2fa, impersonate | x | | | | | |
| users.set-skill-levels | x | x (Rel. for subtree) | | | | |
| departments / skills / areas manage | x | x | | | | x |
| hour-types.manage | x | x | | | | |
| countries / holidays manage | x | | x | | | |
| payment-plans.manage | x | | | x | | |
| payment-plans.view | x | x | x | x | | |
| clients.view | x | x | | x | x (scoped A-04) | x |
| clients.manage | x | x | | | | |
| clients.view-rates | x | x | | x | | |
| clients.manage-rates | x | x | | x | | |
| projects.view | x | x | | x | x (scoped) | x |
| projects.manage | x | x | | | | |
| projects.view-estimates | x | x | | x | | x |
| tasks.view-own | x | x | | | x | |
| tasks.view-any | x | x | | x | | x |
| tasks.manage | x | x | | | | |
| tasks.change-status-any | x | x | | | | |
| tasks.change-status-own | x | x | | | x | |
| timelogs.create/update/view-own | x | x | | | x | |
| timelogs.view-all | x | x | | x | | x |
| timelogs.view-money | x | x | | x | | |
| timelogs.correct-any | x | x | | | | |
| timelogs.export | x | x | | x | | x (no money) |
| months.lock | x | | | x | | |
| months.unlock | x | | | x | | |
| leave.request-own / view-own | x | x | x | x | x | x |
| leave.approve-hr | x | | x | | | |
| leave.view-all | x | | x | | | |
| leave.record-direct | x | | x | | | |
| leave.override-negative | x | | x | | | |
| leave.manage-balances | x | | x | | | |
| calendar.view-all-leave | x | | x | | | |
| reports.billing | x | x | | x | | |
| reports.extra-hours | x | | x | | | |
| invoices.view | x | x | | x | | |
| invoices.manage / issue / mark-paid / cancel | x | | | x | | |
| clients / projects / tasks .export | x | x | | x (clients, projects, tasks) | | x |
| settings.manage, activitylog.view, tokens.manage-any | x | | | | | |
| tokens.manage-own | x | x | x | x | x | x |
| Manager relationship (Rel.) | x | x | x | x | x | x |

Notes and resolved contradictions of the original grid:

1. **Admin sees all time logs** (original grid), consistent with Admin creating tasks across all projects (A-05 revised: Admin's *people data*, i.e. leave and balances, is subtree-scoped; work data is global).
2. **Rates** (`clients.manage-rates`) are editable by Admin and Finance (initial data entry plus ongoing finance ownership, **Assumption A-25**).
3. HR and Finance users who also log time need the Employee role added (additive roles). The default invitation form offers Employee pre-ticked.
4. **Assistant** cannot create time logs (original grid) and has no leave visibility beyond their own.
5. "Employees create holidays" in the original brief means *requesting days off* (clarified); national holidays are HR/Superadmin only (A-07).

## 3. Authorisation implementation

| Mechanism | Use |
|---|---|
| spatie/laravel-permission | Role and permission storage, caching in Redis |
| Policies (one per model) | Row-level decisions. Policy methods call `can()` for the permission **and** check relationship/ownership |
| Query scopes `visibleTo(User)` | Every list query is scoped in SQL: Tasks, TimeLogs, LeaveRequests, Users (calendar), Projects, Clients |
| Filament resources | `canViewAny` etc. delegate to policies; `getEloquentQuery()` applies `visibleTo` |
| Livewire components | `authorize()` in every public action method; locked/protected properties for ids |
| MCP tools | Scope check then the same policy |
| Architecture tests | Fail the build if a Model lacks a Policy, a Filament resource lacks `getEloquentQuery` scoping, or a Livewire action lacks `authorize` |

### 3.1 Policy rules worth special attention

| Policy | Rule |
|---|---|
| TaskPolicy | `view`: `tasks.view-any` or (`tasks.view-own` and assignee) or subtree manager. `update status`: Admin/Superadmin any; assignee only own, and only between allowed states |
| TimeLogPolicy | `update/delete`: author and month not locked and not invoiced; or `timelogs.correct-any` and not invoiced. `view`: own, subtree, or `view-all`. Money attributes only with `timelogs.view-money` (attribute-level hiding in resources and exports) |
| LeaveRequestPolicy | `view`: own, subtree manager, `leave.view-all`. `decide`: user is `required_approver_id` of the manager step, or has `leave.approve-hr` for the HR step, and is **not** the requester and not already a decider on this request |
| UserPolicy | `update`: Admin can't edit Superadmin users; can't alter roles beyond basic; manager can't change own manager |
| InvoicePolicy | Edit only in Draft. Issue/cancel need `invoices.issue`/`cancel`. View needs `invoices.view` |
| MonthLockPolicy | Unlock blocked if invoiced (domain guard) |
| ClientPolicy | Rate relation hidden for users without `clients.view-rates` (relation manager not registered, API fields removed) |

## 4. Data protection

| Class | Examples | Controls |
|---|---|---|
| Restricted | Rates, invoices, 2FA secrets, tokens, password hashes | Role-limited; secrets encrypted (Laravel `encrypted` cast) or hashed; never logged |
| Confidential | Leave records and reasons, balances, emails, phones, skills | Policy scoped; excluded from MCP except own |
| Internal | Tasks, logs, projects (without money) | Authenticated users per policy |
| Public | none | |

| Area | Control |
|---|---|
| In transit | HTTPS only, HSTS, TLS 1.2+ |
| At rest | Encrypted disk/volume for DB and backups (provider feature); `spatie/laravel-backup` output encrypted with a password and stored off-site |
| Personal data lifecycle | Anonymisation job (FR-USR-06); data subject export procedure (NFR-PRIV-04) |
| Minimisation | No health details beyond the sick-leave flag: the note field is for logistics, with a UI hint not to enter diagnoses. Sick-leave notes visible only to HR and the requester (**Assumption A-30**) |
| Logs | App logs exclude request bodies, credentials, tokens |
| Secrets | `.env` outside repo, per-environment secrets, rotation runbook; CI secret scanning |
| Headers | CSP (report-only first, then enforce), `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`, `Permissions-Policy` minimal |
| Uploads | Avatar/logo only: image MIME and size validation, re-encoded, stored outside webroot or on a private disk |
| Mass assignment | Explicit `$fillable`; DTOs for actions |
| Dependencies | `composer audit` / `npm audit` gate, Dependabot/Renovate |

## 5. Audit logging

Implemented with spatie/laravel-activitylog; every row has actor, subject, event, old/new values, UTC timestamp, `source` (`web`, `mcp`, `system`), plus `impersonator_id` when applicable.

| Category | Events audited |
|---|---|
| Identity | user create/update/deactivate/reactivate/anonymise, role changes, invitation sent/resent, 2FA enabled/reset, login failures (aggregate), token created/revoked/used |
| Money | rate versions, client currency/VAT changes, invoice create/edit/issue/pay/cancel, month lock/unlock |
| Work | task create/update/assign/status change, project changes, log create/update/delete (all fields, with old values), log corrections by Admin |
| Leave | request, step decisions (with comments, override flag), cancellations, direct day off, ledger adjustments |
| System | settings changes, impersonation start/stop, MCP calls, exports requested |

Rules: audit rows cannot be edited or deleted from the application (DB grants, NFR-AUD-02); anonymisation keeps rows but pseudonymises causer names via the user record; retention per 06 section 5 (A-16).

## 6. Impersonation rules

Used for support ("show me what the user sees"). Implemented with stechstudio/filament-impersonate.

| Rule | Detail |
|---|---|
| Who | Only holders of `users.impersonate` (Superadmin) |
| Targets | Active, non-anonymised users who are **not** Superadmin. Not yourself. No nested impersonation |
| Visibility | Persistent banner "Impersonating X, return" in every page |
| Duration | Auto-ends after 60 minutes (**Assumption A-31**) |
| Audit | Start and stop rows with both ids; every audit row written during impersonation carries `impersonator_id` |
| Blocked actions while impersonating | Change password/email, 2FA setup/reset, create or revoke tokens, deciding leave steps, issuing/cancelling invoices, locking months, changing settings, deleting data. These return 403 with an explanation |
| Notification | Target is **not** notified in real time (support use), but a monthly report of impersonations is shown to Superadmins (**Assumption A-32**) |

## 7. Threats and mitigations

| Threat | Mitigation |
|---|---|
| Employee reading rates or others' leave via URL/ID guessing | Policies + scoped queries; policy tests per role (11); IDs not enumerable in MCP (`not_found`) |
| Privilege escalation via user edit form | Role assignment limited (A-10); role field absent for roles without permission; server-side check |
| Admin tampering with logs to change billing | Audit with old/new values; month lock; invoiced logs immutable; Finance reconciliation |
| Duplicate/negative leave abuse | Overlap check, ledger, HR override audited |
| Stolen MCP token | Scopes, expiry, 60 req/min, revoke, last-used display |
| Compromised Superadmin | Mandatory 2FA default, impersonation limits, audit, backups, minimal Superadmin count (recommend 2) |
| Email link interception (invitations) | One-time, 72h, hashed in DB; password set page requires no existing session |
| CSV/XLSX injection in exports | Prefix cells starting with `= + - @` with a single quote |
| XSS via descriptions | Output escaping; no raw HTML rendering; Markdown disabled in v1 |
| Time-of-check races (cap, numbering) | Row locks and transactions (05 section 6) |

## 8. Assumptions in this document

| ID | Assumption |
|---|---|
| A-25 | Admin and Finance both manage client rates |
| A-30 | Sick-leave notes visible only to HR and the requester |
| A-31 | 60-minute impersonation limit |
| A-32 | Impersonation report instead of real-time target notification |
