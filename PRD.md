## PRD: Product requirements

**task-logger** is an internal web app where employees log hours against client projects and tasks, request leave, and where managers see estimate vs actual per project and technology.

### Problem

Hours, leave and project budgets live in spreadsheets and chat. Nobody can answer, without manual work: how many Angular hours are left on project X, what a month cost a client, or how many PTO days an employee has today.

### Goals (v1)

1. Every employee logs hours daily in under 60 seconds per entry.
2. Estimate vs actual hours per project and per technology, live, no spreadsheet.
3. Leave balance per employee always correct, with country holidays applied automatically.
4. Monthly log export per client and project, matching what is billed.
5. Roles and permissions managed from a permission grid, not code.

### Non-goals (v1)

- Invoicing and payments (payment plans are stored, not executed)
- Payroll calculation (salary history is stored, not paid)
- Time tracking
- Client portal or client logins
- Native mobile app (responsive web only)

### Roles

| Role | Who | Core job in the app |
| --- | --- | --- |
| Superadmin | Owner, operations lead | Everything, including salaries, entitlements, locks, offboarding |
| Admin | Team leads, project managers | Run clients, projects and tasks; approve their team's leave; log own hours |
| Employee | Developers, designers | Log hours on own tasks, request leave, browse directory and projects |
| HR | HR | Soemthing similar to admin |
| Assistant | Office / admin staff | Maintain lookup data, view and export everything operational |

Roles are presets of permissions. The grid edits permissions; roles are just named bundles of them.

### Permission matrix (v1 defaults)

C = create, R = read, U = update, D = delete, X = export, A = approve. "Own" = records assigned to or created by the user.

| Area | Superadmin | Admin | Employee | Assistant |
| --- | --- | --- | --- | --- |
| Users: public profile | CRUD | R | R | R |
| Users: private HR record, salaries | CRUD | – | – | – |
| Offboarding, ban/unban | CRUD | – | – | – |
| Impersonate | yes | – | – | – |
| Performance reviews | CRUD | CRU (own team) | R (own) | – |
| Clients | CRUD | CRU + activate | R | RX |
| Client rates | CRUD | CRU | – | R |
| Projects + estimates | CRUD | CRUD + status | R | RX |
| Project fixed costs | CRUD | R | – | R |
| Tasks | CRUD | CRUD + assign | R (own) | RX |
| Time entries | CRUD + X + lock | CRU own, R all, X | CRU own (unlocked) | RX |
| Leave requests | CRUD + A | C own, A team | C own, R own | R |
| Manual day off for a user | C | C (own team) | – | – |
| Countries, holidays | CRUD | R | R | CRUD (not entitlements) |
| Departments, technologies, payment plans, leave types | CRUD | R | R | CRUD |

### Functional requirements

| ID | Module | Requirement | Priority |
| --- | --- | --- | --- |
| F1 | Users | Create, edit, deactivate users with public profile, private HR record, emergency contacts, avatar | Must |
| F2 | Users | Many technologies per user with level 1–10 | Must |
| F3 | Users | Salary history: amount, currency, effective date, status; current salary = latest effective | Must |
| F4 | Users | Offboarding checklist; completing it bans login and ends open assignments | Should |
| F5 | Clients | Client with payment plan, priority, technologies with effective-dated hourly rate | Must |
| F6 | Projects | Project with type, manager (admin only), budget, deadline, technologies limited to the client's, estimated hours per technology | Must |
| F7 | Projects | `can_log_directly` projects accept time entries without a task | Must |
| F8 | Projects | Monthly fixed costs per project | Should |
| F9 | Tasks | Task with project, technology (limited to the project's), estimate, assignee, status, parent task, force-to-log flag | Must |
| F10 | Time | Log date, hours (0.25 steps), task or direct project, description; rate snapshotted | Must |
| F11 | Time | Block logging on locked months, inactive projects, closed tasks; warn over 12 h/day | Must |
| F12 | Leave | Leave types (PTO, sick, unpaid…), request with half-day AM/PM, approval, cancellation, balance ledger | Must |
| F13 | Leave | Country holidays and approved leave on one team calendar | Must |
| F14 | Leave | Admin records a day off for an absent employee; deducts from the chosen leave type | Must |
| F15 | Reviews | Performance review: score 1–10, reviewer, notes, date | Should |
| F16 | Reports | Dashboard: hours this month, estimate vs actual per project and technology, budget burn, utilization | Must |
| F17 | Export | Queued CSV/XLSX export of time entries filtered by client, project, user, month | Must |
| F18 | Audit | Activity log on users, salaries, rates, time entries, leave | Must |
| F19 | Auth | Email login, password reset, MFA for superadmin and admin | Should |
| F20 | Notifications | Email + in-app: leave submitted, approved, rejected; task assigned | Should |

### Key acceptance criteria

- A 5-day PTO request spanning a national holiday of the employee's country deducts 4 days.
- Changing a client's Angular rate from 20 to 24 effective 1 Dec leaves November's billed totals unchanged.
- An employee opening another employee's profile never receives salary, DOB, gender, marital status or health fields, in the UI or in any Livewire payload.
- An employee cannot create, edit or delete a time entry in a locked month, and the API refuses it too, not only the button.
- A project's technology picker shows only the technologies chosen on its client.
- Export of 50,000 time entries runs in the background and emails a download link.

### Success metrics (90 days after launch)

- 95% of working days have at least one time entry per active employee.
- Zero spreadsheet-based leave balances in use.
- Monthly client export produced in under 5 minutes of human time.

