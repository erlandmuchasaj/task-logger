# task-logger

Hand-written guide for agents working in this repository. The Laravel Boost block at the end of this file is generated: `php artisan boost:update` replaces it on every run, so edit only above it.

Where the two parts disagree, the hand-written part wins. Two cases exist today. A schema change edits the module's existing migration and does not use `php artisan make:migration` (see Schema). The D-xx entries and ADRs this guide asks for count as requested documentation.

## What this is

An internal web app where employees log working hours against tasks or projects. Around that it manages clients with hourly rates, projects and budgets, leave with country rules, HR records, salaries, performance reviews, offboarding and expenses.

It is one Laravel application (a modular monolith) with one Filament panel at `/app` that every role uses. MySQL stores the data; Redis holds cache, queue and sessions. There is no public API, no separate frontend and one language (English).

Four version facts change how code is written here, so trust them over memory: Filament 5 (the schemas API), Laravel 13 (model attributes such as `#[Fillable]`), Pest 5 and Tailwind 4. Exact versions are in `composer.json` and `package.json`.

## Where the truth is

| Question | Source |
|---|---|
| What should the app do? | `docs/SPEC.md`: business rules BR-xx (§6), decisions D-xx (§3.1), workflows W-xx (§7). FR-xx is in `docs/01-functional-requirements.md`, NFR-xx in `docs/02-non-functional-requirements.md`. |
| What was built, and why that way? | The code, plus the milestone notes in `docs/04-architecture.md` §8 to §19. Read the note for the module you are about to change. |
| Who may do what? | `config/permission.php` (`roles`). The grid tables in the docs are older copies. |

- A later D-xx overrides any earlier text, in the spec and in the numbered docs. Known stale passages: the Directory, removed by D-54, still appears in SPEC §5.2, §6.4 and §8, in `docs/04-architecture.md` §10 and in `docs/07-security.md` §3 and §4. `docs/07-security.md` §2 says there is no `Gate::before`, which D-41 replaced. `docs/04-architecture.md` §3 lists folders and classes that do not exist.
- `README.md`, `IDEA.md`, `PRD.md`, `TRD.md` and `docs/archive/` are history. They contradict the spec (ADR-0003), and the archive reuses BR and D numbers with other meanings. Implement nothing from them.
- Where the spec says nothing, do not fill the gap unannounced. Choose the narrowest default, keep it behind config, an enum or a policy, record it as the next D-xx in `docs/SPEC.md` §3.1 marked "Confirm: yes", and name it in your report. Then build: the D-xx carries the question to the owner. Ask first only when the choice cannot be undone, such as removing or rewriting stored data.
- A new or changed business or access rule gets its D-xx in the same change, and an ADR in `docs/adr/` when it changes the architecture. A screen-only change needs neither.

## Folder map

```
app/Domain/<Module>/        business code, one folder per module
  Actions/                  use cases (one class, one handle()) and their Validates*/Manages* traits
  Data/                     final readonly input objects handed to Actions
  Enums/  Models/  Policies/
  Support/                  calculators, lookups and helpers (LeaveSpan, BudgetCalculator, TimesheetLocks)
  Notifications/ Settings/ Contracts/ Jobs/ Exporters/    only in the modules that need them
app/Filament/Resources/<Plural>/   <Name>Resource.php, Pages/, RelationManagers/, Support/<Name>Form.php
app/Filament/Pages/         custom pages: MyDay, MyLeave, EstimateVsLogged, the settings pages, Auth/
app/Filament/Support/       shared screen helpers: ActionErrors, ExportAction, CalendarInput
app/Http/                   three middleware and one controller; the app does not grow through controllers
app/Providers/              AppServiceProvider (policies, root gate, date formats) and Filament/AppPanelProvider
config/permission.php       role => permissions          config/task-logger.php    every tunable default
database/migrations/        one create_*_tables file per module      database/settings/    spatie settings migrations
database/factories/  database/seeders/
resources/views/filament/{pages,resources,hooks}/    Blade for custom pages, calendars and banners
resources/css/filament/app/theme.css                 the panel theme: imports and @source lines only
routes/console.php          the schedule                 routes/web.php    the welcome page and one POST route
tests/Feature/<Module>/  tests/Unit/  tests/Architecture/  tests/Performance/  tests/Pest.php (shared helpers)
docs/                       SPEC.md, the numbered design docs, adr/
```

