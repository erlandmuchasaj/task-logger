# Filament 5

Loads when you work under `app/Filament`. The project-wide rules are in the root `AGENTS.md`; the interface rules (colours, copy, navigation) are under "Design rules" below. Tailwind-only styling, `dark:` pairs and phone width stay in the root file because they also apply to Blade views.

- Forms and infolists are a `Filament\Schemas\Schema` built with `->components([...])`, and layout pieces come from `Filament\Schemas\Components`. Every action, row actions included, is `Filament\Actions\*`. Tables use `->recordActions()`, a filter with fields uses `->schema()`, and icons are the `Filament\Support\Icons\Heroicon` enum.
- A screen with rules hands its input to the Action. A page overrides `handleRecordCreation()` or `handleRecordUpdate()` (`app/Filament/Resources/Logs/Pages/CreateLog.php`); a relation manager passes `->using(...)` to the built-in action (`FixedCostsRelationManager`). The form lives in `Support/<Name>Form.php`, whose `fill()` and `data()` map between the record, the form state and the `Data` object (`LogForm`).
- A select backed by an enum can return the case or its string value, so mapping code accepts both (`LogForm::data()`).
- A relation manager on a View page is read-only unless `isReadOnly()` is overridden.

## Design rules (the interface)

- Build a screen from Filament components first. Use custom Blade only for what a table or form cannot show (a calendar, a report table, balance cards), and wrap it in `<x-filament-panels::page>` and `<x-filament::section>`, with `<x-filament::button>` and `<x-form-actions>` for the button row.
- Meaning is carried by Filament's colour names, the same everywhere: `success` done or approved, `warning` waiting or needs care, `danger` refused, critical or destructive, `info` a neutral middle step, `gray` not started, cancelled or inactive. On the calendars amber is a bank holiday, sky is leave (a dashed outline while it is pending), red is a working day with nothing logged, and the primary ring is today.
- A status or type shows as a badge whose label and colour come from the enum (`getLabel()`, `getColor()`), never from the table definition.
- Row actions sit together in one `ActionGroup`. An action that removes or cannot be undone is `danger` or `warning`, asks with `->requiresConfirmation()`, and says in `modalDescription` what will happen in plain words. No table has bulk actions.
- Hide what a person may not use (`->visible(fn () => ...)`); do not show it disabled.
- Dates show as `dd/mm/yyyy`, set once in `AppServiceProvider`. Use a bare `DatePicker::make()`, `->date()` or `->dateTime()` with no format of its own. Durations show as `h:mm` (`Log::duration()`), money as `EUR 1200.50` (`Money::format()`), and an empty value as `—`.
- Success is a short notification in the past tense ("Log deleted"). A broken rule goes through `ActionErrors::report()` wherever there is a form to mark (a page, or a modal form with the prefix `mountedActions.0.data.`), followed by `throw new Halt`. An action with no form fields uses `ActionErrors::notify()`. `FixedCostsRelationManager` shows both.
- Copy is plain English written as string literals (this codebase does not use `__()`), in sentence case, with British spelling in what people read ("Anonymised") and American spelling in identifiers (`anonymized_at`). Helper text states the rule in one sentence: "Back-dating is allowed until the month is locked."
- Every screen joins one of the existing navigation groups: Work, Clients & projects, Leave, People, Finance, Reports, Master data, Administration.
- A list has a `->defaultSort()`. A table that can grow large uses `PaginationMode::Simple` with page sizes 25, 50 and 100.
