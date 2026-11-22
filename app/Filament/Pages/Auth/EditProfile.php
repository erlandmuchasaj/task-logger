<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\UpdateOwnProfile;
use App\Domain\People\Data\OwnProfileData;
use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use App\Domain\People\Support\Phones;
use App\Filament\Support\ActionErrors;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lab404\Impersonate\Services\ImpersonateManager;
use SensitiveParameter;

/**
 * A user manages their own details here, their password and their two-factor authentication. D-77: name, email,
 * phone, date of birth, university, address, gender and marital status are editable (through UpdateOwnProfile);
 * role, manager, position and employment type are shown and stay read-only. Replaces D-33.
 */
final class EditProfile extends BaseEditProfile
{
    /** D-47: while someone acts as this user they cannot change the user's details, password or two-factor setup. */
    public static function canAccess(): bool
    {
        return ! resolve(ImpersonateManager::class)->isImpersonating();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Your details')->columns(2)->schema([
                $this->getNameFormComponent(),
                TextInput::make('surname')->maxLength(255),
                $this->getEmailFormComponent()->columnSpanFull(),
                DatePicker::make('date_of_birth')->label('Date of birth')->maxDate(now()),
                TextInput::make('phone')->label('Mobile phone')->tel()
                    ->prefix(fn (): ?string => $this->getUserModel()->country?->prefix)
                    ->helperText('A mobile number of your country, with or without the leading 0.'),
                Select::make('gender')->label('Gender')->options(Gender::class),
                Select::make('marital_status')->label('Marital status')->options(MaritalStatus::class),
                TextInput::make('university')->label('University'),
                Textarea::make('address')->label('Address')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Your role')->description('Set by HR. Ask them if something here is wrong.')->columns(2)->schema([
                TextInput::make('role_label')->label('Role')->disabled()->dehydrated(false),
                TextInput::make('manager_label')->label('Manager')->disabled()->dehydrated(false),
                TextInput::make('position')->label('Position')->disabled()->dehydrated(false),
                TextInput::make('employment_type_label')->label('Employment type')->disabled()->dehydrated(false),
            ]),
            Section::make('Password')->columns(2)->schema([
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent()->columnSpanFull(),
            ]),
        ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToDashboard')->label('Back to dashboard')->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(fn (): string => filament()->getUrl()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getUserModel();
        $profile = $user->profile;

        return [
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'date_of_birth' => $profile?->date_of_birth?->toDateString(),
            'phone' => Phones::forCountryField($profile?->phone, $user->country?->code),
            'gender' => $profile?->gender?->value,
            'marital_status' => $profile?->marital_status?->value,
            'university' => $profile?->university,
            'address' => $profile?->address,
            'role_label' => Str::headline((string) $user->getRoleNames()->first()),
            'manager_label' => $user->manager?->getFilamentName() ?? '—',
            'position' => $user->position ?? '—',
            'employment_type_label' => $user->employment_type?->getLabel() ?? '—',
        ];
    }

    /**
     * D-77: the details go through UpdateOwnProfile. D-53: setting a password here also clears the forced-change flag.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        abort_unless($record instanceof User && self::canAccess(), 403);

        try {
            $record = resolve(UpdateOwnProfile::class)->handle($record, $this->toData($data));
        } catch (ValidationException $validationException) {
            ActionErrors::report($this, $validationException);
            throw new Halt;
        }

        if (array_key_exists('password', $data)) {
            $record->forceFill(['password' => $data['password']]);

            if ($record->must_change_password) {
                $record->must_change_password = false;
            }

            $record->saveQuietly();
        }

        return $record;
    }

    /** @param  array<string, mixed>  $data */
    private function toData(#[SensitiveParameter] array $data): OwnProfileData
    {
        $currentPassword = $this->data['currentPassword'] ?? null;

        return new OwnProfileData(
            name: (string) ($data['name'] ?? ''),
            surname: isset($data['surname']) ? (string) $data['surname'] : null,
            email: (string) ($data['email'] ?? ''),
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            dateOfBirth: filled($data['date_of_birth'] ?? null) ? CarbonImmutable::parse((string) $data['date_of_birth']) : null,
            university: isset($data['university']) ? (string) $data['university'] : null,
            address: isset($data['address']) ? (string) $data['address'] : null,
            gender: $this->enumOf(Gender::class, $data['gender'] ?? null),
            maritalStatus: $this->enumOf(MaritalStatus::class, $data['marital_status'] ?? null),
            currentPassword: is_string($currentPassword) ? $currentPassword : null,
        );
    }

    /**
     * A select backed by an enum returns the case or its value, so both are accepted.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumOf(string $enum, mixed $value): ?BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return filled($value) ? $enum::tryFrom((string) $value) : null;
    }

    private function getUserModel(): User
    {
        $user = $this->getUser();
        assert($user instanceof User);

        return $user->loadMissing(['country', 'manager', 'profile']);
    }
}
