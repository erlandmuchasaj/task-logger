<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Permissions;
use App\Domain\MasterData\Settings\LeaveSettings;
use App\Domain\MasterData\Settings\PrivacySettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** App settings (SPEC 2.3): retention period and the leave carry-over default. Perm settings.manage. */
final class ManageSettings extends Page
{
    /** @var array<string, mixed> */
    public ?array $data = [];

    protected string $view = 'filament.pages.manage-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::SETTINGS_MANAGE);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->getSchema('form')?->fill([
            'anonymize_after_months' => resolve(PrivacySettings::class)->anonymize_after_months,
            'carry_over_default' => resolve(LeaveSettings::class)->carry_over_default,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Privacy and retention')->schema([
                    TextInput::make('anonymize_after_months')
                        ->label('Anonymise personal data after (months)')
                        ->helperText('Counted from the offboarding end date (D-15, BR-28).')
                        ->numeric()->integer()->minValue(0)->maxValue(120)->required(),
                ]),
                Section::make('Leave')->schema([
                    Toggle::make('carry_over_default')
                        ->label('Carry over unused leave by default')
                        ->helperText('Default for new country policies; each country policy can override it (D-08).'),
                ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $state = $this->getSchema('form')?->getState() ?? [];

        $privacy = resolve(PrivacySettings::class);
        $privacy->anonymize_after_months = (int) $state['anonymize_after_months'];
        $privacy->save();

        $leave = resolve(LeaveSettings::class);
        $leave->carry_over_default = (bool) $state['carry_over_default'];
        $leave->save();

        activity('settings')
            ->withChanges(['attributes' => $state])
            ->event('updated')
            ->log('updated');

        Notification::make()->title('Settings saved')->success()->send();
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Save')->submit('save')];
    }
}
