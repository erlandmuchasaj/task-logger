# task-logger

Guide for agents working in this repository. `CLAUDE.md` only imports this file. The Laravel Boost block at the end is generated: `php artisan boost:update` replaces it on every run (and after every `composer update`), so edit only above it.

Where the two parts disagree, the hand-written part wins. Known override of the Boost block: the D-xx entries and ADRs this guide asks for count as requested documentation.

## What this is

An internal web app where employees log working hours against tasks or projects. Around that it manages clients with hourly rates, projects and budgets, leave with country rules, HR records, salaries, performance reviews, offboarding and expenses.

Four version facts change how code is written here, so trust them over memory: Filament 5 (the schemas API), Laravel 13 (model attributes such as `#[Fillable]`), Pest 5 and Tailwind 4. Exact versions are in `composer.json` and `package.json`.

## Where the truth is

| Question | Source |
|---|---|
| What should the app do? | `docs/SPEC.md`: business rules BR-xx (§6), decisions D-xx (§3.1), workflows W-xx (§7). FR-xx is in `docs/01-functional-requirements.md`, NFR-xx in `docs/02-non-functional-requirements.md`. |
| What was built, and why that way? | The code, plus the milestone notes in `docs/04-architecture.md` §8 to §19. Read the note for the module you are about to change. |
| Who may do what? | `config/permission.php` (`roles`). The grid tables in the docs are older copies. |

- A later D-xx overrides any earlier text, in the spec and in the numbered docs. Known stale passages: the Directory, removed by D-54, still appears in SPEC §5.2, §6.4 and §8, in `docs/04-architecture.md` §10 and in `docs/07-security.md` §3 and §4. `docs/07-security.md` §2 says there is no `Gate::before`, which D-41 replaced. `docs/04-architecture.md` §3 lists folders and classes that do not exist.
- Where the spec says nothing, do not fill the gap unannounced, and do not invent a field, status, enum value or rule. Choose the narrowest default, keep it behind config, an enum or a policy, record it as the next D-xx in `docs/SPEC.md` §3.1 marked "Confirm: yes", and name it in your report. Then build: the D-xx carries the question to the owner. Ask first only when the choice cannot be undone, such as removing or rewriting stored data.
- A new or changed business or access rule gets its D-xx in the same change, and an ADR in `docs/adr/` when it changes the architecture. A screen-only change needs neither.

## Modules

Business code is `app/Domain/<Module>/` (Actions, Data, Enums, Models, Policies, Support). Modules: **Identity** (the User model, permissions, roles, root access, 2FA, impersonation, audit context), **MasterData** (countries and leave policies, bank holidays, departments, technologies, labels, leave types, payment plans, settings), **People** (employee lifecycle, HR record, salaries, reviews, offboarding, anonymisation), **Clients** (customers, rates, projects, fixed costs, budget), **Work** (tasks, logs, month locks), **Leave** (requests, approvals, ledger, day counting), **Expenses**, **Exports** (queued CSV), **Reports**, **Shared** (`Money`, `Priority`, `IsoCurrency`, `LogsBusinessActivity`).

To change another module's data, call its Action (`CreateEmployee` calls `AssignUserRole` and `GrantAnnualLeave`). The `users` table is shared: Identity owns the model and People writes it. Domain code does not use `App\Filament`, except notifications that link to a screen.

## Commands

