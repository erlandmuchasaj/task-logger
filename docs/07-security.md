# 07 — Security

## 1. Authentication

- Filament login (email + password). No self-registration; only superadmin creates users (FR-01). New users receive a set-password link `[ASSUMED]` (expiry 72 h `[PROPOSED]`). Password reset via Laravel broker.
- Banned/deactivated users (`cybercog/laravel-ban`) cannot authenticate; their sessions are invalidated on ban.
- 2FA and SSO undecided → ADR-0005: recommend TOTP 2FA for superadmin, admin, hr, assessed in M1; SSO out of v1.
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

Notes carried from SPEC: assistant `logs.create_own` and task/log own permissions are `✔*` derived from the employee baseline (D-09). The grid note that the **Assistant has `add` only** is implemented as permissions `countries.create`, `departments.create`, `technologies.create` separate from `.manage` (`.manage` = update/delete) `[ASSUMED D-10]`.

## 4. Field-level privacy (BR-26, D-14)

- Directory queries select an explicit public column list (name, surname, position, department, skills, avatar). A `DirectoryUserResource` is the only shape served to users lacking `employees.view_hr_record`.
- Private columns live in `employee_profiles`/`emergency_contacts` plus the private `users` columns (email, start date, manager, country, employment type). Eloquent hidden attributes + dedicated Filament infolist guarded by permission.
- Exports and global search must go through the same resource; a Pest test asserts private values never appear in directory query results, resource arrays, exports.
- Open: users' own-record visibility (D-14).

## 5. Data protection

HTTPS only, secure cookies, HSTS in non-local. Receipts on private disk with signed URLs `[OPTIONAL]`. `.env` secrets only in env. Evaluate encrypted casts for DOB, phone, address (NFR-05). Mass-assignment guarded via explicit `$fillable`. Validation via Form Requests/Filament rules; E.164 via `propaganistas/laravel-phone`.

## 6. Auditing (BR-32)

`spatie/laravel-activitylog` on every business model; log attributes (before/after), causer, subject. Edits to logs in a locked month always store before/after (BR-07). `activity_logs.view` is superadmin only. Activity log is **not** pruned. Properties may hold personal data → scrubbed on anonymisation.

## 7. Retention and anonymisation (BR-28, D-15)

Trigger: `offboarding.end_date + anonymize_after_months` (default 2). Replace name with `Former employee #id`, email with `anonymized-{id}@invalid.example`, clear profile, emergency contacts, avatar, metadata; scrub personal values in activity-log properties; set `anonymized_at`. Keep ids, logs, leave, salary, performance rows. Job is idempotent.

## 8. Threat checklist (reviewed again in M7)

IDOR on Filament resources (Policy on every model, scoped queries), mass assignment, privilege escalation via role change (role changes need `employees.update` and are audited), CSRF/XSS (Livewire/Blade defaults), export data leaks (permission re-check in job), impersonation misuse `[OPTIONAL]` (superadmin only, logged), dependency CVEs (`composer audit` in CI).

## Open questions for the owner

1. Are 2FA and/or SSO required? (ADR-0005)
2. May employees read their own HR record and salary? (D-14, D-10)
3. Employee-role users who manage others: can they approve the manager stage? (ADR-0007)
4. Encrypted-at-rest columns needed?
