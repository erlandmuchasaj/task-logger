<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offboardings\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\CreateOffboarding;
use App\Domain\People\Data\OffboardingData;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Support\DirectReports;
use App\Filament\Resources\Offboardings\OffboardingResource;
use App\Filament\Support\ActionErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

final class ManageOffboardings extends ManageRecords
{
    protected static string $resource = OffboardingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label('Start offboarding')->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => (bool) auth()->user()?->can('create', Offboarding::class))
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('user_id')->label('Employee')->required()->searchable()->live()
                        ->options(fn (): array => User::query()->whereNull('anonymized_at')->whereNull('banned_at')->orderBy('name')->get()
                            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->getFilamentName()])->all()),
                    Select::make('new_manager_id')->label('New manager for their direct reports')->searchable()
                        ->visible(fn (Get $get): bool => $this->hasReports($get('user_id')))
                        ->required(fn (Get $get): bool => $this->hasReports($get('user_id')))
                        ->options(fn (Get $get): array => OffboardingResource::managerChoices(filled($get('user_id')) ? (int) $get('user_id') : null))
                        ->helperText('This person still manages a team. Everyone who reports to them moves to this manager (D-74).')
                        ->columnSpanFull(),
                    Select::make('status')->options([
                        OffboardingStatus::Pending->value => 'Pending',
                        OffboardingStatus::InProgress->value => 'In progress',
                        OffboardingStatus::Completed->value => 'Completed (deactivates the login now)',
                    ])->default(OffboardingStatus::Pending->value)->required()->native(false),
                    ...OffboardingResource::factFields(),
                ])->columns(2))
                ->action(function (array $data, Action $action): void {
                    try {
                        $employee = User::query()->whereKey($data['user_id'])->firstOrFail();
                        $newManagerId = filled($data['new_manager_id'] ?? null) ? (int) $data['new_manager_id'] : null;

                        // Accept the id only if it is among the choices this person was offered.
                        $newManager = $newManagerId !== null && array_key_exists($newManagerId, OffboardingResource::managerChoices((int) $employee->getKey()))
                            ? User::query()->whereKey($newManagerId)->first()
                            : null;

                        resolve(CreateOffboarding::class)->handle(
                            auth()->user(),
                            $employee,
                            new OffboardingData(
                                CarbonImmutable::parse((string) $data['end_date']), (bool) $data['equipment_returned'],
                                (bool) $data['resignation_letter_signed'], $data['description'] ?? null,
                                OffboardingStatus::from((string) $data['status']),
                            ),
                            $newManager,
                        );
                        Notification::make()->title('Offboarding started')->success()->send();
                    } catch (ValidationException $validationException) {
                        ActionErrors::notify($validationException);
                        $action->halt();
                    }
                }),
        ];
    }

    private function hasReports(mixed $userId): bool
    {
        $employee = filled($userId) ? User::query()->find((int) $userId) : null;

        return $employee instanceof User && DirectReports::activeOf($employee)->exists();
    }
}