```bash
php artisan test --compact tests/Feature/Work/LogTest.php   # one file, while iterating
php artisan test --compact --group=BR-06                    # every test for one rule or decision
php artisan test --compact tests/Browser                    # the browser tests (Pest and Playwright, Chromium)
npx playwright install chromium                             # once per machine, and in CI, before the browser tests
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

- Comments explain WHY, not WHAT. No obvious docblocks and no comments that restate the code.
- Early returns and guard clauses instead of nesting. Keep files to 80-120 lines; propose a split when one grows past that.
- Where a trait needs a contract, use the trait and contract pattern with `@require-implements`.
- Imports are alphabetical. Always `use` a class and never write a fully qualified name inline (`\Throwable`, `\Carbon\Carbon`), in signatures, `new`, `instanceof`, `catch` and anonymous classes alike.
- Method order: public, protected, private. Within each, the caller comes before its callee.
- Naming: Controllers `ResourceController`, Actions `VerbNoun`, Services `NounService`, Jobs `VerbNoun`, Events `NounPastTense`, Resources `ModelResource`, Requests `VerbModelRequest`. Variables, properties and parameters are camelCase; DB columns and API fields are snake_case.
- Layout: one item per line in a multi-item array (one or two simple items may share a line), a chain of three or more calls broken across lines, a blank line after a control structure and before `return`.
- Use `=== null` and `!== null`, never `is_null()`. Never combine `?->` with `??`: `??` already has isset semantics and absorbs a null dereference at every level of the chain, so the nullsafe operator is redundant there.
- Propose improvements that go beyond the task (refactors, renames, splits) and wait for approval before making them. A gap in the spec is different: follow "Where the truth is".

### Actions, models and values

These rules are in `app/Domain/CLAUDE.md`, which loads when you work under `app/Domain`; read it first if you are about to write a business rule, a model, an enum, or money, day, hour or date arithmetic from elsewhere (a screen, a test, a migration). In short: a write that carries a business rule is an Action, a Filament create, edit or delete action never saves around one, and money, days and hours are integers.

### Authorisation

- Code checks permissions, through the `Permissions::` constants, in Policies and Gates. Role names and permission string literals stay out of `app/`; `tests/Architecture/ConventionsTest.php` and `tests/Feature/Identity/PermissionsClassTest.php` scan for both. The one file that reads a role is `ProjectManagerEligibility`, a data rule about who can be picked as a manager.
- Row rules (own record, direct manager, project member, locked month) sit in the Policy on top of the permission. The same scope goes into the resource's `getEloquentQuery()`, together with the eager loading.
- Root access: whoever holds `system.root` (the superadmin) passes every Gate check before any Policy runs, except the abilities listed in `RootAccess::POLICY_ONLY`. A rule that binds everyone ("nobody decides their own request", "only a draft can change") therefore lives in the Action, or its ability is added to `POLICY_ONLY`. A Policy that returns `false` for the superadmin does nothing, so prove such a rule with a test that acts as a superadmin.
- A new permission is a constant in `app/Domain/Identity/Permissions.php` plus an entry under each role that gets it in `config/permission.php`. The superadmin list names every permission explicitly (`tests/Feature/Identity/PermissionGridTest.php`). Then run the seeder command above.
- A new Policy is registered in `AppServiceProvider::boot()`. Models live outside `app/Models`, so Laravel does not find their policies by itself.
- Every screen is closed by default (`tests/Feature/Security/HardeningTest.php`). A resource is closed by its Policy, a relation manager by `canViewForRecord()`, and a custom page by `canAccess()` plus `abort_unless(self::canAccess(), 403)` in `mount()` and in any method that writes without an Action.
- Rates, budgets and amounts need `clients.view_rates`, salaries need `salaries.view`, and private HR fields need the `viewHrRecord` ability. This holds for every output: columns, infolists, exports, reports, emails and search. A gated value shown in a new place uses the same permission as the existing column, and a test asserts its absence for a role without it.
- Never trust a value from the browser (a Livewire property, a method argument, a query string). Check the permission again in the method, parse dates and months with `CalendarInput`, and accept an id only if it is among the choices this person has (`LogChoices`).

### Schema

- The schema is released (the owner lifted the "edit the existing migration" rule on 25 November 2026). A schema change is a new migration made with `php artisan make:migration`; a migration that has been committed is never edited. Apply it locally with `php artisan migrate`. Tests rebuild from the migrations.
- Where the database can enforce a rule, it enforces it a second time (a CHECK constraint, a composite foreign key, a unique generated column), beside the Action's friendly message.
- More migration rules (forward-only `down()`, chunked data migrations, which changes rebuild a table) are in `database/CLAUDE.md`, which loads when you work under `database`.

### Types and style

- Type every parameter, property and return, closures included: `fn (Builder $query, string $from): Builder`. Narrow an overridden return type. Type coverage sits at 97.3% against a floor of 97% (`composer test:type-coverage`), so a handful of untyped closure parameters fails the gate.
- Pint here goes beyond the Laravel preset. It adds `final` and `declare(strict_types=1)`, and it rewrites `==` to `===`, `strlen()` to `mb_strlen()` and `DateTime` to `DateTimeImmutable`. Write code that way from the start, and run the tests after Pint, because those rewrites change behavior.
- A union has two or three types at most; prefer an interface, and avoid `mixed`.
- Prefer `match` over `switch`, enums over class constants, named arguments, first-class callables, numeric separators (`1_000_000`) and property hooks for computed properties (`get =>`, `set =>`).
- Never use `assert()` to narrow a type. Use a `/** @var Type $var */` docblock.

### Tests and Filament 5

The test conventions are in `tests/CLAUDE.md` and the Filament 5 conventions in `app/Filament/CLAUDE.md`. Each loads when you work in that folder; read the file first if you are about to write a test or a screen from elsewhere.

Filament 3 or 4 code written from memory (`Filament\Forms\Form`, `Tables\Actions\EditAction`, `->actions([...])`) fails as a class-not-found error or in PHPStan. Copy a sibling resource, and use `search-docs` before an API this repo has not used yet.

## Design rules (the interface)

Screen rules (colours, badges, actions, copy, dates, navigation) are in `app/Filament/CLAUDE.md`, which loads when you work under `app/Filament`; read it first if you build a screen from elsewhere. These apply to every Blade view:

- Style with Tailwind utilities only. `theme.css` holds imports and `@source` lines, and nothing else.
- Every colour utility has its `dark:` partner. The pairs in use: secondary text `text-sm text-gray-500 dark:text-gray-400`, hairlines `border-gray-200 dark:border-white/10`. The primary colour is Amber (`primary-*`).
- Custom Blade works at phone width: grids step up with `sm:` and `lg:`, and a wide table sits in `overflow-x-auto`.

## Before you say a task is done

Work through every line. A line that does not apply is skipped on purpose, not by accident.

1. Each changed behaviour has a Feature test that is named for its rule ID, calls the Action, and includes one refused case. Test with the lowest role that should pass and with a role that should be refused, never only as the superadmin: root passes everything, so an unregistered Policy or a missing permission looks fine.
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
9. `git status` shows only files for this task. Changes that were already in the working tree are untouched, and no document nobody asked for. A schema change is a new migration, and no committed migration was edited.
10. Your report says what changed, what you verified and how, what you could not verify, and every place where the spec and the code disagreed.

## Git and writing

- Branches are `{type}/{ISSUE}`, with the type taken from the Linear label (`bug` is `bugfix/`, `task` is `task/`, `feature` or no label is `feature/`). Commit only through the `/commit` skill, which holds the message format (`ERL-1234 add user auth endpoints`); never run `git add` or `git commit` by hand.
- A PR title is `{ISSUE}: {description}`, for example `ERL-1234: Remove N+1 queries from list endpoints`.
- Run any text written for people (PR descriptions and comments, review replies, Linear comments) through the `humanizer` skill before posting.


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
