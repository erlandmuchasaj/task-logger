# Tests

Loads when you work under `tests`. The commands and the "Before you say a task is done" checklist are in the root `CLAUDE.md`.

- A Feature test calls the Action itself: `resolve(CreateLog::class)->handle($actor, logData([...]))`. Build the world with the helpers in `tests/Pest.php` (`userWithRole`, `loggingSetup`, `leaveSetup`, `clientWithRates`, `projectData`, `logData`, `leaveData`, `grantDays`, `employeeData`). Roles and permissions are seeded before every Feature test.
- Name a test after the rule it proves and group it: `it('BR-06 keeps the snapshot when the rate changes', ...)->group('BR-06')`. Every BR-xx needs at least one test.
- Factories insert raw rows and skip every rule. Use them to arrange, and the Action for the behaviour under test.
- A browser test (`tests/Browser`) proves only what needs a real browser: Alpine, Livewire round trips, modals, calendars, responsive layout. The rule itself stays proven by a Feature test. Sign in with `$this->actingAs($user)` before `visit()`; use `signInThroughBrowser()` only where the login form is under test. Filament ids contain dots, so select with `[id="form.email"]`, and never `press('Sign in')` or `click('Log time')` when the same text is a heading: it hits the heading and does nothing.
- A new screen joins the role-by-URL list in its module's `ScreensTest.php`, which catches a 403, a 500 and lazy loading.
- A new business model joins the `$models` list in `tests/Feature/Audit/BusinessActivityTest.php`.
