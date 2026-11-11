# task-logger
- A task logger  for employees to logg their daily work. 



## Web Application: task-logger
---------------------
I want to build an application that employees of a company can log their working  hours, using the TALL Stack (Tailwind, Alpine.js, Livewire, Laravel) paired with an admin panel generator like Filament.
The application should have users, user skills, roles & permissions, clients, projects, tasks, logs, PTO requests management, etc. 


## Tech Stack:
------------
TALL Stack (Tailwind, Alpine.js, Livewire, Laravel) paired with an admin panel generator like Filament.
MySQL
Redis
Supervisor

## Context & permission:

### superadmin:
- Can create new users/employees
- Can create new clients
- Can create new projects
- Can create PTO requests for employee and approve them
- Can create payment plans (these are used when creating clients so that when should that client pay ex: 30 days after invoice, at the ned of the month, prepayment, 15 days after invoice etc)
- Can create department (These are used when creating users so to group them into departments)
- Can create countries (These are used when creating user to know, when company is international, which country these employee belong to so to also know the national holidays of that country for those employees. When creating country can set how many PTO offers per year, based on the country, how many Sick Leave days per year)
- Can create skills (These are used when creating employees. So one employee can have multiple skills and with different levels. ex: Employee John knows Angular at level 10 and Laravel at level 7 but Janet knows .Net at level 8 and angular at level 9. Is kind of ManyToMany relationship between employees and skills.)
- Can create areas (These are used when creating customers/clients and projects, and works kind of the same as skills per employees/user. So when creating the client we select MTM multiple areas (.Net, Angular, Ionic) and per each we also set the hourly rate for this client (.Net 24/h, Angular 20/h, Ionic 18/h). Than when we create a project for this client, we only see the areas we already selected (.Net, Angular, Ionic), and select only the areas or technologies that this specific project consist of. it could have only Angular and .Net and than we set how many hours is estimated per each area. For example angular 230h and .Net 330h).
- Can create new tasks (When creating a task you select the project and area of that task and give an estimation for that task and then assign to an employee with a title and description of the task and than when employee login  they will see it to the list of their tasks, and can log hours to that task.)
- Can also create vacation days. So for example admin can  create all national holidays (bank holidays) for all the countries, so he select the country the date and the name of holiday so it will show in calendar.
- Also can create directly Day of for an employee. For example he didn't came at work and has no access on the platform. He can inform the admin and than he can set it as day off, and will be deduct from his total PTO or Sick Leave.
- Can export the list of all logs


### admin:
- Can create/edit and activate/deactivate customers
- Can CRUD and change status of projects 
- Can CRUD and change status of tasks
- Can assign task
- Can log hours for his own tasks
- Can request days off for himself
- Can see the list of all the logs and also his own logs
- Can see a list of all tasks and also only tasked assigned to him.


### employees:
- Can see all employees and view them 
- Can see only his tasks and his logs
- Can add new logs to his tasks
- Can request his PTO for approval
- Create a request for days off
- Can view projects
- Can view customers
- Can view tasks
- Can view logs


### assistant:
- Can add, skills, areas, countries, departments
- Can view/export projects
- Can view/export customers
- Can view/export tasks
- Can view/expory logs


When creating Roles and permission use a permission grid so always start from permission first. 


## Business Entities

### User (users) - These are the employees
- name, surname, email, date of birth, country_id (selected from countries list), phone number, gender, address, university, field of study, martial status, Emergency contacts(name,phone, relation to the user, like spause, brother/.sister etc/), role,  employement_type (full_time, part_time, contractor, freelance, intern), source of hire, helth-insurance T/F, department (list from a dropdown from departmens),  position (job_title), manager (manager_id from users), start_date, total_days_off, salary,  description, skills (many to many with also a level) and an avatar.
- Maybe create other tables such as: users, profiles, skills_users or any other if neede for optimized table normalization. salaries (salaries ar kept in a different table to also keep track of history of salaires).
- Also possibility to add metadata as key->value pair. 

### Salary (salaries)
- user_id, created_by, amount, effective_date, status, description

