# 00 — Business requirements

Source: `docs/SPEC.md` §1 and §3 (authoritative). Tags follow SPEC §0.1.

## 1. Purpose

task-logger is a web application where employees manually log working hours against tasks (or directly against eligible projects). Admins manage clients, projects and tasks. The superadmin manages master data, people and leave. It also covers employee HR data, leave (PTO and sick) with country rules and bank holidays, performance reviews, offboarding, and `[OPTIONAL]` expenses.

## 2. Business goals

| ID | Goal |
|---|---|
| BG-1 | Record who worked how long, on which project, task and technology, and of which type (normal, extra, replacement, other). |
| BG-2 | Compare estimated vs logged hours per project and technology; value logged work at the client rate that applied when it was logged (BR-06). |
| BG-3 | Manage employees across countries: national holidays, leave entitlements, currencies. |
| BG-4 | Keep an audit trail (BR-32); retain business history after an employee leaves and anonymise personal data after the retention period (BR-28). |

## 3. Scope (v1)

In: users, roles/permissions, departments, countries and leave policies, bank holidays, technologies, payment plans (metadata), labels, clients with rate history, projects with technology estimates and members, fixed costs, tasks, logs, month lock, exports, leave requests with two-stage approval and ledger, performance reviews, salaries, offboarding, anonymisation, activity log, settings.

`[OPTIONAL]` after core milestones: impersonation (D-20), expenses (D-17), notifications (SPEC §2.2), role-grid viewer and widgets (SPEC §8).

## 4. Non-goals (SPEC §1.3)

Invoicing (payment plans are metadata only, BR-15); multiple languages (English only); manager approval of time logs (BR-01); automatic or timer logging; multiple assignees per task (BR-09).

Also out of v1 by decision: a public REST API and MCP server (D-22, ADR-0004).

## 5. Stakeholders and actors

| Actor | Interest |
|---|---|
| Owner | Confirms decisions in the log; receives open-question lists. |
| `superadmin` | Master data, people, leave, month lock, all permissions. |
| `admin` | Clients, projects, tasks; manager stage of leave for direct reports; may also be a project manager (BR-30). |
| `hr` (D-06) | HR record, salaries, offboarding, HR stage of leave, bank holidays, performance review visibility. |
| `assistant` | Adds master data; views and exports projects, customers, tasks, logs without rate columns. |
| `employee` | Logs time, sees own tasks, requests leave, views directory/projects/clients. |

## 6. Decision log summary

D-01…D-23 live in SPEC §3.1 and are not copied here. New decisions D-24 onward are appended there and backed by `docs/adr/`.

| New | Topic | ADR |
|---|---|---|
| D-24 | SPEC.md is authoritative over IDEA.md, PRD.md, TRD.md and the archived Phase 1 draft | 0003 |
| D-25 | MCP/agent API deferred out of v1 | 0004 |
| D-26 | Authentication: password + email reset; 2FA recommended, SSO out of v1 | 0005 |
| D-27 | Use Filament navigation, drop `spatie/laravel-navigation` | 0006 |
| D-28 | Manager authority follows `manager_id` relationship, not the role | 0007 |
| D-29 | Offboarding completion deactivates login as a system effect | 0008 |
| D-30 | HR record visible to superadmin, admin, hr (follows grid, not §8 wording) | 0009 |

## 7. Conflicts with other source files (resolved by ADR-0003)

| Topic | SPEC | Other source | Outcome |
|---|---|---|---|
| Assignees per task | One (BR-09) | IDEA.md Q8: "Yes" | SPEC |
| Invoicing | Non-goal | IDEA.md Q1: "can be added"; archived draft S8 | SPEC; owner question |
| Finance role | Not a role | Archived draft, TRD | SPEC |
| MCP | Out of v1 (D-22) | IDEA.md Q10: "IMPORTANT" | Deferred, ADR-0004, owner question |
| Log on cancelled/closed tasks, 15-min steps | Not specified; BR-04 allows any minute | IDEA.md Q7, archived draft | SPEC (minute granularity); owner question |
| Roles additive | One role per user (D-09) | Archived draft D-01 | SPEC |
| Stack versions | "Latest stable" | TRD: Laravel 13, Filament 5, Shield, medialibrary, backup | Verified on Packagist (04-architecture §6); Shield/medialibrary/backup not in SPEC package list, not installed unless owner agrees |

## Open questions for the owner

1. Is invoicing really out of v1 (SPEC) or in scope (IDEA.md Q1)?
2. Is an MCP server for coding agents v1 or a later milestone?
3. May employees log on completed tasks and on dates before their start date? (SPEC allows backdating until month lock, silent on task status.)
4. Do you want the Finance role from earlier drafts? SPEC has none; finance work maps to the assistant plus `logs.export`.
