<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\Settings\SecuritySettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Superadmin control of two-factor authentication (ADR-0005, D-26): switch it on or off globally,
 * require it for everyone, and set the grace period. Perm two_factor.manage.
 */
class TwoFactorSettings extends Page
{
    protected string $view = 'filament.pages.two-factor-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Two-factor authentication';

    protected static ?string $title = 'Two-factor authentication';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::TWO_FACTOR_MANAGE);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(SecuritySettings::class);

        $this->getSchema('form')?->fill([
            'two_factor_enabled' => $settings->two_factor_enabled,
            'two_factor_required' => $settings->two_factor_required,
            'two_factor_grace_days' => $settings->two_factor_grace_days,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $enrolled = User::query()->whereNotNull('app_authentication_secret')->count();
        $total = User::query()->count();

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Availability')
                    ->description("{$enrolled} of {$total} users have set up an authenticator app.")
                    ->schema([
                        Toggle::make('two_factor_enabled')
                            ->label('Enable two-factor authentication')
                            ->helperText('When off, nobody is asked for a code and nobody can enrol. Existing enrolments are kept.')
                            ->live(),
                        Toggle::make('two_factor_required')
                            ->label('Require it for all users')
                            ->helperText('Users who have not enrolled get the grace period below, then must enrol before using the app.')
                            ->visible(fn ($get): bool => (bool) $get('two_factor_enabled'))
                            ->live(),
                        TextInput::make('two_factor_grace_days')
                            ->label('Grace period (days)')
                            ->helperText('Counted from when the requirement is switched on, or from the day a user is created if later.')
                            ->numeric()->integer()->minValue(0)->maxValue(90)->required()
                            ->visible(fn ($get): bool => (bool) $get('two_factor_enabled') && (bool) $get('two_factor_required')),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->getSchema('form')?->getState() ?? [];
        $settings = app(SecuritySettings::class);
        $before = [
            'two_factor_enabled' => $settings->two_factor_enabled,
            'two_factor_required' => $settings->two_factor_required,
            'two_factor_grace_days' => $settings->two_factor_grace_days,
        ];

        $enabled = (bool) ($state['two_factor_enabled'] ?? false);
        $required = $enabled && (bool) ($state['two_factor_required'] ?? false);

        // The grace period counts from the moment the requirement is switched on.
        if ($required && ! $settings->two_factor_required) {
            $settings->two_factor_required_since = now()->toIso8601String();
        } elseif (! $required) {
            $settings->two_factor_required_since = null;
        }

        $settings->two_factor_enabled = $enabled;
        $settings->two_factor_required = $required;
        $settings->two_factor_grace_days = (int) ($state['two_factor_grace_days'] ?? $settings->two_factor_grace_days);
        $settings->save();

        activity('security')
            ->withChanges([
                'old' => $before,
                'attributes' => [
                    'two_factor_enabled' => $settings->two_factor_enabled,
                    'two_factor_required' => $settings->two_factor_required,
                    'two_factor_grace_days' => $settings->two_factor_grace_days,
                ],
            ])
            ->event('updated')
            ->log('updated');

        Notification::make()->title('Two-factor settings saved')->success()->send();
    }
}