### EmployeeOffBoarding (offbordings)
- user_id, created_by, end_Date, equipment_returned (T/F), resignation_letter_signed (T/F), description, status, created_at, updated_at


### Customer (customers) or (clients)
- created_by, name, surname, email, country, phone, addres, company name,priority (low, medium, high, critical), payment_plan_id (related belongs to a payment_plans table with FK).  areas (Many to Many) and an avatar.

### PaymentPlan (payment_plans)
- days, description, etc

### Project (projects)
- created_by, customer_id, project_type (standalone, recuring, pay_as_you_go, etc (maybe enum list, or FK table)), name, slug, description, manager (list of employees only those ith the role admin), client, budget, estimated_hours, deadline (start_date - end_date), areas (many to many), can_log_directly_to_project (This mean if an employee can log directly to a project so no task is needed, so when creating a log,  if he check a checkbox, than only project where you can log dirrectly are shown and you pick one of these project and log the hours). status (planing, active, on_hold, completed, cancelled), priority (low, medium, high, critical), color

### FixedCost (fixed_costs)
Another thing to consider here is that a project has many fixed_costs. These are added by superadmin to specific project on specific month. For example you can add management, 100EUR, fixed costs for september. 
- name, date, amount/value, project_id.

### Task (tasks)
- created_by, name, description, project (project_id), area (area from the project areas list for this task. in general one area per task), status (such as Backlog, ToDo, InProgress, InReview, Completed, Cancelled, etc), priority (Low, medium, high, critical), hours (estimated hours per task), user_id (to whome this task is assignet to), parent_id when task are related, default to null. force_to_log (means user must log to this task), due_date, labels (many to mny relationship with labels such as, Bug, Feature, Design, Testing, Refactoring, enhancement, Documentation etc. )

### Log (logs)
- user_id (the person makking the log), date (can use created_at as the time of logg), project_id, task_id, hours, minutes, log_type (normal_hours, extra_hours, replacement_hours, others), description

### BankHoliday (bank_holidays) - Holds bank holidays per each contry.
- name, start_date, end_date, country_id, description, etc

### LeaveRequest (leave_requests)
- user_id (employee), created_by, leave_type_id (annual_leave, sick_leave, personal_leave, unpaid_leave, parental_leave) , start_date, end_date, days_requested (calculated from start/end dates, also half day supported), reason, status (pending, approved, rejected, taken, cancelled),
- reviewed_by, reviewed_at, review_note  (this is required if the leave request is denied)

### Country (countries)
- name, days_off per year, sick leave, code (AL), prefix (+355), timezone (Europe/­Tirane), currency (ALL),  etc.

### Performance (performances)
These are employee performance evaluation. that should be filled by admin or superadmin per each performance review
- id, user_id (which is the employee), created_by (or performed_by),  performance value (1-10), notes/description etc 

### Department (departments)
These are the depatment groupng that employees belong to. 
- id, parent_id, name, slug, description, color, is_active

### Area (areas)
These are areas a client operates to and is Many to many for clinet and area
id, name, description

### Skill (skills or technologies)
These are employee skills selected when you add a new employee. And are many to many 
id, name, description

### Expense (expenses) (we can also add employee expenses)
id, user_id (the employee), created_by (the admin or manager creating the expense), project_id (This is optional), category (Travel, Meals, Supplies, Equipment, Software, Other), description, number (This is a autogenerated number ex: EXP-000001), receipt_path (maybe a file upload), notes, statu (Draft, Submitted, Approved, Rejected, Reimbursed)

### ExpenseLine (expense_lines) one Expense may have multiple items same as product cart.
id, description, quanitty, unit_price, amount (quantity * price), currency,  date

### ActivityLog (activity_logs) - spatie package


NOTE: the table names and column names are just for example. you can rename and use them following best practices and conventions.


