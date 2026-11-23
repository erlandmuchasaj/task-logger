# Filament 5

Loads when you work under `app/Filament`. The project-wide rules are in the root `CLAUDE.md`; the interface rules (colours, copy, navigation) are under "Design rules" there.

- Forms and infolists are a `Filament\Schemas\Schema` built with `->components([...])`, and layout pieces come from `Filament\Schemas\Components`. Every action, row actions included, is `Filament\Actions\*`. Tables use `->recordActions()`, a filter with fields uses `->schema()`, and icons are the `Filament\Support\Icons\Heroicon` enum.
- A screen with rules hands its input to the Action. A page overrides `handleRecordCreation()` or `handleRecordUpdate()` (`app/Filament/Resources/Logs/Pages/CreateLog.php`); a relation manager passes `->using(...)` to the built-in action (`FixedCostsRelationManager`). The form lives in `Support/<Name>Form.php`, whose `fill()` and `data()` map between the record, the form state and the `Data` object (`LogForm`).
- A select backed by an enum can return the case or its string value, so mapping code accepts both (`LogForm::data()`).
- A relation manager on a View page is read-only unless `isReadOnly()` is overridden.
