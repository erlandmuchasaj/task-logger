# 12. Implementation roadmap

Vertical slices, each shippable and tested (definition of done in 09, section 7). Order follows dependencies. Estimates are **assumptions** (A-34): one to two developers, ideal weeks, including tests.

| Slice | Name | Delivers | Depends on | Est. |
|---|---|---|---|---|
| S0 | Foundation | Laravel 13 skeleton, modules layout, Filament 5 panel, CI (Pint, Larastan 8, Pest), MySQL 8.4 + Redis in CI, Platform value objects (Money, Minutes, DateRange), settings, audit base, package verification (Sanctum, Horizon, Pest plugin, impersonate) | none | 1 |
| S1 | Identity | Invite-only users, set-password link (72h), login, TOTP 2FA with role-based enforcement, roles and permissions, role grid seeding, deactivation, impersonation | S0 | 2 |
| S2 | Organization | Departments, skills and levels, countries (week, allowance, carry-over), holidays, manager tree, user profile, avatars | S1 | 1.5 |
| S3 | Clients and delivery | Clients (currency, VAT, payment plan), areas, versioned rates, hour types, projects, project areas and budgets, tasks, assignments, composite-FK rules | S2 | 2 |
| S4 | Time logging | `LogTime` and edit/delete actions, snapshots, 24h cap, quick log, my logs, my tasks, budget colours, overdue flag | S3 | 2.5 |
| S5 | Month lock and reports | Manual and automatic lock, correction rules, billing report, exports | S4 | 1.5 |
| S6 | Leave | Requests (half days), two-step approval, reject with reason, ledger, allowances and carry-over jobs, PTO override, sick flag, direct day-off, cancellation, My and Team calendars | S2 | 3 |
| S7 | Notifications | In-app and email per event table, reminders | S4, S6 | 1 |
| S8 | Billing | Draft invoice from locked logs, editable lines, VAT and discount, numbering, statuses, overdue job, PDF | S5 | 2.5 |
| S9 | MCP | Token manager page, 8 tools, scopes, rate limit, idempotency, audit | S4, S6 | 2 |
| S10 | Hardening | Performance seed and k6, security test pass, accessibility pass, backups and runbooks, staging soak | all | 1.5 |

Total about 20 ideal weeks for one developer, about 12-14 calendar weeks with two. S6 can run in parallel with S3-S5 after S2.

## Milestones

| Milestone | After | Meaning |
|---|---|---|
| M1 Internal alpha | S4 | Employees can log time |
| M2 Month-end ready | S5 + S7 | Finance can lock and export |
| M3 Leave live | S6 | HR can retire spreadsheets |
| M4 Invoicing | S8 | Basic invoicing in use |
| M5 Agents | S9 | MCP available |
| M6 Release 1.0 | S10 | Hardened, backed up, documented |

## Risks to the schedule

| Risk | Mitigation |
|---|---|
| Filament 5 or package incompatibility with Laravel 13 | Slice S0 verifies every package before any feature work |
| Leave rules growing | Country configuration only; exceptions via HR override |
| Invoicing scope creep | Fixed scope (no credit notes, no email); anything else goes to a Phase 2 list |
| Concurrency bugs in cap and numbering | Concurrency tests in the slice that introduces them |

## Phase 2 candidates (out of scope now)

Credit notes, invoice emailing, multi-currency conversion, SSO, per-client month lock, time-off-in-lieu, mobile app, additional languages.
