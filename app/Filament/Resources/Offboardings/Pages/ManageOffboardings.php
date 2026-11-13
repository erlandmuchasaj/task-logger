<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offboardings\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\CreateOffboarding;
use App\Domain\People\Data\OffboardingData;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Filament\Resources\Offboardings\OffboardingResource;
use App\Filament\Support\ActionErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
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
                    Select::make('user_id')->label('Employee')->required()->searchable()
                        ->options(fn (): array => User::query()->whereNull('anonymized_at')->whereNull('banned_at')->orderBy('name')->get()
                            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->getFilamentName()])->all()),
                    Select::make('status')->options([
                        OffboardingStatus::Pending->value => 'Pending',
                        OffboardingStatus::InProgress->value => 'In progress',
                        OffboardingStatus::Completed->value => 'Completed (deactivates the login now)',
                    ])->default(OffboardingStatus::Pending->value)->required()->native(false),
                    ...OffboardingResource::factFields(),
                ])->columns(2))
                ->action(function (array $data, Action $action): void {
                    try {
                        resolve(CreateOffboarding::class)->handle(
                            auth()->user(),
                            User::query()->whereKey($data['user_id'])->firstOrFail(),
                            new OffboardingData(
                                CarbonImmutable::parse((string) $data['end_date']), (bool) $data['equipment_returned'],
                                (bool) $data['resignation_letter_signed'], $data['description'] ?? null,
                                OffboardingStatus::from((string) $data['status']),
                            ),
                        );
                        Notification::make()->title('Offboarding started')->success()->send();
                    } catch (ValidationException $validationException) {
                        ActionErrors::notify($validationException);
                        $action->halt();
                    }
                }),
        ];
    }
}
