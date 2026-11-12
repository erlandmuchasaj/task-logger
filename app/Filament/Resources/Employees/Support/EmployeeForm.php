<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Support;

use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use App\Domain\People\Support\ManagerChain;
use App\Domain\People\Support\Phones;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Account, skills, HR record, emergency contacts and (on create) the first salary. Each tab is shown
 * and enabled by permission; the People actions re-check the same permissions on save (D-14).
 */
final class EmployeeForm
{
    public static function configure(Schema $schema, ?User $record, bool $creating): Schema
    {
        $user = auth()->user();
        $canAccount = $creating ? (bool) $user?->can('create', User::class) : (bool) ($record && $user?->can('updateAccount', $record));
        $canHr = (bool) $user?->can(Permissions::EMPLOYEES_UPDATE_HR_RECORD);
        $canSalary = $creating && (bool) $user?->can(Permissions::SALARIES_MANAGE);

        $tabs = [
            Tab::make('Account')->schema(self::account($record, $creating))->disabled(! $canAccount),
            Tab::make('Skills')->schema(self::skills())->disabled(! $canAccount),
        ];

        if ($user?->can(Permissions::EMPLOYEES_VIEW_HR_RECORD)) {
            $tabs[] = Tab::make('HR record')->schema(self::hrRecord())->disabled(! $canHr);
            $tabs[] = Tab::make('Emergency contacts')->schema(self::contacts())->disabled(! $canHr);
        }

        if ($canSalary) {
            $tabs[] = Tab::make('First salary')->schema(self::salary());
        }

        return $schema->components([Tabs::make('Employee')->tabs($tabs)->columnSpanFull()]);
    }

    /**
     * Dropdown options keyed by their own text (the columns are free strings, D-19), plus the current value when it is
     * not in the list so editing an older record never loses it.
     *
     * @param  array<int, mixed>  $list
     * @return array<string, string>
     */
    private static function choices(array $list, ?string $current): array
    {
        $options = array_combine(array_map(strval(...), $list), array_map(strval(...), $list));

        return filled($current) && ! isset($options[$current]) ? [$current => $current] + $options : $options;
    }

    /** @return array<int, mixed> */
    private static function account(?User $record, bool $creating): array
    {
        return [
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('surname')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)
                    ->unique('users', 'email', ignorable: $record)
                    ->helperText($creating ? 'The login. A set-password link is emailed to this address.' : null),
                Select::make('role')
                    ->options(collect(RoleName::cases())->mapWithKeys(fn (RoleName $r): array => [$r->value => Str::headline($r->value)])->all())
                    ->required()
                    ->helperText('One role per user (D-09).'),
                Select::make('country_id')->label('Country')
                    ->options(fn (): array => Country::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required($creating)->live()
                    ->afterStateUpdated(fn (mixed $state, callable $set) => $set('salary.currency', FormMapper::currencyOf($state))),
                Select::make('department_id')->label('Department')
                    ->options(fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required($creating),
                TextInput::make('position')->required($creating)->maxLength(255),
                Select::make('manager_id')->label('Manager')
                    ->options(fn (): array => User::query()->whereNull('banned_at')->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->orderBy('name')->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->getFilamentName()])->all())
                    ->searchable()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if (ManagerChain::wouldCycle($record?->getKey(), filled($value) ? (int) $value : null)) {
                            $fail('A person cannot manage themselves or their own manager.');
                        }
                    }),
                Select::make('employment_type')->options(EmploymentType::class)->required($creating),
                DatePicker::make('start_date')->required($creating),
                FileUpload::make('avatar_path')->label('Avatar')->image()->avatar()->disk('public')->directory('avatars')->maxSize(2048),
            ]),
        ];
    }

    /** @return array<int, mixed> */
    private static function skills(): array
    {
        $min = (int) config('task-logger.technology_level_min');
        $max = (int) config('task-logger.technology_level_max');

        return [
            Repeater::make('skills')->label('Technologies and levels')->defaultItems(0)->columns(2)
                ->schema([
                    Select::make('technology_id')->label('Technology')
                        ->options(fn (): array => Technology::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('level')->numeric()->integer()->minValue($min)->maxValue($max)->required()
                        ->helperText("{$min} to {$max}"),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private static function hrRecord(): array
    {
        return [
            Section::make('Private HR record')->description('Visible only to people who may view the HR record. Never shown in the Directory.')
                ->columns(2)->schema([
                    DatePicker::make('profile.date_of_birth')->label('Date of birth')->maxDate(now()),
                    TextInput::make('profile.phone')->label('Phone')->tel()->placeholder('+355 69 123 4567')
                        ->rule(fn (callable $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (filled($value) && ! Phones::isValid((string) $value, FormMapper::countryCode($get('country_id')))) {
                                $fail('Enter a valid phone number with the country code.');
                            }
                        }),
                    Select::make('profile.gender')->label('Gender')->options(Gender::class),
                    Select::make('profile.marital_status')->label('Marital status')->options(MaritalStatus::class),
                    Textarea::make('profile.address')->label('Address')->columnSpanFull(),
                    TextInput::make('profile.university')->label('University'),
                    TextInput::make('profile.field_of_study')->label('Field of study'),
                    Select::make('profile.source_of_hire')->label('Source of hire')->native(false)->placeholder('Choose')
                        ->options(fn (?string $state): array => self::choices((array) config('task-logger.source_of_hire_options'), $state)),
                    Toggle::make('profile.has_health_insurance')->label('Has health insurance'),
                    Textarea::make('profile.description')->label('Description')->columnSpanFull(),
                    KeyValue::make('profile.metadata')->label('Metadata')->columnSpanFull(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private static function contacts(): array
    {
        return [
            Repeater::make('contacts')->label('Emergency contacts')->defaultItems(0)->columns(3)
                ->schema([
                    TextInput::make('id')->hidden()->dehydratedWhenHidden(),
                    TextInput::make('name')->required(),
                    TextInput::make('phone')->tel()->required()
                        ->rule(fn (callable $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (filled($value) && ! Phones::isValid((string) $value, FormMapper::countryCode($get('../../country_id')))) {
                                $fail('Enter a valid phone number with the country code.');
                            }
                        }),
                    Select::make('relationship')->required()->native(false)
                        ->options(fn (?string $state): array => self::choices((array) config('task-logger.emergency_contact_relationships'), $state)),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private static function salary(): array
    {
        return [
            Section::make('First salary (optional)')->description('Recorded in the salary history. Later changes are new rows; history is never edited.')
                ->columns(2)->schema([
                    TextInput::make('salary.amount')->label('Amount (per month, major units)')->numeric()->minValue(0),
                    TextInput::make('salary.currency')->label('Currency (ISO 4217)')->length(3)->alpha()
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null)
                        ->helperText('Defaults to the country currency.'),
                    DatePicker::make('salary.effective_date')->label('Effective date')->default(now()),
                    Textarea::make('salary.description')->label('Note')->columnSpanFull(),
                ]),
        ];
    }
}