Modules: **Identity** (the User model, permissions, roles, root access, 2FA, impersonation, audit context), **MasterData** (countries and leave policies, bank holidays, departments, technologies, labels, leave types, payment plans, settings), **People** (employee lifecycle, HR record, salaries, reviews, offboarding, anonymisation), **Clients** (customers, rates, projects, fixed costs, budget), **Work** (tasks, logs, month locks), **Leave** (requests, approvals, ledger, day counting), **Expenses**, **Exports** (queued CSV), **Reports**, **Shared** (`Money`, `Priority`, `IsoCurrency`, `LogsBusinessActivity`).

To change another module's data, call its Action (`CreateEmployee` calls `AssignUserRole` and `GrantAnnualLeave`). The `users` table is shared: Identity owns the model and People writes it. Domain code does not use `App\Filament`, except notifications that link to a screen.

## Commands

```bash
php artisan test --compact tests/Feature/Work/LogTest.php   # one file, while iterating
php artisan test --compact --group=BR-06                    # every test for one rule or decision
vendor/bin/pint --dirty --format agent                      # fix style in the files you changed
composer test                                               # the whole gate that CI runs; exit code 0 means pass
npm run build                                               # rebuild CSS and assets after Blade or class changes
php artisan db:seed --class=RolesAndPermissionsSeeder       # sync roles and permissions from config; safe to repeat
```

- `composer test` runs four gates in order: `test:lint` (Pint and Rector, read-only), `test:types` (PHPStan level 7), `test:type-coverage` (minimum 97%) and `test:unit` (every Pest suite in parallel, about 40 seconds). Each can run alone, for example `composer test:types`. Run by an agent, each tool prints one JSON line: look for `"result":"passed"`. The coverage line ends with `Total: NN %` instead.
- Tests use the MySQL database `task_logger_testing` on 127.0.0.1:3306 (`phpunit.xml`). SQLite cannot stand in: the schema has CHECK constraints, generated columns and composite foreign keys.
- The app is always up at `http://task-logger.test/app`. Seeded logins: `superadmin@test.com`, `admin@test.com`, `hr@test.com`, `assistant@test.com`, `employee@test.com`, `finance@test.com`, all with password `12345678`.
- Exports are queued, on Redis locally. One stays "Queued" until a worker runs (`php artisan queue:work`).
- Three commands do more than their names say, so run them only when the owner asks: `composer lint` rewrites files across the whole repository, `composer run dev` starts `artisan serve` beside Herd, and `php artisan migrate:fresh --seed` erases the local database.
- Generators take a nested name and then land in the right folder: `php artisan make:class Domain/Work/Support/Foo`, `make:model Domain/Work/Models/Foo`, `make:test --pest Work/FooTest`. The stub is only a start; bring it to the shape of its sibling.

## Coding rules

Write new code by copying the nearest sibling. These are the decisions the siblings share.

### Actions

- A write that carries a business rule is an Action: `app/Domain/<Module>/Actions/<VerbNoun>.php` with one public `handle()`. An Action that a person triggers takes `User $actor` first, then a `Data/` object. Inside, in this order: authorise with `Gate::forUser($actor)->authorize(...)`, validate, then write inside `resolve(CauserResolver::class)->withCauser($actor, fn () => DB::transaction(...))`, so the audit names the actor. Copy `app/Domain/Clients/Actions/AddFixedCost.php`.
- A broken rule throws `ValidationException::withMessages(['field' => 'A plain sentence.'])`. Checks shared by create and update live in a `Validates<Thing>` trait beside the Actions (`ValidatesLog`).
- A check against a running total (the 24-hour day, a leave balance) first locks the person's `users` row with `lockForUpdate()`, then repeats the check inside the transaction. Copy `app/Domain/Work/Actions/CreateLog.php`.
- Scheduled work is an Action with a parameterless `handle()`, registered in `routes/console.php`, and safe to run twice.
- Emails and bell notifications go out after the transaction commits (`DB::afterCommit`, see `app/Domain/Work/Support/NotifyAssignee.php`). They are sent at once, not queued, and skip deactivated and anonymised people.
- Master data (countries, departments, technologies, labels, leave types, payment plans, bank holidays) has no Actions. It is plain Filament CRUD behind a `MasterDataPolicy` subclass; keep it that simple.

### Authorisation

