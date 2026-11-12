# 07 — Security

## 1. Authentication

- Filament login (email + password). No self-registration; only superadmin creates users (FR-01). New users receive a set-password link `[ASSUMED]` (expiry 72 h `[PROPOSED]`). Password reset via Laravel broker.
- Banned/deactivated users (`cybercog/laravel-ban`) cannot authenticate; their sessions are invalidated on ban.
- 2FA (ADR-0005, D-26): TOTP authenticator app, controlled by the superadmin on the Two-factor authentication page. Global on/off, optional "required for all users", and a grace period (default 14 days, `config/task-logger.php`, editable on the page). After the grace period, users without 2FA are redirected to their Profile page until they enrol. Secret encrypted at rest, hidden, never logged. SSO out of v1.
- Rate-limit login (Laravel default throttle).

## 2. Authorisation

Permission-first (SPEC §4.1): permissions first, roles are named bundles, code checks permissions via Policies and Gates, never role names. One role per user (D-09); every role gets the employee baseline. Unspecified = denied (D-10). Superadmin gets every permission **explicitly** from the seeder (no `Gate::before`) so the grid is the single source of truth.

Relationship rules sit **inside Policies on top of** the permission check:

| Rule | Policy | Source |
|---|---|---|
| Own record | `logs.*_own`, `tasks.view_own`, `leave_requests.view_own` match `user_id`/`assignee_id` | §4.4 |
| Task creator may change status | `TaskPolicy::changeStatus` | BR-35 |
| Direct manager (leave stage 1) | `LeaveRequestPolicy::approveAsManager`: permission `approve_direct_reports` **and** requester's `manager_id == actor.id` | D-07, ADR-0007 |
| Direct manager reads performance | `PerformancePolicy::view`: `performances.view` OR (actor is requester's manager, ADR-0007) | D-21 |
| Project member direct logging | `LogPolicy::createDirect` | BR-02, D-12 |
| Locked month | `LogPolicy` requires `logs.edit_locked` when the month is locked | BR-07 |
| No self-approval | `LeaveRequestPolicy::decide` denies if actor is the requester | BR-20 |
| Rate visibility | field-level: `clients.view_rates` | D-05 |

## 3. Permission catalog and role grid (seeder source)

Permissions are string constants in `App\Domain\Identity\Permissions` (`Permissions::LABELS_MANAGE = 'labels.manage'`); code uses the constants, never literals (an architecture test enforces it). The role to permission map is `config/permission.php` (`roles`), with the employee baseline shared by every role. `PermissionCatalog` validates the map against the constants and feeds the seeder.

`Y` = granted; `own`/`mgr` = relationship-scoped via Policy; blank = denied; `add` = create only. SA superadmin, AD admin, HR hr, AS assistant, EM employee. Source: SPEC §4.4 (unchanged).

| Permission | SA | AD | HR | AS | EM |
|---|---|---|---|---|---|
| employees.view_directory | Y | Y | Y | Y | Y |
| employees.create, update, deactivate | Y | | | | |
| employees.view_hr_record, update_hr_record | Y | Y | Y | | |
| employees.impersonate `[OPTIONAL]` | Y | | | | |
| salaries.view, manage | Y | Y | Y | | |
| offboardings.view, manage | Y | Y | Y | | |
| performances.create | Y | Y | | | |
| performances.view | Y | mgr | Y | | |
| clients.view | Y | Y | Y | Y | Y |
| clients.create, update, activate, deactivate | Y | Y | | | |
| clients.export | Y | | | Y | |
| clients.view_rates | Y | Y | | | |
| payment_plans.manage | Y | | | | |
| projects.view | Y | Y | Y | Y | Y |
| projects.create | Y | Y | | | |
| projects.update, delete, change_status | Y | Y | | | |
| projects.export | Y | | | Y | |
| projects.manage_members | Y | Y | | | |
| fixed_costs.manage | Y | | | | |
| tasks.view_any | Y | Y | | Y | |
| tasks.view_own | Y | Y | Y | Y | Y |
| tasks.create, update, delete | Y | Y | | | |
| tasks.change_status | Y | Y | | | |
| tasks.change_status_own | Y | Y | Y | Y | Y |
| tasks.assign | Y | Y | | | |
| tasks.export | Y | | | Y | |
| labels.manage | Y | | | | |
| logs.view_any | Y | Y | | Y | |
| logs.view_own | Y | Y | Y | Y | Y |
| logs.create_own | Y | Y | Y | Y | Y |
| logs.update_own, delete_own | Y | Y | Y | Y | Y |
| logs.export | Y | | | Y | |
| logs.edit_locked | Y | Y | | | |
| timesheets.lock | Y | | | | |
| leave_requests.create_own | Y | Y | Y | Y | Y |
| leave_requests.view_own | Y | Y | Y | Y | Y |
| leave_requests.view_any | Y | | Y | | |
| leave_requests.approve | Y | | Y | | |
| leave_requests.approve_direct_reports | Y | Y | Y | | |
| leave_requests.create_for_others | Y | | | | |
| leave_types.manage, country_leave_policies.manage | Y | | | | |
| bank_holidays.manage | Y | | Y | | |
| bank_holidays.view | Y | Y | Y | Y | Y |
| countries.manage, departments.manage, technologies.manage | Y | | | add | |
| expenses.* `[OPTIONAL]` | Y | Y | Y | | |
| activity_logs.view | Y | | | | |
| settings.manage | Y | Y | Y | | |
| two_factor.manage | Y | | | | |

Notes carried from SPEC: assistant `logs.create_own` and task/log own permissions are `✔*` derived from the employee baseline (D-09). The grid note that the **Assistant has `add` only** is implemented as permissions `countries.create`, `departments.create`, `technologies.create` separate from `.manage` (`.manage` = update/delete) `[ASSUMED D-10]`.

`UserPolicy` (People) abilities, all permission-only: `viewAny`/`view`/`viewHrRecord` (`employees.view_hr_record`), `create` (`employees.create`), `updateAccount` (`employees.update`), `updateHrRecord` (`employees.update_hr_record`), `viewSalaries`/`manageSalaries`, `deactivate` (`employees.deactivate`), `resetTwoFactor` (`two_factor.manage`). Users are never hard-deleted. A user has no extra right over their own HR record (D-14 default).

## 4. Field-level privacy (BR-26, D-14)

- Directory queries select an explicit public column list (name, surname, position, department, skills, avatar). A `DirectoryUserResource` is the only shape served to users lacking `employees.view_hr_record`.
- Private columns live in `employee_profiles`/`emergency_contacts` plus the private `users` columns (email, start date, manager, country, employment type). Eloquent hidden attributes + dedicated Filament infolist guarded by permission.
- Exports and global search must go through the same resource; a Pest test asserts private values never appear in directory query results, resource arrays, exports.
- Open: users' own-record visibility (D-14).

## 5. Data protection

HTTPS only, secure cookies, HSTS in non-local. Receipts on private disk with signed URLs `[OPTIONAL]`. `.env` secrets only in env. Evaluate encrypted casts for DOB, phone, address (NFR-05). Mass-assignment guarded via explicit `$fillable`. Validation via Form Requests/Filament rules; E.164 via `propaganistas/laravel-phone`.

## 6. Auditing (BR-32)

`spatie/laravel-activitylog` (v5) on every business model; before/after values are stored in the `attribute_changes` column (not `properties`), with causer and subject. Edits to logs in a locked month always store before/after (BR-07). `activity_logs.view` is superadmin only. Each entry also records the client IP, the user agent and, when someone is acting as another user, the impersonator (`impersonator_id`; D-38). The causer is the user acted as, so "who really did it" is never lost. Activity log is **not** pruned. Properties may hold personal data → scrubbed on anonymisation.

## 7. Retention and anonymisation (BR-28, D-15)

Trigger: `offboarding.end_date + anonymize_after_months` (default 2). Replace name with `Former employee #id`, email with `anonymized-{id}@invalid.example`, clear profile, emergency contacts, avatar, metadata; scrub personal values in activity-log `attribute_changes` and `properties`, and clear `client_ip` and `user_agent` on that user's entries; set `anonymized_at`. Keep ids, logs, leave, salary, performance rows. Job is idempotent.

Behind a load balancer the client IP is only right if trusted proxies are configured (`bootstrap/app.php`, `trustProxies`); otherwise the proxy's address is stored.

## 8. Threat checklist (reviewed again in M7)

IDOR on Filament resources (Policy on every model, scoped queries), mass assignment, privilege escalation via role change (role changes need `employees.update` and are audited), CSRF/XSS (Livewire/Blade defaults), export data leaks (permission re-check in job), impersonation misuse `[OPTIONAL]` (superadmin only, logged), dependency CVEs (`composer audit` in CI).

## 9. M7 security review (2026-11-12)

Scope: the whole application as built through M6, reviewed by reading the code, by searching it for known-dangerous patterns, and by tests that now run on every push (`tests/Feature/Security`, `tests/Architecture`).

| Area | Result | Evidence |
|---|---|---|
| Authentication | Pass | Filament login is rate limited (test), no registration route (test), banned and anonymised users cannot enter the panel (`canAccessPanel`), TOTP 2FA with a superadmin-controlled requirement, secrets encrypted and never logged. |
| Authorisation: default deny | Pass | A signed-in user with no role reaches no resource, page or relation manager except their own profile (`NFR-03` test). Policies check permissions only (architecture test forbids role-name checks). Root access has a documented exemption list (D-41). |
| Authorisation: guests | Pass | Every GET panel route that takes no parameter redirects a guest to the login page (test). |
| IDOR | Pass | Row-level scope on logs, leave requests, performance reviews, exports and tasks is in the query (`getEloquentQuery`) and in the policy; the Directory selects public columns only (BR-26 tests). Direct manager rights come from `manager_id`, never from a request value. |
| Mass assignment | Pass | Every model declares `#[Fillable]`; a test fails if a model sets `$guarded = []`. Role and permission changes go through `AssignUserRole` only. |
| Injection | Pass | No user input reaches raw SQL: the raw fragments are constants (`whereRaw('1 = 0')`, the report's grouped sums). No `eval`, `exec`, `unserialize`, `{!! !!}` or `allowHtml` in `app` or `resources`. CSV export neutralises formula cells (D-40). |
| CSRF | Pass | Livewire and Filament forms are protected; the only custom state-changing route (stop impersonating) is POST with a token and the package's GET routes are not registered (D-47). |
| Sessions and transport | Pass in production | `APP_ENV=production` forces HTTPS URLs and secure cookies; HttpOnly and SameSite=lax are set; HSTS and the baseline headers are sent (D-50). Behind a load balancer set `TRUSTED_PROXIES`. |
| Passwords | Pass | 12 or more characters, mixed case, a number, and not breached in production (D-50). |
| Secrets and data at rest | Open question | The 2FA secret and recovery codes are encrypted. Date of birth, phone and address are not (D-50, owner question). `.env` is not committed. |
| Files | Pass with a note | Exports are on the private disk, owner-only, deleted after 7 days. Avatars are on the public disk by design (they appear in the Directory); uploads are limited to images of 2 MB. |
| Audit | Pass | Every business write is audited with the actor, IP, browser and impersonator; entries are read-only even for root (BR-32); anonymisation scrubs personal values (D-46). |
| Dependencies | Pass | `composer audit` and `npm audit --omit=dev` report no advisories. |

Found and fixed during the review:

1. **N+1 on every log table row** (performance and denial-of-service risk): the policy asked the database whether the month is locked once per row and action (136 queries for one page). Locked months are now loaded once per request (`LockedMonths`).
2. **Missing response headers**: added `SecurityHeaders` (D-50).
3. **No password policy** beyond the framework default: added (D-50).
4. **Session cookie `secure` flag defaulted to unset**: now on in production.

Known gaps, none blocking:

- No Content-Security-Policy (Filament and Livewire need inline scripts); revisit when Filament supports nonces.
- 2FA is optional until the superadmin requires it (D-26).
- No per-user throttle on report and export requests; the capacity test shows the cost is bounded (docs/09-scalability.md).
- Backups, queue supervision and TLS termination are deployment work (NFR-12, NFR-14) and are not testable in the repository.

## Open questions for the owner

1. Is the grace period start rule right: from when the requirement is switched on, or the user's creation if later? (ADR-0005)
2. May employees read their own HR record and salary? (D-14, D-10)
3. Employee-role users who manage others: can they approve the manager stage? (ADR-0007)
4. Encrypted-at-rest columns needed? (D-50)
