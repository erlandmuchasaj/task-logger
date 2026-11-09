# 01. Business requirements

## 1. Purpose

task-logger is an internal application for a (possibly international) company where employees record working hours against tasks, so the company can:

1. know where its people's time goes (projects, clients, technology areas);
2. bill clients accurately from those hours;
3. manage who is available (leave, holidays, country rules);
4. keep a reliable, auditable record.

## 2. Problem today

Finance exports lists of logs and calculates by hand how many hours to charge each client at which rate. Leave and holidays are tracked separately. There is no single place that shows estimated vs. actual hours, who is off, or what was billed.

## 3. Business objectives and success measures

| ID | Objective | Measure (target after 3 months in use) |
|---|---|---|
| BO-01 | Replace manual billing calculation | Finance produces a monthly billing report and a draft invoice per client in under 15 minutes, with zero manual rate lookups |
| BO-02 | Accurate time capture | 95% of working days have at least one log per active employee (excluding leave/holidays) |
| BO-03 | Budget control | Every task and project-area shows logged vs. estimated hours; overruns are visible the same day |
| BO-04 | Leave transparency | 100% of leave requests handled in-app; balances correct per country rules |
| BO-05 | Auditability | Every change to money-relevant data (rates, logs after lock, invoices, leave balances) is traceable to a user and a time |
| BO-06 | Agent-assisted logging | An employee can log time and check balances from a coding agent without opening the UI |

## 4. Stakeholders

| Stakeholder | Interest |
|---|---|
| Employees | Log hours quickly, see their tasks, request leave, check balances |
| Team leads / managers (Admin) | Create and assign tasks, watch budgets, approve leave, see their team |
| HR | Approve leave, maintain countries, holidays, allowances, balances |
| Finance | Rates, billing reports, month locking, invoices |
| Assistants | Maintain lookups, produce exports |
| Superadmin | Everything, plus system settings and user lifecycle |
| Clients (indirect) | Receive correct invoices; do not use the system |

## 5. Scope

### In scope (Phase 1)

- Organization data: departments, countries (allowances, working week), holidays, skills, areas, payment plans, hour types.
- Clients (areas and rates over time, currency, VAT default, payment plan), projects (area estimates), tasks (assignees, estimates, statuses).
- Time logging with snapshots, month locking.
- Leave: PTO and sick requests, two-party approval, balances, direct day off, calendars.
- Budget and overdue indicators with alerts.
- Billing report, exports, and basic invoicing (draft, edit, issue, PDF, paid/overdue/cancelled).
- Roles and permissions, activity log, impersonation, 2FA, invite-only onboarding, offboarding with anonymisation.
- MCP server for coding agents (v1 tools in D-51).
- In-app and email notifications.

### Out of scope (Phase 1)

- Public or REST API; SSO; mobile app; live start/stop timer (manual date + hours entry only).
- Credit notes, emailing invoices, payment tracking beyond a Paid flag, currency conversion, accounting integration.
- Time-off-in-lieu balance for Replacement hours; overtime approval.
- Content translation; additional UI languages.
- Payroll calculation.

## 6. Business rules (summary)

Full detail in [04-domain-analysis.md](04-domain-analysis.md). Headline rules:

1. Time is logged only on tasks assigned to the user, in 15-minute steps, max 24h per day.
2. Each log freezes its rate; later rate changes never alter past logs.
3. After a month is locked, only Admin/Superadmin can correct its logs, and never those on an issued invoice.
4. Leave needs manager and HR approval; PTO may go negative only with HR's explicit override; sick leave is never blocked.
5. Holidays and non-working days are not deducted from leave.
6. Money is visible only to Superadmin, Admin and Finance.

## 7. Constraints

| Type | Constraint |
|---|---|
| Technology | Laravel 13, Livewire, Alpine.js, Tailwind, Filament, MySQL, Redis, Supervisor |
| Hosting | Linux production; dev on WAMP (Windows) and Herd (Mac) |
| Legal | Personal data of EU staff is likely: GDPR-style anonymisation, retention of financial records about 10 years (**Assumption**: the company is subject to EU rules; confirm with legal) |
| Users | 50-200 employees, about 30 countries, so multiple timezones |
| Language | English UI |

## 8. Risks

| ID | Risk | Mitigation |
|---|---|---|
| R-01 | Timezone errors shift logs or leave by a day | Dates stored as plain dates (A-01); timestamps in UTC; tested explicitly |
| R-02 | Rate or hour changes alter past invoices | Snapshots on logs, month lock, invoice immutability (A-03) |
| R-03 | Permission mistakes expose rates or other people's data | Permission-first design, policies with tests, scoped queries |
| R-04 | Leave rules vary per country and exceed the model | Country-level configuration (allowance, working week, carry-over); flag exceptions to HR override |
| R-05 | Over-scoped Phase 1 (invoicing plus MCP plus leave) | Delivery slices in [12-implementation-roadmap.md](12-implementation-roadmap.md) |
| R-06 | Package or version drift (new Laravel major) | Verified compatibility (05), CI on locked versions |
| R-07 | Agents (MCP) acting with excessive reach | Scoped tokens, same policies, rate limit, audit |

## 9. Open items needing business confirmation

See the consolidated assumption register A-01 to A-33 in [00-decisions.md](00-decisions.md).