- Code checks permissions, through the `Permissions::` constants, in Policies and Gates. Role names and permission string literals stay out of `app/`; `tests/Architecture/ConventionsTest.php` and `tests/Feature/Identity/PermissionsClassTest.php` scan for both. The one file that reads a role is `ProjectManagerEligibility`, a data rule about who can be picked as a manager.
- Row rules (own record, direct manager, project member, locked month) sit in the Policy on top of the permission. The same scope goes into the resource's `getEloquentQuery()`, together with the eager loading.
- Root access: whoever holds `system.root` (the superadmin) passes every Gate check before any Policy runs, except the abilities listed in `RootAccess::POLICY_ONLY`. A rule that binds everyone ("nobody decides their own request", "only a draft can change") therefore lives in the Action, or its ability is added to `POLICY_ONLY`.
- A new permission is a constant in `app/Domain/Identity/Permissions.php` plus an entry under each role that gets it in `config/permission.php`. The superadmin list names every permission explicitly (`tests/Feature/Identity/PermissionGridTest.php`). Then run the seeder command above.
- A new Policy is registered in `AppServiceProvider::boot()`. Models live outside `app/Models`, so Laravel does not find their policies by itself.
- Every screen is closed by default (`tests/Feature/Security/HardeningTest.php`). A resource is closed by its Policy, a relation manager by `canViewForRecord()`, and a custom page by `canAccess()` plus `abort_unless(self::canAccess(), 403)` in `mount()` and in any method that writes without an Action.
- Rates, budgets and amounts need `clients.view_rates`, salaries need `salaries.view`, and private HR fields need the `viewHrRecord` ability. This holds for every output: columns, infolists, exports, reports, emails and search.

### Models, enums and values

- A model is `final`, lives in `app/Domain/<Module>/Models`, declares `#[Fillable([...])]` and `#[UseFactory(...)]`, has a `casts()` method, and documents relations as `@return BelongsTo<User, $this>`. It holds relations, casts and small pure helpers. A business model has `use LogsBusinessActivity`, which audits every write.
- An enum is string-backed with TitleCase cases and implements `HasLabel`, plus `HasColor` or `HasIcon` when it shows as a badge. Allowed transitions are a `next()` method on the enum (`ExpenseStatus`).
- Money is integer minor units plus an ISO currency: `*_minor` columns and `App\Domain\Shared\Money`. Parse a typed amount with `Money::fromMajor()`, round half up with `intdiv`, and total per currency with `Money::groupByCurrency()`. Leave days are integer tenths (`app/Domain/Leave/Support/Days.php`) and time is minutes. Stored values and arithmetic are integers; a float appears only at the edge, when a value is formatted for display or an export column.
- A person's "today" is `WorkCalendar::today($user)`, in their country's timezone. `date` columns are calendar dates in that timezone; `datetime` columns are UTC.
- People and clients are deactivated, never deleted. Salaries, the leave ledger and the activity log only grow.
- A tunable default goes in `config/task-logger.php`.

### Schema