Some FAQ-s:
- logging is manual
- time log do not require manager approval
- the app will be in english only for the first release
- Only assigned project members can directly log to a project. 
- One assignee  per task
- The leaves are approved by direct manager and HR.
- HR is a user with role amdina and the deppartment of HR, or you can create a new role for HR called `hr`.
- Annual leave and sick leave rules are based on country policy with effective dates, confirmed entitelment, proration, carry-over and expire by country.
- In some countries holidays exclude weekends and are carried over. so consider that as well. 
- Currency is base on country for example: Kosovo has EUR, Albania has ALL.
- The budget on a project is more like estimated hours and based on hours and rate is entered the budget. 
- Payment plan are just metadata and no invoice is generated (maybe for V2)
- Priority is per client than per project. 
- Performance reviews can be seen only by superadmin, HR and the managing person. 
- Equiprment return is a boolean value it only means if the person has returned his working laptop or other equipment given by the company
- When an Employee lives  retain business/audit history, and anonimize email, phone number and any other personal data after a specific period. 
- areas, skills can also be merged into One technologies table. Users link with a level, clients with a rate, projects with estimated hours
- Keep track of client hourly rate change. client_technology_rates with effective_from; each time entry snapshots the rate it was logged at.
- Count working days only, skip the employee's country holidays, AM/PM half days, grant on 1 Jan prorated by start date, no carry-over (setting)
- Split: public profile (name, role, department, skills, avatar) vs private HR record (policy-guarded, superadmin only)
- No timesheet lock? Superadmin locks a month; edits to locked entries need an admin and are activity-logged
- force to log:  Pinned task that appears in every assignee's daily log form until closed.
- Keep payment plans as client metadata only; invoicing is a non-goal for v1
- Money stored as integer minor units + ISO currency per client and per salary; reports group by currency

Some packages that can be used but not limited to and not all required.
Note that you can use other packages that you see fit for the occasion As long as the packages are well maintained and up to date.

-----------------------------
barryvdh/laravel-debugbar
404labfr/laravel-impersonate 
cybercog/laravel-ban
nomaduro/larastan
barryvdh/laravel-ide-helper
propaganistas/laravel-phone
spatie/laravel-activitylog
spatie/laravel-permissions
spatie/laravel-sluggable
spatie/laravel-settings
spatie/laravel-navigation
Filament Admin 




## Workflow:
----------
INITIALY:
Define
- Business requirement
- Functional Requirement
- Non-Functional requirement
- Domain Analysis - Identify business entities, workflows and relationships
- Architecture - Decide whether a modular monolith or microservices makes sense.
- Database Design - Define entities, relationships, constraints and expected access patterns.
- API design - Define contracts, versioning and communication.
- Security - Authentication, authorization, data protection and auditing.
- Project organization - Coding standards, branching strategy, environments and CI/CD.
- Scalability and performance - Estimate expected load and identify potential bottlenecks.
- Testing - Unit, integration, end-to-end and performance testing



## DEMO filament:
==============
```
Github: https://github.com/filamentphp/demo
URL: https://demo.filamentphp.com/login
Email: admin@filamentphp.com
Password: demo.Filament@2021!
```


```

You are a senior Laravel engineer building "task-logger" from docs/SPEC.md.

Read the whole spec before acting. Treat it as follows:
- Untagged statements are requirements.
- [DERIVED] items are required consequences of stated rules.
- [ASSUMED D-xx] items are defaults you must implement but isolate behind config, enums or policies.
- [OPTIONAL] items wait until the core milestones are done.
- Do not invent fields, enum values or behaviour. If something is missing or contradictory, write an ADR in docs/adr/ and extend the decision log instead of choosing silently.

Step 1 (Phase 0 only): produce the 11 documents in section 10 under docs/. Then stop and list every decision marked "Confirm: yes" that blocks Milestone 1.

Step 2 (after I approve): implement milestones M0 to M5 in order. After each milestone, run Larastan and the test suite, list which BR-xx rules have tests, and summarize any deviation from the spec.

Constraints: permission-first RBAC (Policies check permissions, never role names); money as integer minor units plus ISO currency; every business write audited with spatie/laravel-activitylog; verify package names on Packagist before installing.
```