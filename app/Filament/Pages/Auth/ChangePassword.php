<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangeOwnPassword;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * D-53: shown instead of every other page while the account still holds a temporary password (a new
 * account, or a password reset by an administrator). ForcePasswordChange sends the person here.
 */
final class ChangePassword extends Page
{
    /** @var array<string, mixed> */
    public ?array $data = [];

    protected string $view = 'filament.pages.change-password';

    protected static ?string $slug = 'change-password';

    protected static ?string $title = 'Choose your password';

    protected static bool $shouldRegisterNavigation = false;

    /** Only reachable while the signed-in account still holds a temporary password (default deny, NFR-03). */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->must_change_password;
    }

    public function mount(): void
    {
        $this->getSchema('form')?->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('You signed in with a temporary password')
                    ->description('Choose your own password to continue. Use at least 12 characters with upper and lower case letters and a number.')
                    ->schema([
                        TextInput::make('password')->label('New password')
                            ->password()->revealable()->required()
                            ->rule(Password::default())->showAllValidationMessages()
                            ->autocomplete('new-password')
                            ->same('passwordConfirmation'),
                        TextInput::make('passwordConfirmation')->label('Confirm new password')
                            ->password()->revealable()->required()
                            ->autocomplete('new-password')
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->getSchema('form')?->getState() ?? [];

        try {
            resolve(ChangeOwnPassword::class)->handle($this->user(), (string) $state['password']);
        } catch (ValidationException $validationException) {
            throw ValidationException::withMessages(['data.password' => $validationException->errors()['password'] ?? $validationException->getMessage()]);
        }

        // The password hash changed: refresh it in the session so AuthenticateSession keeps the user signed in.
        if (request()->hasSession()) {
            request()->session()->put('password_hash_'.filament()->getAuthGuard(), $this->user()->getAuthPassword());
        }

        Notification::make()->title('Password changed')->success()->send();

        $this->redirect(session()->pull('url.intended', filament()->getUrl()));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