- While the schema is unreleased (the owner's rule, 12 November 2026), change the module's existing `create_*_tables` migration instead of adding a migration file, and apply the same change to the local database with SQL. Tests rebuild from the migrations.
- Where the database can enforce a rule, it enforces it a second time (a CHECK constraint, a composite foreign key, a unique generated column), beside the Action's friendly message.

### Types and style

- Type every parameter and return, closures included: `fn (Builder $query, string $from): Builder`. Type coverage sits at 97.3% against a floor of 97%, so a handful of untyped closure parameters fails the gate.
- Pint here goes beyond the Laravel preset. It adds `final` and `declare(strict_types=1)`, and it rewrites `==` to `===`, `strlen()` to `mb_strlen()` and `DateTime` to `DateTimeImmutable`. Write code that way from the start, and run the tests after Pint, because those rewrites change behaviour.

### Tests

- A Feature test calls the Action itself: `resolve(CreateLog::class)->handle($actor, logData([...]))`. Build the world with the helpers in `tests/Pest.php` (`userWithRole`, `loggingSetup`, `leaveSetup`, `clientWithRates`, `projectData`, `logData`, `leaveData`, `grantDays`, `employeeData`). Roles and permissions are seeded before every Feature test.
- Name a test after the rule it proves and group it: `it('BR-06 keeps the snapshot when the rate changes', ...)->group('BR-06')`. Every BR-xx needs at least one test.
- Factories insert raw rows and skip every rule. Use them to arrange, and the Action for the behaviour under test.
- A new screen joins the role-by-URL list in its module's `ScreensTest.php`, which catches a 403, a 500 and lazy loading.
- A new business model joins the `$models` list in `tests/Feature/Audit/BusinessActivityTest.php`.

### Filament 5

- Forms and infolists are a `Filament\Schemas\Schema` built with `->components([...])`, and layout pieces come from `Filament\Schemas\Components`. Every action, row actions included, is `Filament\Actions\*`. Tables use `->recordActions()`, a filter with fields uses `->schema()`, and icons are the `Filament\Support\Icons\Heroicon` enum.
- A screen with rules hands its input to the Action. A page overrides `handleRecordCreation()` or `handleRecordUpdate()` (`app/Filament/Resources/Logs/Pages/CreateLog.php`); a relation manager passes `->using(...)` to the built-in action (`FixedCostsRelationManager`). The form lives in `Support/<Name>Form.php`, whose `fill()` and `data()` map between the record, the form state and the `Data` object (`LogForm`).
- A select backed by an enum can return the case or its string value, so mapping code accepts both (`LogForm::data()`).
- A relation manager on a View page is read-only unless `isReadOnly()` is overridden.

## Design rules (the interface)

- Build a screen from Filament components first. Use custom Blade only for what a table or form cannot show (a calendar, a report table, balance cards), and wrap it in `<x-filament-panels::page>` and `<x-filament::section>`, with `<x-filament::button>` and `<x-form-actions>` for the button row.
- Style with Tailwind utilities only. `theme.css` holds imports and `@source` lines, and nothing else.
- Every colour utility has its `dark:` partner. The pairs in use: secondary text `text-sm text-gray-500 dark:text-gray-400`, hairlines `border-gray-200 dark:border-white/10`. The primary colour is Amber (`primary-*`).
- Meaning is carried by Filament's colour names, the same everywhere: `success` done or approved, `warning` waiting or needs care, `danger` refused, critical or destructive, `info` a neutral middle step, `gray` not started, cancelled or inactive. On the calendars amber is a bank holiday, sky is leave (a dashed outline while it is pending), red is a working day with nothing logged, and the primary ring is today.
- A status or type shows as a badge whose label and colour come from the enum (`getLabel()`, `getColor()`), never from the table definition.
- Row actions sit together in one `ActionGroup`. An action that removes or cannot be undone is `danger` or `warning`, asks with `->requiresConfirmation()`, and says in `modalDescription` what will happen in plain words. No table has bulk actions.
- Hide what a person may not use (`->visible(fn () => ...)`); do not show it disabled.
- Dates show as `dd/mm/yyyy`, set once in `AppServiceProvider`. Use a bare `DatePicker::make()`, `->date()` or `->dateTime()` with no format of its own. Durations show as `h:mm` (`Log::duration()`), money as `EUR 1200.50` (`Money::format()`), and an empty value as `—`.
- Success is a short notification in the past tense ("Log deleted"). A broken rule goes through `ActionErrors::report()` wherever there is a form to mark (a page, or a modal form with the prefix `mountedActions.0.data.`), followed by `throw new Halt`. An action with no form fields uses `ActionErrors::notify()`. `FixedCostsRelationManager` shows both.
- Copy is plain English written as string literals (this codebase does not use `__()`), in sentence case, with British spelling in what people read ("Anonymised") and American spelling in identifiers (`anonymized_at`). Helper text states the rule in one sentence: "Back-dating is allowed until the month is locked."
- Every screen joins one of the existing navigation groups: Work, Clients & projects, Leave, People, Finance, Reports, Master data, Administration.
- A list has a `->defaultSort()`. A table that can grow large uses `PaginationMode::Simple` with page sizes 25, 50 and 100.
- Custom Blade works at phone width: grids step up with `sm:` and `lg:`, and a wide table sits in `overflow-x-auto`.

## Mistakes to expect, and what to do instead

Nothing stops the first group; you have to know them.

| Mistake | Do this instead |
|---|---|
| Denying the superadmin with a Policy that returns `false`. | Root access skips the Policy. Put the rule in the Action or add the ability to `RootAccess::POLICY_ONLY`, and prove it with a test that acts as a superadmin. |
| Checking a feature only as the superadmin. | Root passes everything, so an unregistered Policy or a missing permission looks fine. Test with the lowest role that should pass and with one that should be refused. |
| Building from the README, IDEA, PRD, TRD, the archive or a stale doc passage. | Follow "Where the truth is". When a doc and the code disagree, say so in your report. |
| Filling a gap with an invented field, status, enum value or rule. | Take the narrowest default, isolate it, record it as a D-xx, and report it. |
| Saving through Eloquent, or through a default Filament create, edit or delete action, where an Action exists. | That skips authorisation, validation, the rate snapshot and the audit actor. Route the save through the Action. |
| Showing a gated value in a new place, such as a rate in an email or a salary in an export. | Gate it with the same permission as the existing column, and assert its absence for a role without that permission. |
| Adding a business model that is never audited. | Add `use LogsBusinessActivity` and the `$models` row. Before and after values are in `attribute_changes`, not `properties`. |
| `now()` or `today()` for a person's day; `$hours * $rate` or `round()` on money, days or hours. | `WorkCalendar::today($user)`; integer units and `intdiv`. The architecture test checks column types only. |
| Trusting a value from the browser: a Livewire property, a method argument, a query string. | Check the permission again in the method, parse dates and months with `CalendarInput`, and accept an id only if it is among the choices this person has (`LogChoices`). |
| Queueing an email that carries a password, or notifying inside the transaction. | Send it at once, after the commit. |
| A new colour without its `dark:` partner, or a new class that never reaches the page. | Add the pair; run `npm run build`. |

A gate catches the second group. This is what the failure means.

| Mistake | Do this instead | Caught by |
|---|---|---|
| Filament 3 or 4 code from memory: `Filament\Forms\Form`, `Tables\Actions\EditAction`, `->actions([...])`. | Copy a sibling resource. Use `search-docs` before an API this repo has not used yet. | Class-not-found errors, PHPStan |
| Reading a relation that was not eager loaded. | `->with()` in `getEloquentQuery()`, or `loadMissing()`. | Lazy loading throws outside production; `ScreensTest` |
| A closure parameter without a type. | Type it. | `composer test:type-coverage` |
| A new screen that a person with no role can open. | Close it; see Authorisation. | `HardeningTest` |
| A permission the superadmin list does not name. | Add it to every role that should hold it, the superadmin included. | `PermissionGridTest` |

## Before you say a task is done

Work through every line. A line that does not apply is skipped on purpose, not by accident.

1. Each changed behaviour has a Feature test that is named for its rule ID, calls the Action, and includes one refused case. The refusal is tested with a role that is not the superadmin.
2. The wiring for anything new is complete:
   - a model has `#[Fillable]`, `LogsBusinessActivity`, a factory and its row in `BusinessActivityTest`;
   - a permission has its constant, its entries in `config/permission.php`, and the seeder was run;
   - a Policy is registered in `AppServiceProvider`;
   - a screen is closed by default and listed in a `ScreensTest`;
   - a rule that binds everyone is in the Action or in `RootAccess::POLICY_ONLY`.
3. Rates, salaries and HR fields are still gated in every output you added or touched.
4. Arithmetic on money, days and hours is integer arithmetic, and no `now()` stands in for a person's today.
5. `vendor/bin/pint --dirty --format agent` ran, and the affected tests ran again after it.
6. `composer test` exits 0. If a gate fails, fix it or report it with its output; do not describe a failing gate as passing.
7. For a screen change: `npm run build` ran, and you opened the screen at `http://task-logger.test/app` as a role that is not the superadmin, in light and dark mode and at phone width, with no errors in `browser-logs`.
8. The spec matches the change: a new D-xx for a new or changed rule, and the passages you made stale are corrected or named in your report.
9. `git status` shows only files for this task. Changes that were already in the working tree are untouched, and there is no new migration file and no document nobody asked for.
10. Your report says what changed, what you verified and how, what you could not verify, and every place where the spec and the code disagreed.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== spatie/laravel-activitylog/core rules ===

## Activitylog

- `spatie/laravel-activitylog` logs model events and manual activities to a database table, with subjects, causers, tracked attribute changes, and custom properties.
- Always activate the `activitylog-development` skill when working with activity logging or any code that uses the `LogsActivity`, `CausesActivity`, or `HasActivity` traits, `LogOptions`, the `Activity` model, or the `activity()` helper.
- If the skill is unavailable, read `vendor/spatie/laravel-activitylog/resources/boost/skills/activitylog-development/SKILL.md` before making changes.

</laravel-boost-guidelines>
