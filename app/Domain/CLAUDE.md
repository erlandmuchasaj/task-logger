# Domain

Loads when you work under `app/Domain`. The project-wide rules, the permission rules and the "Before you say a task is done" checklist are in the root `AGENTS.md`.

## Actions

- A write that carries a business rule is an Action: `app/Domain/<Module>/Actions/<VerbNoun>.php` with one public `handle()`. An Action that a person triggers takes `User $actor` first, then a `Data/` object. Inside, in this order: authorise with `Gate::forUser($actor)->authorize(...)`, validate, then write inside `resolve(CauserResolver::class)->withCauser($actor, fn () => DB::transaction(...))`, so the audit names the actor. Copy `app/Domain/Clients/Actions/AddFixedCost.php`.
- Where an Action exists, save through it. Eloquent or a default Filament create, edit or delete action skips authorisation, validation, the rate snapshot and the audit actor.
- A broken rule throws `ValidationException::withMessages(['field' => 'A plain sentence.'])`. Checks shared by create and update live in a `Validates<Thing>` trait beside the Actions (`ValidatesLog`).
- A check against a running total (the 24-hour day, a leave balance) first locks the person's `users` row with `lockForUpdate()`, then repeats the check inside the transaction. Copy `app/Domain/Work/Actions/CreateLog.php`.
- Scheduled work is an Action with a parameterless `handle()`, registered in `routes/console.php`, and safe to run twice.
- Emails and bell notifications go out after the transaction commits (`DB::afterCommit`, see `app/Domain/Work/Support/NotifyAssignee.php`). They are sent at once, never queued (an email can carry a password), and skip deactivated and anonymised people.
- Master data (countries, departments, technologies, labels, leave types, payment plans, bank holidays) has no Actions. It is plain Filament CRUD behind a `MasterDataPolicy` subclass; keep it that simple.

## Models, enums and values

- A model is `final`, lives in `app/Domain/<Module>/Models`, declares `#[Fillable([...])]` and `#[UseFactory(...)]`, has a `casts()` method, and documents relations as `@return BelongsTo<User, $this>`. It holds relations, casts and small pure helpers. A business model has `use LogsBusinessActivity`, which audits every write, and a row in the `$models` list of `BusinessActivityTest`. Before and after values are in `attribute_changes`, not `properties`.
- Treat N+1 as a bug. Eager load every relation read in a loop, collection or resource (`->with('relation.nested')`, `withCount()`, `loadMissing()`). Lazy loading throws outside production, and each module's `ScreensTest` catches it.
- An enum is string-backed with TitleCase cases, is cast in `casts()`, and implements `HasLabel`, plus `HasColor` or `HasIcon` when it shows as a badge. Allowed transitions are a `next()` method on the enum (`ExpenseStatus`).
- Money is integer minor units plus an ISO currency: `*_minor` columns and `App\Domain\Shared\Money`. Parse a typed amount with `Money::fromMajor()`, round half up with `intdiv`, and total per currency with `Money::groupByCurrency()`. Leave days are integer tenths (`app/Domain/Leave/Support/Days.php`) and time is minutes. Stored values and arithmetic are integers; a float appears only at the edge, when a value is formatted for display or an export column. No `$hours * $rate` and no `round()` on money, days or hours; the architecture test checks column types only.
- A person's "today" is `WorkCalendar::today($user)`, in their country's timezone, never `now()` or `today()`. `date` columns are calendar dates in that timezone; `datetime` columns are UTC.
- People and clients are deactivated, never deleted. Salaries, the leave ledger and the activity log only grow.
- A tunable default goes in `config/task-logger.php`.
